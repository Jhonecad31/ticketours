<?php

namespace App\Modules\Reservations;

use App\Modules\Audit\AuditLog;
use App\Modules\Inventory\InventoryClaims;
use App\Modules\Inventory\TimeRange;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

class ReservationRecords
{
    public function __construct(private InventoryClaims $inventory, private AuditLog $audit) {}

    public function rows(string $table, string $org): Builder
    {
        return DB::table($table)->where('organization_id', $org);
    }

    public function get(string $table, string $org, string $id): stdClass
    {
        $row = $this->rows($table, $org)->where('id', $id)->first();
        abort_if($row === null, 404);

        return $row;
    }

    public function lockHold(string $org, string $id): stdClass
    {
        $hold = $this->get('holds', $org, $id);
        $this->inventory->target($org, $hold->session_id, $hold->resource_id, lock: true);

        return $this->rows('holds', $org)->where('id', $id)->lockForUpdate()->first();
    }

    public function expireTarget(string $org, ?string $session, ?string $resource, ?Request $request = null): void
    {
        $holds = $this->inventory->forTarget($org, $session, $resource)->where('status', 'active')
            ->where('expires_at', '<=', now()->utc()->toDateTimeString())->orderBy('id')->lockForUpdate()->get();

        foreach ($holds as $hold) {
            $this->expire($org, $hold, $request);
        }
    }

    public function expire(string $org, stdClass $hold, ?Request $request = null): void
    {
        if ($hold->status !== 'active' || Carbon::parse($hold->expires_at, 'UTC')->isFuture()) {
            return;
        }

        $correlation = $request?->attributes->get('correlation_id') ?? (string) Str::uuid();
        $this->setHoldStatus($org, $hold, 'expired');
        $this->audit->write($org, null, 'hold.expired', 'hold', $hold->id, $correlation);
        $reservation = $this->rows('reservations', $org)->where(['hold_id' => $hold->id, 'status' => 'held'])->first();
        if ($reservation !== null) {
            $this->transition($org, $reservation, 'expired', null, $correlation, 'hold_expired');
        }
    }

    public function setHoldStatus(string $org, stdClass $hold, string $status): void
    {
        $this->rows('holds', $org)->where('id', $hold->id)->update(['status' => $status]);
        $hold->status = $status;
    }

    public function transition(string $org, stdClass $record, string $status, ?int $actor, string $correlation, ?string $reason = null): void
    {
        $this->rows('reservations', $org)->where('id', $record->id)->update(['status' => $status]);
        $record->status = $status;
        DB::table('reservation_history')->insert([
            'id' => (string) Str::uuid(), 'organization_id' => $org, 'reservation_id' => $record->id,
            'actor_id' => $actor, 'status' => $status, 'correlation_id' => $correlation, 'reason' => $reason, 'occurred_at' => now(),
        ]);
        $this->audit->write($org, $actor, "reservation.$status", 'reservation', $record->id, $correlation);
    }

    public function quoteResponse(stdClass $quote): array
    {
        return [
            'id' => $quote->id, 'variant_id' => $quote->variant_id,
            'selection' => json_decode($quote->selection, true, flags: JSON_THROW_ON_ERROR),
            'total' => $quote->total, 'currency' => $quote->currency, 'rate_version_id' => $quote->rate_version_id,
            'policy_version_id' => $quote->policy_version_id, 'expires_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime(TimeRange::utc($quote->expires_at))),
        ];
    }

    public function holdResponse(string $org, stdClass $hold): array
    {
        $quote = $this->quoteResponse($this->get('quotes', $org, $hold->quote_id));

        return [
            'id' => $hold->id, 'quote_id' => $hold->quote_id, 'status' => $hold->status, 'selection' => $quote['selection'],
            'expires_at' => TimeRange::utc($hold->expires_at),
        ];
    }

    public function reservationResponse(string $org, stdClass $record): array
    {
        $item = $this->rows('reservation_items', $org)->where('reservation_id', $record->id)->first([
            'id', 'variant_id', 'selection', 'total', 'currency', 'rate_version_id', 'policy_version_id',
        ]);
        $item->selection = json_decode($item->selection, true, flags: JSON_THROW_ON_ERROR);

        return ['id' => $record->id, 'hold_id' => $record->hold_id, 'customer_id' => $record->customer_id, 'status' => $record->status, 'items' => [(array) $item]];
    }
}
