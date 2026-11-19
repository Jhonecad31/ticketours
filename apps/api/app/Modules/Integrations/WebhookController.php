<?php

namespace App\Modules\Integrations;

use App\Modules\Audit\AuditLog;
use App\Modules\Organizations\OrganizationAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class WebhookController
{
    public function __construct(private AuditLog $audit) {}

    public function receive(Request $request, string $org): JsonResponse
    {
        return $this->deliver($request, $org, $request->getContent(), $request->header('X-Ticketours-Timestamp'), $request->header('X-Ticketours-Signature'));
    }

    public function simulate(Request $request, string $org, OrganizationAccess $access): JsonResponse
    {
        $access->authorize($request->user(), $org, 'integrations.simulate');
        $data = $request->validate(['id' => ['required', 'uuid'], 'reservation_id' => ['required', 'uuid']]);
        abort_unless(DB::table('reservations')->where(['organization_id' => $org, 'id' => $data['reservation_id']])->exists(), 404);
        $raw = json_encode(['id' => strtolower($data['id']), 'type' => 'voucher.issued', 'reservation_id' => strtolower($data['reservation_id'])], JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->timestamp;

        return $this->deliver($request, $org, $raw, $timestamp, $this->sign($org, $raw, $timestamp));
    }

    private function deliver(Request $request, string $org, string $raw, ?string $timestamp, ?string $signature): JsonResponse
    {
        $org = strtolower($org);
        abort_if(strlen($raw) > 4096, 422);
        abort_unless($timestamp !== null && preg_match('/^[0-9]{1,12}$/D', $timestamp)
            && abs(now()->timestamp - (int) $timestamp) <= 300
            && $signature !== null && hash_equals($this->sign($org, $raw, $timestamp), $signature), 401);
        abort_unless(DB::table('organizations')->where('id', $org)->exists(), 404);
        $payload = json_decode($raw, true);
        abort_unless(is_array($payload), 422);
        $data = Validator::make($payload, ['id' => ['required', 'uuid'], 'type' => ['required', 'in:voucher.issued'], 'reservation_id' => ['required', 'uuid']])->validate();
        $event = ['organization_id' => $org, 'external_id' => strtolower($data['id'])];
        $fingerprint = hash('sha256', $raw);

        $body = DB::transaction(function () use ($request, $org, $event, $data, $fingerprint): array {
            DB::table('webhook_events')->insertOrIgnore([
                ...$event, 'id' => (string) Str::uuid(), 'fingerprint' => $fingerprint, 'type' => $data['type'],
                'reservation_ref' => strtolower($data['reservation_id']), 'status' => 'pending', 'attempts' => 0,
            ]);
            $query = DB::table('webhook_events')->where($event);
            $row = $query->lockForUpdate()->first();
            abort_unless(hash_equals($row->fingerprint, $fingerprint), 409, 'WEBHOOK_EVENT_CONFLICT');
            $update = ['attempts' => $row->attempts + 1];
            $action = 'webhook.replayed';
            if ($row->status === 'pending') {
                $order = DB::table('orders')->where(['organization_id' => $org, 'reservation_id' => $row->reservation_ref, 'status' => 'completed'])->value('id');
                $processed = $order !== null && DB::table('vouchers')->where(['organization_id' => $org, 'order_id' => $order])->exists();
                $action = $processed ? 'webhook.processed' : 'webhook.pending';
                if ($processed) {
                    $update['status'] = 'processed';
                    $update['processed_at'] = now();
                }
            }
            $query->update($update);
            $this->audit->write($org, $request->user()?->id, $action, 'webhook_event', $row->id, $request->attributes->get('correlation_id'));

            return ['event_id' => $row->external_id, 'status' => $update['status'] ?? $row->status, 'attempts' => $update['attempts'], 'duplicate' => $row->attempts > 0];
        });

        return response()->json($body, $body['status'] === 'processed' ? 200 : 202);
    }

    private function sign(string $org, string $raw, string $timestamp): string
    {
        $key = config('app.key');
        abort_unless(is_string($key) && $key !== '', 503);
        $secret = hash_hmac('sha256', 'ticketours.simulator.'.$org, $key, true);

        return 'v1='.hash_hmac('sha256', $timestamp.'.'.$raw, $secret);
    }
}
