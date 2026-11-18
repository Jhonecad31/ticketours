<?php

namespace App\Modules\Reservations;

use App\Modules\Organizations\OrganizationAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReservationController
{
    public function __construct(private OrganizationAccess $access, private ReservationRecords $records, private Idempotency $idempotency) {}

    public function store(Request $request, string $org): JsonResponse
    {
        $this->access->authorize($request->user(), $org, 'reservations.create');
        $data = $request->validate([
            'hold_id' => ['required', 'uuid'], 'customer_id' => ['required', 'uuid'],
            'participants' => ['sometimes', 'array', 'list', 'max:100'],
            'participants.*' => ['array:name'], 'participants.*.name' => ['required', 'string', 'max:200'],
        ]);
        $holdId = strtolower($data['hold_id']);
        $customerId = strtolower($data['customer_id']);
        $participants = $data['participants'] ?? [];
        $body = $this->idempotency->run($request, $org, 'reservation.create', [$holdId, $customerId, $participants], function () use ($request, $org, $holdId, $customerId, $participants): array {
            $hold = $this->records->lockHold($org, $holdId);
            $this->requireActive($hold);
            $this->records->get('customers', $org, $customerId);
            abort_if($this->records->rows('reservations', $org)->where('hold_id', $holdId)->exists(), 409, 'INVALID_STATE_TRANSITION');

            $quote = $this->records->get('quotes', $org, $hold->quote_id);
            $policy = $this->records->get('product_policy_versions', $org, $quote->policy_version_id);
            $reservation = ['id' => (string) Str::uuid(), 'hold_id' => $holdId, 'customer_id' => $customerId, 'status' => 'held'];
            DB::table('reservations')->insert(['organization_id' => $org, ...$reservation]);
            DB::table('reservation_items')->insert([
                'id' => (string) Str::uuid(), 'organization_id' => $org, 'reservation_id' => $reservation['id'],
                'variant_id' => $quote->variant_id, 'selection' => $quote->selection,
                'total' => $quote->total, 'currency' => $quote->currency, 'rate_version_id' => $quote->rate_version_id,
                'policy_version_id' => $quote->policy_version_id, 'policy_terms' => $policy->terms,
                'participants' => json_encode($participants, JSON_THROW_ON_ERROR),
            ]);
            $record = (object) $reservation;
            $this->records->transition($org, $record, 'held', $request->user()->id, $request->attributes->get('correlation_id'));

            return $this->records->reservationResponse($org, $record);
        });

        return response()->json($body, 201);
    }

    public function show(Request $request, string $org, string $id): JsonResponse
    {
        $this->access->authorize($request->user(), $org, 'reservations.read');

        return response()->json(DB::transaction(function () use ($request, $org, $id): array {
            $record = $this->records->get('reservations', $org, $id);
            if ($record->status === 'held') {
                $hold = $this->records->lockHold($org, $record->hold_id);
                $this->records->expire($org, $hold, $request);
                $record = $this->records->get('reservations', $org, $id);
            }

            return $this->records->reservationResponse($org, $record);
        }));
    }

    public function confirm(Request $request, string $org, string $id): JsonResponse
    {
        $this->access->authorize($request->user(), $org, 'reservations.confirm');
        $id = strtolower($id);
        $body = $this->idempotency->run($request, $org, 'reservation.confirm', [$id], function () use ($request, $org, $id): array {
            [$record, $hold] = $this->lock($org, $id);
            abort_if($record->status === 'expired', 409, 'HOLD_EXPIRED');
            abort_unless($record->status === 'held', 409, 'INVALID_STATE_TRANSITION');
            $this->requireActive($hold);
            $this->records->setHoldStatus($org, $hold, 'committed');
            $this->records->transition($org, $record, 'confirmed', $request->user()->id, $request->attributes->get('correlation_id'));

            return $this->records->reservationResponse($org, $record);
        });

        return response()->json($body);
    }

    public function cancel(Request $request, string $org, string $id): JsonResponse
    {
        $this->access->authorize($request->user(), $org, 'reservations.cancel');
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $id = strtolower($id);
        $body = $this->idempotency->run($request, $org, 'reservation.cancel', [$id, $data['reason']], function () use ($request, $org, $id, $data): array {
            [$record, $hold] = $this->lock($org, $id);
            abort_unless(in_array($record->status, ['held', 'confirmed'], true), 409, 'INVALID_STATE_TRANSITION');
            abort_unless(Carbon::parse($hold->start_at, 'UTC')->isFuture(), 409, 'CANCELLATION_CLOSED');
            if ($record->status === 'held') {
                $this->requireActive($hold);
            }
            $this->records->setHoldStatus($org, $hold, 'released');
            $this->records->transition($org, $record, 'cancelled', $request->user()->id, $request->attributes->get('correlation_id'), $data['reason']);

            return $this->records->reservationResponse($org, $record);
        });

        return response()->json($body);
    }

    private function lock(string $org, string $id): array
    {
        $record = $this->records->get('reservations', $org, $id);
        $hold = $this->records->lockHold($org, $record->hold_id);

        return [$this->records->get('reservations', $org, $id), $hold];
    }

    private function requireActive(object $hold): void
    {
        abort_if($hold->status === 'expired' || ($hold->status === 'active' && ! Carbon::parse($hold->expires_at, 'UTC')->isFuture()), 409, 'HOLD_EXPIRED');
        abort_unless($hold->status === 'active', 409, 'INVALID_STATE_TRANSITION');
    }
}
