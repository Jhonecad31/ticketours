<?php

namespace App\Modules\Commissions;

use App\Http\PaginatedJson;
use App\Modules\Audit\AuditLog;
use App\Modules\Inventory\TimeRange;
use App\Modules\Organizations\OrganizationAccess;
use App\Modules\Payments\SimulatedPayments;
use App\Modules\Reservations\Idempotency;
use Brick\Math\BigDecimal;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use stdClass;

class SettlementController
{
    public function __construct(private OrganizationAccess $access, private CommissionLedger $ledger, private AuditLog $audit, private Idempotency $idempotency, private SimulatedPayments $simulator) {}

    public function index(Request $request, string $org): JsonResponse
    {
        $this->access->authorize($request->user(), $org, 'settlements.read');

        return PaginatedJson::from($request, $this->visible($org), ['*'], $this->present(...));
    }

    public function items(Request $request, string $org, string $id): JsonResponse
    {
        $this->access->authorize($request->user(), $org, 'settlements.read');
        abort_unless($this->visible($org)->where('id', $id)->exists(), 404);
        $query = DB::table('commission_entries')->whereIn('id', DB::table('settlement_items')->where('settlement_id', $id)->select('commission_entry_id'));

        return PaginatedJson::from($request, $query, ['id', 'provider_id', 'agency_id', 'sale_id', 'type', 'amount', 'currency', 'recorded_at'], fn ($row) => [...(array) $row, 'recorded_at' => TimeRange::utc($row->recorded_at)]);
    }

    public function store(Request $request, string $org): JsonResponse
    {
        $this->access->authorize($request->user(), $org, 'settlements.manage');
        $data = $request->validate(['agency_id' => ['required', 'uuid'], 'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/D']]);
        $key = ['provider_id' => $org, 'agency_id' => strtolower($data['agency_id']), 'currency' => $data['currency']];

        $body = $this->idempotency->run($request, $org, 'settlement.create', $key, function () use ($request, $org, $key): array {
            $this->ledger->lockProvider($org);
            abort_unless(DB::table('agreements')->where(['provider_id' => $org, 'agency_id' => $key['agency_id']])->exists(), 404);
            abort_if(DB::table('settlements')->where($key)->where('status', '!=', 'paid')->exists(), 409, 'SETTLEMENT_OPEN');
            $entries = $this->unassigned($key)->get(['id', 'amount']);
            $total = $this->sum($entries);
            abort_unless($total->isPositive(), 409, 'SETTLEMENT_NOT_POSITIVE');

            $row = (object) [...$key, 'id' => (string) Str::uuid(), 'total' => (string) $total, 'status' => 'reconciled',
                'payment_reference' => null, 'created_at' => now()->utc()->toDateTimeString(), 'approved_at' => null, 'paid_at' => null];
            DB::table('settlements')->insert((array) $row);
            $this->assign($row, $entries);
            $this->record($request, $row, 'reconciled');

            return $this->present($row);
        });

        return response()->json($body, 201);
    }

    public function transition(Request $request, string $org, string $id): JsonResponse
    {
        $this->access->authorize($request->user(), $org, 'settlements.manage');
        $data = $request->validate(['status' => ['required', Rule::in(['reconciled', 'approved', 'paid'])]]);
        $id = strtolower($id);
        $next = $data['status'];

        return response()->json($this->idempotency->run($request, $org, 'settlement.transition', [$id, $next], function () use ($request, $org, $id, $next): array {
            $this->ledger->lockProvider($org);
            $query = DB::table('settlements')->where(['provider_id' => $org, 'id' => $id]);
            $row = $query->first();
            abort_if($row === null, 404);
            if ($row->status === 'paid' && $next === 'paid') {
                return $this->present($row);
            }
            abort_if($row->status === 'paid', 409, 'INVALID_STATE_TRANSITION');
            $key = ['provider_id' => $org, 'agency_id' => $row->agency_id, 'currency' => $row->currency];

            $update = ['status' => $next];
            if ($next === 'reconciled') {
                $entries = $this->unassigned($key)->get(['id', 'amount']);
                $this->assign($row, $entries);
                $update['total'] = (string) BigDecimal::of($row->total)->plus($this->sum($entries))->strippedOfTrailingZeros();
                $update['approved_at'] = null;
            } else {
                abort_unless($row->status === ($next === 'approved' ? 'reconciled' : 'approved'), 409, 'INVALID_STATE_TRANSITION');
                $alreadyIncluded = DB::table('settlement_items as si')->join('commission_entries as c', 'si.commission_entry_id', '=', 'c.id')->where('si.settlement_id', $id)->select('c.payment_id');
                abort_if($this->unassigned($key)->where('type', 'reversal')->whereIn('payment_id', $alreadyIncluded)->exists(), 409, 'SETTLEMENT_RECONCILIATION_REQUIRED');
                abort_unless(BigDecimal::of($row->total)->isPositive(), 409, 'SETTLEMENT_NOT_POSITIVE');
                if ($next === 'approved') {
                    $update['approved_at'] = now()->utc()->toDateTimeString();
                } else {
                    $update['paid_at'] = now()->utc()->toDateTimeString();
                    $update['payment_reference'] = $this->simulator->approve();
                }
            }

            $query->update($update);
            $row = (object) [...(array) $row, ...$update];
            $this->record($request, $row, $next);

            return $this->present($row);
        }));
    }

    private function visible(string $org): Builder
    {
        return DB::table('settlements')->where(fn ($q) => $q->where('provider_id', $org)->orWhere('agency_id', $org));
    }

    private function unassigned(array $key): Builder
    {
        return DB::table('commission_entries')->where($key)->whereNotIn('id', DB::table('settlement_items')->select('commission_entry_id'));
    }

    private function assign(stdClass $row, iterable $entries): void
    {
        foreach ($entries as $entry) {
            DB::table('settlement_items')->insert([
                'commission_entry_id' => $entry->id, 'provider_id' => $row->provider_id, 'agency_id' => $row->agency_id,
                'currency' => $row->currency, 'settlement_id' => $row->id,
            ]);
        }
    }

    private function sum(iterable $entries): BigDecimal
    {
        $total = BigDecimal::zero();
        foreach ($entries as $entry) {
            $total = $total->plus($entry->amount);
        }

        return $total->strippedOfTrailingZeros();
    }

    private function present(stdClass $row): array
    {
        $value = (array) $row;
        foreach (['created_at', 'approved_at', 'paid_at'] as $date) {
            $value[$date] = $row->$date === null ? null : TimeRange::utc($row->$date);
        }

        return $value;
    }

    private function record(Request $request, stdClass $row, string $status): void
    {
        foreach ([$row->provider_id, $row->agency_id] as $party) {
            $this->audit->record($request, $party, $request->user()->id, "settlement.$status", 'settlement', $row->id);
        }
    }
}
