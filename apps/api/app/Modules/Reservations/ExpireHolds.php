<?php

namespace App\Modules\Reservations;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;

class ExpireHolds implements ShouldQueue
{
    public function handle(ReservationRecords $records): void
    {
        DB::table('holds')->where('status', 'active')->where('expires_at', '<=', now()->utc()->toDateTimeString())
            ->chunkById(200, function ($holds) use ($records): void {
                foreach ($holds as $hold) {
                    DB::transaction(function () use ($records, $hold): void {
                        $current = $records->lockHold($hold->organization_id, $hold->id);
                        $records->expire($hold->organization_id, $current);
                    });
                }
            });
    }
}
