<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Organizations\Membership;
use App\Modules\Organizations\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PhaseSixTest extends TestCase
{
    use RefreshDatabase;

    public function test_signed_ticket_single_use_and_scoped_validator(): void
    {
        [$scope, $org, $id, $owner] = $this->reservation();
        $route = "$scope/reservations/$id";
        $this->postWithKey("$route/confirm", [], 'confirm')->assertOk();
        $tickets = $this->getJson("$route/tickets")->assertOk()->assertJsonPath('quantity', 2)->json('items');
        $this->assertNotSame($tickets[0]['code'], $tickets[1]['code']);
        $input = ['code' => $tickets[0]['code']];

        $this->travelTo(Carbon::parse('2035-07-12T13:29:59Z'));
        $this->postWithKey("$scope/operations/check-ins", $input, 'scan')->assertConflict()->assertJsonPath('code', 'CHECKIN_WINDOW_CLOSED');
        $this->travelTo(Carbon::parse('2035-07-12T13:30:00Z'));

        $validator = User::factory()->create();
        app(Membership::class)->create($org, $validator->id, Role::BookingAgent);
        $this->actingAs($validator)->postWithKey("$scope/operations/check-ins", $input, 'scan')->assertForbidden();
        $this->actingAs($owner)->patchJson("$scope/operations/validators", ['user_id' => $validator->id, 'enabled' => true])->assertOk();
        $this->getJson("$scope/operations/validators")->assertJsonPath('items.0', (string) $validator->id);

        $this->actingAs($validator);
        $first = $this->postWithKey("$scope/operations/check-ins", $input, 'scan')->assertCreated()->assertJsonPath('operation_status', 'checkin_partial')->json('id');
        $this->postWithKey("$scope/operations/check-ins", $input, 'scan')->assertCreated()->assertJsonPath('id', $first);
        $this->postWithKey("$scope/operations/check-ins", $input, 'other-key')->assertConflict()->assertJsonPath('code', 'TICKET_ALREADY_USED');
        $this->postWithKey("$scope/operations/check-ins", ['code' => $tickets[1]['code']], 'remaining')->assertCreated()
            ->assertJsonPath('operation_status', 'checkin_complete');
        $this->assertDatabaseCount('ticket_checkins', 2);
        $this->actingAs($owner)->postWithKey("$route/cancel", ['reason' => 'Prueba'], 'cancel')->assertConflict()->assertJsonPath('code', 'TICKET_ALREADY_USED');
        $this->getJson("$route")->assertJsonPath('status', 'confirmed');
        $this->getJson("$scope/reports/summary?from=2035-07-12T13:00:00Z&to=2035-07-13T00:00:00Z")->assertOk()->assertJsonPath('checkins', 2);
    }

    public function test_webhook_signature_retries_and_deduplicates_processing(): void
    {
        [$scope, $org, $id] = $this->reservation();
        $event = ['id' => (string) Str::uuid(), 'type' => 'voucher.issued', 'reservation_id' => $id];
        $raw = json_encode($event, JSON_THROW_ON_ERROR);
        $path = "/api/v1/integrations/$org/webhook";
        $this->call('POST', $path, [], [], [], ['CONTENT_TYPE' => 'application/json'], $raw)->assertUnauthorized();
        $this->signed($org, $raw, now()->timestamp - 301)->assertUnauthorized();
        $this->signed($org, $raw)->assertAccepted()->assertJsonPath('attempts', 1);
        $this->postWithKey("$scope/reservations/$id/confirm", [], 'confirm')->assertOk();
        $this->signed($org, $raw)->assertOk()->assertJsonPath('status', 'processed')->assertJsonPath('attempts', 2);
        $this->signed($org, $raw)->assertOk()->assertJsonPath('duplicate', true)->assertJsonPath('attempts', 3);
        $this->signed($org, json_encode([...$event, 'reservation_id' => (string) Str::uuid()], JSON_THROW_ON_ERROR))->assertConflict()
            ->assertJsonPath('code', 'WEBHOOK_EVENT_CONFLICT');
        $this->assertDatabaseCount('webhook_events', 1);
        $this->assertDatabaseHas('audit_events', ['organization_id' => $org, 'action' => 'webhook.replayed']);
    }

    private function reservation(): array
    {
        $owner = User::factory()->create();
        $owner->forceFill(['can_create_organizations' => true])->save();
        $org = $this->actingAs($owner)->postJson('/api/v1/organizations', ['name' => 'Operación', 'time_zone' => 'America/Cancun'])->assertCreated()->json('id');
        $scope = "/api/v1/organizations/$org";
        $product = $this->postJson("$scope/products", ['name' => 'Visita'])->assertCreated()->json('id');
        $variant = $this->postJson("$scope/product-variants", ['name' => 'Sesión', 'product_id' => $product, 'modality' => 'session'])->assertCreated()->json('id');
        $session = $this->postJson("$scope/sessions", [
            'variant_id' => $variant, 'capacity' => 2, 'start_at' => '2035-07-12T14:00:00Z', 'end_at' => '2035-07-12T15:00:00Z',
        ])->assertCreated()->json('id');
        $this->postJson("$scope/products/$product/policies", ['terms' => 'Prueba'])->assertCreated();
        $this->postJson("$scope/rates", ['variant_id' => $variant, 'amount' => '0', 'currency' => 'MXN'])->assertCreated();
        $this->patchJson("$scope/products/$product/status", ['status' => 'published'])->assertOk();
        $selection = ['session_id' => $session, 'quantity' => 2];
        $quote = $this->postJson("$scope/quotes", ['variant_id' => $variant, 'selection' => $selection])->assertCreated()->json('id');
        $hold = $this->postWithKey("$scope/holds", ['quote_id' => $quote, 'selection' => $selection], 'hold')->assertCreated()->json('id');
        $customer = $this->postJson("$scope/customers", ['name' => 'Cliente'])->assertCreated()->json('id');
        $reservation = $this->postWithKey("$scope/reservations", ['hold_id' => $hold, 'customer_id' => $customer], 'reserve')->assertCreated()->json('id');

        return [$scope, $org, $reservation, $owner];
    }

    private function postWithKey(string $path, array $body, string $key): TestResponse
    {
        return $this->postJson($path, $body, ['Idempotency-Key' => $key]);
    }

    private function signed(string $org, string $raw, ?int $timestamp = null): TestResponse
    {
        $timestamp ??= now()->timestamp;
        $secret = hash_hmac('sha256', 'ticketours.simulator.'.$org, (string) config('app.key'), true);
        $signature = 'v1='.hash_hmac('sha256', $timestamp.'.'.$raw, $secret);

        return $this->call('POST', "/api/v1/integrations/$org/webhook", [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_TICKETOURS_TIMESTAMP' => (string) $timestamp, 'HTTP_X_TICKETOURS_SIGNATURE' => $signature,
        ], $raw);
    }
}
