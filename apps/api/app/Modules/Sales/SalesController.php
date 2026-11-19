<?php

namespace App\Modules\Sales;

use App\Modules\Inventory\TimeRange;
use App\Modules\Organizations\OrganizationAccess;
use App\Modules\Reservations\Idempotency;
use App\Modules\Reservations\ReservationRecords;
use Brick\Math\BigDecimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SalesController
{
    public function __construct(
        private Sales $sales,
        private ReservationRecords $records,
        private OrganizationAccess $access,
        private Idempotency $idempotency,
    ) {}

    public function order(Request $request, string $org, string $id): JsonResponse
    {
        $this->access->authorize($request->user(), $org, 'sales.read');

        return response()->json(DB::transaction(function () use ($request, $org, $id): array {
            $reservation = $this->records->get('reservations', $org, $id);
            $hold = $this->records->lockHold($org, $reservation->hold_id);
            $this->records->expire($org, $hold, $request);

            return $this->sales->summary($org, $this->sales->order($org, $id));
        }));
    }

    public function pay(Request $request, string $org, string $id): JsonResponse
    {
        $this->access->authorize($request->user(), $org, 'sales.pay');
        $data = $request->validate(['amount' => ['required', 'string', 'regex:/^(?:0|[1-9][0-9]{0,27})(?:\.[0-9]{1,13})?$/D']]);
        $amount = (string) BigDecimal::of($data['amount'])->strippedOfTrailingZeros();
        $id = strtolower($id);
        $result = $this->idempotency->run($request, $org, 'payment.simulate', [$id, $amount], function () use ($request, $org, $id, $amount): array {
            $reference = $this->records->get('reservations', $org, $id);
            $hold = $this->records->lockHold($org, $reference->hold_id);
            $reservation = $this->records->get('reservations', $org, $id);
            abort_if($reservation->status === 'expired' || ($reservation->status === 'held' && ! Carbon::parse($hold->expires_at, 'UTC')->isFuture()), 409, 'HOLD_EXPIRED');
            abort_unless(in_array($reservation->status, ['held', 'confirmed'], true), 409, 'INVALID_STATE_TRANSITION');

            return $this->sales->capture($org, $this->sales->order($org, $id), $amount, $request->user()->id, $request->attributes->get('correlation_id'));
        });

        return response()->json($result, 201);
    }

    public function voucher(Request $request, string $org, string $id): JsonResponse
    {
        $this->access->authorize($request->user(), $org, 'sales.read');

        return response()->json(DB::transaction(function () use ($org, $id): array {
            $record = $this->records->get('reservations', $org, $id);
            $hold = $this->records->lockHold($org, $record->hold_id);
            $order = $this->sales->order($org, $id);
            abort_unless($order->status === 'completed', 404);
            $voucher = DB::table('vouchers')->where(['organization_id' => $org, 'order_id' => $order->id])->first();
            abort_if($voucher === null, 404);

            return [
                'id' => $voucher->id, 'reservation_id' => $record->id,
                'organization' => DB::table('organizations')->where('id', $org)->value('name'),
                'customer' => $this->records->get('customers', $org, $record->customer_id)->name,
                'start_at' => TimeRange::utc($hold->start_at), 'end_at' => TimeRange::utc($hold->end_at),
                'time_zone' => DB::table('organizations')->where('id', $org)->value('time_zone'),
                'issued_at' => TimeRange::utc($voucher->issued_at),
                'order' => $this->sales->summary($org, $order),
            ];
        }));
    }
}
