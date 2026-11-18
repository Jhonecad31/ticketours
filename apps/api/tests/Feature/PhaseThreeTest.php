<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Organizations\Membership;
use App\Modules\Organizations\Role;
use App\Modules\Reservations\ExpireHolds;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PhaseThreeTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_confirmation_preserves_price_and_policy_and_cancellation_releases_cupo(): void
    {
        [$scope, $owner, $product, $variant, $session, $firstPolicy] = $this->setupInventory();
        $agent = User::factory()->create();
        app(Membership::class)->create(basename($scope), $agent->id, Role::BookingAgent);
        $this->actingAs($agent);
        $customer = $this->postJson("$scope/customers", ['name' => 'Persona de prueba'])->assertCreated()->json('id');
        $selection = ['session_id' => $session, 'quantity' => 2];
        $quote = $this->quote($scope, $variant, $selection)->assertCreated()
            ->assertJsonPath('total', '0.2400')->assertJsonPath('policy_version_id', $firstPolicy)->json();
        $hold = $this->hold($scope, $quote, 'hold-one')->assertCreated()->json('id');
        $this->hold($scope, $quote, 'hold-one')->assertCreated()->assertJsonPath('id', $hold);
        $this->hold($scope, $quote, 'hold-another')->assertConflict()->assertJsonPath('code', 'QUOTE_ALREADY_USED');
        $this->getJson($this->availability($scope, $variant))->assertOk()->assertJsonPath('items.0.available_quantity', 0);

        $payload = ['hold_id' => $hold, 'customer_id' => $customer, 'participants' => [['name' => 'Acompañante']]];
        $reservation = $this->mutate("$scope/reservations", $payload, 'reserve-one')->assertCreated()->json('id');
        $this->mutate("$scope/reservations", $payload, 'reserve-one')->assertCreated()->assertJsonPath('id', $reservation);
        $this->mutate("$scope/reservations", [...$payload, 'participants' => []], 'reserve-one')->assertConflict()
            ->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');
        $this->mutate("$scope/holds/$hold/release", [], 'release')->assertConflict();
        $route = "$scope/reservations/$reservation";
        $this->mutate("$route/confirm", [], 'confirm-one')->assertForbidden();
        $this->mutate("$route/cancel", ['reason' => 'Prueba'], 'cancel-one')->assertForbidden();

        $this->actingAs($owner);
        $this->postJson("$scope/rates", ['variant_id' => $variant, 'amount' => '99.00', 'currency' => 'MXN'])->assertCreated();
        $this->postJson("$scope/products/$product/policies", ['terms' => 'Política nueva.'])->assertCreated();
        $result = $this->mutate("$route/confirm", [], 'confirm-one')->assertOk()->assertJsonPath('status', 'confirmed')
            ->assertJsonPath('items.0.total', '0.2400')->assertJsonPath('items.0.policy_version_id', $firstPolicy);
        $this->mutate("$route/confirm", [], 'confirm-one')->assertOk()->assertExactJson($result->json());
        $this->assertDatabaseHas('reservation_items', ['reservation_id' => $reservation, 'policy_terms' => 'Política inicial.']);
        $this->getJson($this->availability($scope, $variant))->assertJsonPath('items.0.available_quantity', 0);

        $this->mutate("$route/cancel", ['reason' => 'Solicitud de prueba'], 'cancel-one')->assertOk()->assertJsonPath('status', 'cancelled');
        $this->mutate("$route/cancel", ['reason' => 'Solicitud de prueba'], 'cancel-one')->assertOk();
        $this->getJson($this->availability($scope, $variant))->assertJsonPath('items.0.available_quantity', 2);
        $this->assertDatabaseCount('reservations', 1);
        $this->assertDatabaseCount('reservation_history', 3);
        $this->assertDatabaseHas('reservation_history', ['reservation_id' => $reservation, 'status' => 'cancelled', 'reason' => 'Solicitud de prueba']);
        $this->assertDatabaseHas('audit_events', ['action' => 'reservation.cancelled', 'entity_id' => $reservation]);
    }

    public function test_expiration_is_idempotent_and_quote_and_hold_use_independent_timers(): void
    {
        [$scope, , , $variant, $session] = $this->setupInventory();
        $this->freezeTime();
        $this->getJson("$scope/reservation-settings")->assertOk()->assertJsonPath('hold_minutes', 15);
        $this->patchJson("$scope/reservation-settings", ['hold_minutes' => 2])->assertOk()->assertJsonPath('hold_minutes', 2);
        $selection = ['session_id' => $session, 'quantity' => 2];
        $stale = $this->quote($scope, $variant, $selection)->assertCreated()->json();
        $this->travel(1)->minutes();
        $quote = $this->quote($scope, $variant, $selection)->assertCreated()->json();
        $this->travel(1)->minutes();
        $this->hold($scope, $stale, 'stale')->assertConflict()->assertJsonPath('code', 'QUOTE_EXPIRED');
        $hold = $this->hold($scope, $quote, 'live')->assertCreated()->json();
        $this->assertGreaterThan(strtotime($quote['expires_at']), strtotime($hold['expires_at']));
        $customer = $this->postJson("$scope/customers", ['name' => 'Persona'])->assertCreated()->json('id');
        $id = $this->mutate("$scope/reservations", ['hold_id' => $hold['id'], 'customer_id' => $customer], 'reserve')->assertCreated()->json('id');
        $this->travel(2)->minutes();
        $this->mutate("$scope/reservations/$id/confirm", [], 'expired-confirm')->assertConflict()->assertJsonPath('code', 'HOLD_EXPIRED');
        $this->getJson($this->availability($scope, $variant))->assertJsonPath('items.0.available_quantity', 2);

        app()->call([new ExpireHolds, 'handle']);
        app()->call([new ExpireHolds, 'handle']);
        $this->getJson("$scope/reservations/$id")->assertOk()->assertJsonPath('status', 'expired');
        $this->assertDatabaseCount('reservation_history', 2);
        $this->assertDatabaseHas('audit_events', ['entity_id' => $hold['id'], 'action' => 'hold.expired', 'actor_id' => null]);
        $fresh = $this->quote($scope, $variant, $selection)->assertCreated()->json();
        $this->hold($scope, $fresh, 'fresh')->assertCreated();
    }

    public function test_resource_claims_apply_across_variants_and_reject_conflicting_blocks(): void
    {
        [$scope, , $product] = $this->setupInventory();
        $resource = $this->postJson("$scope/resources", ['name' => 'Salón'])->assertCreated()->json('id');
        $this->patchJson("$scope/products/$product/status", ['status' => 'suspended'])->assertOk();
        $first = $this->variant($scope, $product, 'exclusive_resource');
        $second = $this->variant($scope, $product, 'exclusive_resource');
        $window = ['start_at' => '2035-07-12T14:00:00Z', 'end_at' => '2035-07-12T18:00:00Z'];
        foreach ([$first, $second] as $variant) {
            $this->postJson("$scope/rates", ['variant_id' => $variant, 'amount' => '50.00', 'currency' => 'MXN'])->assertCreated();
            $this->postJson("$scope/resources/$resource/windows", ['variant_id' => $variant, ...$window])->assertCreated();
        }
        $this->patchJson("$scope/products/$product/status", ['status' => 'published'])->assertOk();
        $selection = ['resource_id' => $resource, 'start_at' => '2035-07-12T15:00:00Z', 'end_at' => '2035-07-12T16:00:00Z'];
        $quote = $this->quote($scope, $first, $selection)->assertCreated()->assertJsonPath('total', '50.00')->json();
        $other = $this->quote($scope, $second, $selection)->assertCreated()->json();
        $hold = $this->hold($scope, $quote, 'first')->assertCreated()->json('id');
        $this->hold($scope, $other, 'second')->assertConflict()->assertJsonPath('code', 'INVENTORY_UNAVAILABLE');
        $this->postJson("$scope/resources/$resource/blocks", $window)->assertConflict();
        $this->getJson($this->availability($scope, $second))->assertOk()->assertJsonCount(2, 'items')
            ->assertJsonPath('items.0.end_at', '2035-07-12T15:00:00.000000Z');

        if (DB::getDriverName() === 'pgsql') {
            $claim = (array) DB::table('holds')->where('id', $hold)->first();
            try {
                DB::transaction(fn () => DB::table('holds')->insert([...$claim, 'id' => (string) Str::uuid(), 'quote_id' => $other['id']]));
                $this->fail('PostgreSQL must reject overlapping active resource claims');
            } catch (QueryException $error) {
                $this->assertSame('23P01', $error->errorInfo[0]);
            }
        }
        $this->mutate("$scope/holds/$hold/release", [], 'release')->assertOk()->assertJsonPath('status', 'released');
        $this->hold($scope, $other, 'second')->assertCreated();
    }

    public function test_service_start_caps_ttl_and_closes_cancellation(): void
    {
        [$scope, , , $variant, $session] = $this->setupInventory();
        $this->travelTo(Carbon::parse('2035-07-12T13:59:00Z'));
        $quote = $this->quote($scope, $variant, ['session_id' => $session, 'quantity' => 1])->assertCreated()->json();
        $hold = $this->hold($scope, $quote, 'near-start')->assertCreated()->json();
        $start = strtotime('2035-07-12T14:00:00Z');
        $this->assertSame($start, strtotime($quote['expires_at']));
        $this->assertSame($start, strtotime($hold['expires_at']));
        $customer = $this->postJson("$scope/customers", ['name' => 'Persona'])->assertCreated()->json('id');
        $id = $this->mutate("$scope/reservations", ['hold_id' => $hold['id'], 'customer_id' => $customer], 'reserve')->assertCreated()->json('id');
        $this->mutate("$scope/reservations/$id/confirm", [], 'confirm')->assertOk();

        $other = $this->postJson('/api/v1/organizations', ['name' => 'Otra', 'time_zone' => 'America/Cancun'])->assertCreated()->json('id');
        $this->getJson("/api/v1/organizations/$other/reservations/$id")->assertNotFound();
        $this->getJson("/api/v1/organizations/$other/customers")->assertOk()->assertJsonCount(0, 'items');
        $this->travel(1)->minutes();
        $this->mutate("$scope/reservations/$id/cancel", ['reason' => 'Fuera de plazo'], 'late-cancel')
            ->assertConflict()->assertJsonPath('code', 'CANCELLATION_CLOSED');
        $this->getJson("$scope/reservations/$id")->assertOk()->assertJsonPath('status', 'confirmed');
    }

    private function setupInventory(): array
    {
        $owner = User::factory()->create();
        $owner->forceFill(['can_create_organizations' => true])->save();
        $this->actingAs($owner);
        $id = $this->postJson('/api/v1/organizations', ['name' => 'Inventario', 'time_zone' => 'America/Cancun'])->assertCreated()->json('id');
        $scope = '/api/v1/organizations/'.$id;
        $product = $this->postJson("$scope/products", ['name' => 'Visita'])->assertCreated()->json('id');
        $variant = $this->variant($scope, $product, 'session');
        $session = $this->postJson("$scope/sessions", [
            'variant_id' => $variant, 'capacity' => 2, 'start_at' => '2035-07-12T14:00:00Z', 'end_at' => '2035-07-12T15:00:00Z',
        ])->assertCreated()->json('id');
        $this->postJson("$scope/rates", ['variant_id' => $variant, 'amount' => '0.1200', 'currency' => 'MXN'])->assertCreated();
        $policy = $this->postJson("$scope/products/$product/policies", ['terms' => 'Política inicial.'])->assertCreated()->json('id');
        $this->patchJson("$scope/products/$product/status", ['status' => 'published'])->assertOk();

        return [$scope, $owner, $product, $variant, $session, $policy];
    }

    private function variant(string $scope, string $product, string $modality): string
    {
        return $this->postJson("$scope/product-variants", ['product_id' => $product, 'name' => $modality, 'modality' => $modality])
            ->assertCreated()->json('id');
    }

    private function availability(string $scope, string $variant): string
    {
        return "$scope/availability?variant_id=$variant&from=2035-07-12T13:00:00Z&to=2035-07-12T19:00:00Z";
    }

    private function quote(string $scope, string $variant, array $selection): TestResponse
    {
        return $this->postJson("$scope/quotes", ['variant_id' => $variant, 'selection' => $selection]);
    }

    private function hold(string $scope, array $quote, string $key): TestResponse
    {
        return $this->mutate("$scope/holds", ['quote_id' => $quote['id'], 'selection' => $quote['selection']], $key);
    }

    private function mutate(string $path, array $payload, string $key): TestResponse
    {
        return $this->postJson($path, $payload, ['Idempotency-Key' => $key]);
    }
}
