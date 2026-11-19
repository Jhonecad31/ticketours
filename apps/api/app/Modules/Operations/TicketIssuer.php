<?php

namespace App\Modules\Operations;

use App\Modules\Audit\AuditLog;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

class TicketIssuer
{
    public function __construct(private AuditLog $audit) {}

    public function issue(string $org, string $reservation, int $actor, string $correlation): stdClass
    {
        $key = ['organization_id' => $org, 'reservation_id' => $reservation];
        $existing = DB::table('ticket_batches')->where($key)->first();
        if ($existing !== null) {
            return $existing;
        }
        $selection = json_decode(DB::table('reservation_items')->where($key)->value('selection'), true, flags: JSON_THROW_ON_ERROR);
        $row = [
            ...$key, 'id' => (string) Str::uuid(), 'quantity' => (int) ($selection['quantity'] ?? 1),
            'encrypted_secret' => Crypt::encryptString(bin2hex(random_bytes(32))),
        ];
        DB::table('ticket_batches')->insert($row);
        $this->audit->write($org, $actor, 'ticket_batch.issued', 'ticket_batch', $row['id'], $correlation);

        return (object) $row;
    }

    public function code(stdClass $batch, int $ticket, string $secret): string
    {
        $value = $batch->id.'.'.$ticket;

        return $value.'.'.hash_hmac('sha256', $value, $secret);
    }

    public function used(string $org, string $batch): int
    {
        return DB::table('ticket_checkins')->where(['organization_id' => $org, 'batch_id' => $batch])->count();
    }

    public function status(int $used, int $quantity): string
    {
        return match (true) {
            $used === 0 => 'pending',
            $used === $quantity => 'checkin_complete',
            default => 'checkin_partial',
        };
    }

    public function assertUnused(string $org, string $reservation): void
    {
        $batch = DB::table('ticket_batches')->where(['organization_id' => $org, 'reservation_id' => $reservation])->value('id');
        abort_if($batch !== null && $this->used($org, $batch) > 0, 409, 'TICKET_ALREADY_USED');
    }
}
