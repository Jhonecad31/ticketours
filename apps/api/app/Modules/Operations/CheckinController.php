<?php

namespace App\Modules\Operations;

use App\Modules\Audit\AuditLog;
use App\Modules\Inventory\TimeRange;
use App\Modules\Organizations\OrganizationAccess;
use App\Modules\Reservations\Idempotency;
use App\Modules\Reservations\ReservationRecords;
use App\Modules\Sales\Sales;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CheckinController
{
    public function __construct(
        private OrganizationAccess $access, private ReservationRecords $records, private TicketIssuer $tickets,
        private AuditLog $audit, private Idempotency $idempotency,
    ) {}

    public function tickets(Request $request, string $org, string $id, Sales $sales): JsonResponse
    {
        $this->access->authorize($request->user(), $org, 'sales.read');
        $data = $request->validate(['from' => ['sometimes', 'integer', 'between:1,2147483647']]);

        return response()->json(DB::transaction(function () use ($request, $org, $id, $sales, $data): array {
            $reservation = $this->records->get('reservations', $org, $id);
            $this->records->lockHold($org, $reservation->hold_id);
            $reservation = $this->records->get('reservations', $org, $id);
            $order = $sales->order($org, $id);
            abort_unless($reservation->status === 'confirmed' && DB::table('vouchers')->where(['organization_id' => $org, 'order_id' => $order->id])->exists(), 404);
            $batch = $this->tickets->issue($org, $id, $request->user()->id, $request->attributes->get('correlation_id'));
            $from = (int) ($data['from'] ?? 1);
            $end = min($from + 49, $batch->quantity);
            $secret = Crypt::decryptString($batch->encrypted_secret);
            $used = DB::table('ticket_checkins')->where(['organization_id' => $org, 'batch_id' => $batch->id])
                ->whereBetween('ticket_number', [$from, $end])->pluck('ticket_number')->map(fn ($n) => (int) $n)->flip();
            $items = [];
            for ($n = $from; $n <= $end; $n++) {
                $items[] = ['number' => $n, 'code' => $this->tickets->code($batch, $n, $secret), 'used' => $used->has($n)];
            }

            return [
                'items' => $items, 'next_from' => $end < $batch->quantity ? $end + 1 : null, 'quantity' => (int) $batch->quantity,
                'operation_status' => $this->tickets->status($this->tickets->used($org, $batch->id), $batch->quantity),
            ];
        }));
    }

    public function scan(Request $request, string $org): JsonResponse
    {
        $this->access->authorize($request->user(), $org, 'operations.checkin');
        $data = $request->validate(['code' => ['required', 'string', 'regex:/^[a-f0-9-]{36}\.[1-9][0-9]{0,9}\.[a-f0-9]{64}$/D']]);
        [$batchId, $value] = explode('.', $data['code']);
        abort_unless(Str::isUuid($batchId), 404);
        $number = (int) $value;

        return response()->json($this->idempotency->run($request, $org, 'ticket.checkin', [hash('sha256', $data['code'])], function () use ($request, $org, $data, $batchId, $number): array {
            $batch = DB::table('ticket_batches')->where(['organization_id' => $org, 'id' => $batchId])->first();
            abort_unless($batch !== null && $number <= (int) $batch->quantity, 404);
            abort_unless(hash_equals($this->tickets->code($batch, $number, Crypt::decryptString($batch->encrypted_secret)), $data['code']), 404);
            $reservation = $this->records->get('reservations', $org, $batch->reservation_id);
            $hold = $this->records->lockHold($org, $reservation->hold_id);
            $reservation = $this->records->get('reservations', $org, $batch->reservation_id);
            abort_unless($reservation->status === 'confirmed', 409, 'TICKET_INVALID');
            $key = ['organization_id' => $org, 'batch_id' => $batchId, 'ticket_number' => $number];
            abort_if(DB::table('ticket_checkins')->where($key)->exists(), 409, 'TICKET_ALREADY_USED');
            $now = now();
            abort_if($now->lt(Carbon::parse($hold->start_at, 'UTC')->subMinutes(30)) || $now->gte(Carbon::parse($hold->end_at, 'UTC')), 409, 'CHECKIN_WINDOW_CLOSED');
            $id = (string) Str::uuid();
            DB::table('ticket_checkins')->insert([...$key, 'id' => $id, 'actor_id' => $request->user()->id, 'recorded_at' => $now]);
            $this->audit->record($request, $org, $request->user()->id, 'ticket.checked_in', 'ticket_checkin', $id);

            return [
                'id' => $id, 'reservation_id' => $batch->reservation_id, 'ticket_number' => $number,
                'recorded_at' => TimeRange::utc($now->toISOString()), 'operation_status' => $this->tickets->status($this->tickets->used($org, $batchId), $batch->quantity),
            ];
        }), 201);
    }

    public function validators(Request $request, string $org): JsonResponse
    {
        $this->access->authorize($request->user(), $org, 'operations.manage');
        $ids = DB::table('checkin_validators')->where('organization_id', $org)->orderBy('user_id')->pluck('user_id')->map(fn ($id) => (string) $id);

        return response()->json(['items' => $ids]);
    }

    public function grant(Request $request, string $org): JsonResponse
    {
        $this->access->authorize($request->user(), $org, 'operations.manage');
        $data = $request->validate(['user_id' => ['required', 'integer', 'min:1'], 'enabled' => ['required', 'boolean']]);
        $key = ['organization_id' => $org, 'user_id' => (int) $data['user_id']];
        DB::transaction(function () use ($request, $org, $data, $key): void {
            $member = DB::table('memberships')->where([...$key, 'active' => true])->lockForUpdate()->first();
            abort_if($member === null, 404);
            $changed = $data['enabled'] ? DB::table('checkin_validators')->insertOrIgnore($key) : DB::table('checkin_validators')->where($key)->delete();
            if ($changed) {
                $this->audit->record($request, $org, $request->user()->id, $data['enabled'] ? 'validator.granted' : 'validator.revoked', 'membership', $member->id);
            }
        });

        return response()->json(['user_id' => (string) $data['user_id'], 'enabled' => (bool) $data['enabled']]);
    }
}
