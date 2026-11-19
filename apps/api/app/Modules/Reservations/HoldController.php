<?php

namespace App\Modules\Reservations;

use App\Modules\Audit\AuditLog;
use App\Modules\Catalog\Catalog;
use App\Modules\Distribution\Distribution;
use App\Modules\Inventory\InventoryClaims;
use App\Modules\Organizations\OrganizationAccess;
use Brick\Math\BigDecimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class HoldController
{
    public function __construct(
        private OrganizationAccess $access,
        private Catalog $catalog,
        private InventoryClaims $inventory,
        private ReservationRecords $records,
        private Idempotency $idempotency,
        private AuditLog $audit,
        private Distribution $market,
    ) {}

    public function quote(Request $request, string $org, ?array $distribution = null): JsonResponse
    {
        if ($distribution === null) {
            $this->access->authorize($request->user(), $org, 'reservations.quote');
        } else {
            $request->merge(['variant_id' => $distribution['variant_id']]);
        }
        $data = $request->validate(['variant_id' => ['required', 'uuid'], 'selection' => ['required', 'array']]);
        $selection = Selection::normalize($data['selection']);

        $result = DB::transaction(function () use ($request, $org, $data, $selection, $distribution): array {
            if ($distribution !== null) {
                $this->market->lock($distribution);
            }
            $variant = $this->catalog->variant($org, $data['variant_id']);
            $product = $this->catalog->product($org, $variant->product_id, lock: true);
            abort_unless($product->status === 'published', 404);
            if ($distribution !== null && isset($selection['resource_id'])) {
                abort_unless(DB::table('resource_windows')->where(['organization_id' => $org, 'variant_id' => $variant->id, 'resource_id' => $selection['resource_id']])->exists(), 404);
            }
            $period = $this->inventory->selection($org, $variant, $selection);
            $rate = $this->records->rows('base_rate_versions', $org)->where('variant_id', $variant->id)
                ->when($distribution !== null, fn ($q) => $q->where('id', $distribution['rate_version_id']))
                ->orderByDesc('version')->first();
            $policy = $this->records->rows('product_policy_versions', $org)->where('product_id', $product->id)->orderByDesc('version')->first();
            abort_if($rate === null || $policy === null, 409, 'PRODUCT_NOT_READY');
            $row = [
                'id' => (string) Str::uuid(), 'variant_id' => $variant->id,
                'selection' => json_encode($selection, JSON_THROW_ON_ERROR),
                'total' => (string) BigDecimal::of($rate->amount)->multipliedBy($period['quantity']), 'currency' => $rate->currency,
                'rate_version_id' => $rate->id, 'policy_version_id' => $policy->id,
                'distribution_offer_id' => $distribution['id'] ?? null,
                'expires_at' => $this->expiry($org, $period['start_at']),
            ];
            DB::table('quotes')->insert(['organization_id' => $org, ...$row]);
            $this->audit->record($request, $org, $request->user()->id, 'quote.created', 'quote', $row['id']);

            return $this->records->quoteResponse((object) $row);
        });

        return response()->json($result, 201);
    }

    public function store(Request $request, string $org, ?array $distribution = null): JsonResponse
    {
        if ($distribution === null) {
            $this->access->authorize($request->user(), $org, 'reservations.hold');
        }
        $data = $request->validate(['quote_id' => ['required', 'uuid'], 'selection' => ['required', 'array']]);
        $selection = Selection::normalize($data['selection']);
        $quoteId = strtolower($data['quote_id']);

        $result = $this->idempotency->run($request, $distribution['agency_id'] ?? $org, $distribution ? 'agency.hold.create' : 'hold.create', [$quoteId, $selection, ...($distribution ? [$distribution['id']] : [])], function () use ($request, $org, $quoteId, $selection, $distribution): array {
            if ($distribution !== null) {
                $this->market->lock($distribution);
            }
            $quote = $this->records->get('quotes', $org, $quoteId);
            abort_unless($quote->distribution_offer_id === ($distribution['id'] ?? null), 404);
            abort_unless($this->records->quoteResponse($quote)['selection'] === $selection, 409, 'QUOTE_SELECTION_MISMATCH');
            $variant = $this->catalog->variant($org, $quote->variant_id);
            $product = $this->catalog->product($org, $variant->product_id, lock: true);
            abort_unless($product->status === 'published', 404);

            $session = $selection['session_id'] ?? null;
            $resource = $selection['resource_id'] ?? null;
            $this->inventory->target($org, $session, $resource, lock: true);
            abort_unless(Carbon::parse($quote->expires_at, 'UTC')->isFuture(), 409, 'QUOTE_EXPIRED');
            abort_if($this->records->rows('holds', $org)->where('quote_id', $quoteId)->exists(), 409, 'QUOTE_ALREADY_USED');
            $this->records->expireTarget($org, $session, $resource, $request);
            $period = $this->inventory->selection($org, $variant, $selection);
            $row = [
                'id' => (string) Str::uuid(), 'quote_id' => $quoteId, ...$period,
                'status' => 'active', 'expires_at' => $this->expiry($org, $period['start_at']),
            ];
            DB::table('holds')->insert(['organization_id' => $org, ...$row]);
            $this->audit->record($request, $org, $request->user()->id, 'hold.created', 'hold', $row['id']);

            return $this->records->holdResponse($org, (object) $row);
        });

        return response()->json($result, 201);
    }

    public function release(Request $request, string $org, string $id, ?array $distribution = null): JsonResponse
    {
        if ($distribution === null) {
            $this->access->authorize($request->user(), $org, 'reservations.hold');
        }
        $id = strtolower($id);
        $result = $this->idempotency->run($request, $distribution['agency_id'] ?? $org, $distribution ? 'agency.hold.release' : 'hold.release', [$id, ...($distribution ? [$distribution['id']] : [])], function () use ($request, $org, $id, $distribution): array {
            if ($distribution !== null) {
                $this->market->lock($distribution);
            }
            $hold = $this->records->lockHold($org, $id);
            $quote = $this->records->get('quotes', $org, $hold->quote_id);
            abort_if($distribution !== null && $quote->distribution_offer_id !== $distribution['id'], 404);
            $this->records->expire($org, $hold, $request);
            abort_if($hold->status === 'committed', 409, 'INVALID_STATE_TRANSITION');
            if ($hold->status === 'active') {
                abort_if($this->records->rows('reservations', $org)->where('hold_id', $id)->exists(), 409, 'INVALID_STATE_TRANSITION');
                $this->records->setHoldStatus($org, $hold, 'released');
                $this->audit->record($request, $org, $request->user()->id, 'hold.released', 'hold', $id);
            }

            return $this->records->holdResponse($org, $hold);
        });

        return response()->json($result);
    }

    private function expiry(string $org, string $serviceStart): string
    {
        $minutes = (int) DB::table('organizations')->where('id', $org)->value('hold_minutes');

        return min(now()->utc()->addMinutes($minutes)->toDateTimeString(), $serviceStart);
    }
}
