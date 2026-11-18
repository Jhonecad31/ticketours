<?php

namespace App\Modules\Inventory;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;

class InventoryClaims
{
    public function target(string $org, ?string $session, ?string $resource, bool $lock = false): stdClass
    {
        $query = DB::table($session === null ? 'resources' : 'inventory_sessions')
            ->where(['organization_id' => $org, 'id' => $session ?? $resource]);
        $row = ($lock ? $query->lockForUpdate() : $query)->first();
        abort_if($row === null, 404);

        return $row;
    }

    public function active(string $org): Builder
    {
        return DB::table('holds')->where('organization_id', $org)->where(function (Builder $query): void {
            $query->where('status', 'committed')->orWhere(function (Builder $query): void {
                $query->where('status', 'active')->where('expires_at', '>', now()->utc()->toDateTimeString());
            });
        });
    }

    public function forTarget(string $org, ?string $session, ?string $resource): Builder
    {
        return DB::table('holds')->where(['organization_id' => $org, $session === null ? 'resource_id' : 'session_id' => $session ?? $resource]);
    }

    public function assertResourceFree(string $org, string $resource, string $start, string $end): void
    {
        abort_if($this->active($org)->where('resource_id', $resource)->where('start_at', '<', $end)->where('end_at', '>', $start)->exists(), 409, 'INVENTORY_UNAVAILABLE');
    }

    public function selection(string $org, stdClass $variant, array $selection): array
    {
        $session = $selection['session_id'] ?? null;
        $resource = $selection['resource_id'] ?? null;
        abort_unless(($variant->modality === 'session') === ($session !== null), 409, 'INVALID_INVENTORY_MODALITY');
        $target = $this->target($org, $session, $resource);

        if ($session !== null) {
            abort_unless($target->variant_id === $variant->id, 404);
            $start = $this->dbTime($target->start_at);
            $end = $this->dbTime($target->end_at);
            $quantity = $selection['quantity'];
            $reserved = $this->active($org)->where('session_id', $session)->sum('quantity');
            abort_if($quantity + $reserved > $target->capacity, 409, 'INVENTORY_UNAVAILABLE');
        } else {
            $start = $this->dbTime($selection['start_at']);
            $end = $this->dbTime($selection['end_at']);
            $quantity = 1;
            $this->assertWindow($org, $variant->id, $resource, $start, $end);
            $this->assertResourceFree($org, $resource, $start, $end);
        }

        abort_if($start <= now()->utc()->toDateTimeString(), 409, 'INVENTORY_UNAVAILABLE');

        return ['session_id' => $session, 'resource_id' => $resource, 'start_at' => $start, 'end_at' => $end, 'quantity' => $quantity];
    }

    private function assertWindow(string $org, string $variant, string $resource, string $start, string $end): void
    {
        $windows = DB::table('resource_windows')->where(['organization_id' => $org, 'variant_id' => $variant, 'resource_id' => $resource])
            ->where('start_at', '<', $end)->where('end_at', '>', $start)->orderBy('start_at')->get();

        $covered = $start;
        foreach ($windows as $window) {
            if ($this->dbTime($window->start_at) > $covered) {
                break;
            }
            $covered = max($covered, $this->dbTime($window->end_at));
            if ($covered >= $end) {
                break;
            }
        }

        $blocked = DB::table('resource_blocks')->where(['organization_id' => $org, 'resource_id' => $resource])
            ->where('start_at', '<', $end)->where('end_at', '>', $start)->exists();
        abort_if($covered < $end || $blocked, 409, 'INVENTORY_UNAVAILABLE');
    }

    private function dbTime(string $time): string
    {
        return Carbon::parse($time, 'UTC')->utc()->toDateTimeString();
    }
}
