<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Organizations\Membership;
use App\Modules\Organizations\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PhaseTwoTest extends TestCase
{
    use RefreshDatabase;

    public function test_publication_needs_each_variant_ready_and_availability_applies_blocks(): void
    {
        $scope = $this->organization('Principal');
        $product = $this->postJson("$scope/products", ['name' => 'Visita y salón'])->assertCreated()->json('id');
        $status = "$scope/products/$product/status";
        $this->patchJson($status, ['status' => 'published'])->assertConflict()->assertJsonPath('code', 'PRODUCT_NOT_READY');

        $session = $this->variant($scope, $product, 'session');
        $exclusive = $this->variant($scope, $product, 'exclusive_resource');
        $resource = $this->postJson("$scope/resources", ['name' => 'Salón'])->assertCreated()->json('id');
        $this->postJson("$scope/products/$product/policies", ['terms' => 'Presentarse con identificación.'])->assertCreated()->assertJsonPath('version', 1);
        $capacity = $this->postJson("$scope/sessions", [
            'variant_id' => $session, 'capacity' => 5, 'start_at' => '2035-07-12T09:00:00-05:00', 'end_at' => '2035-07-12T10:00:00-05:00',
        ])->assertCreated()->assertJsonPath('start_at', '2035-07-12T14:00:00.000000Z')->json('id');
        $this->rate($scope, $session, '123456789012345678.123456789012')->assertCreated()
            ->assertJsonPath('version', 1)->assertJsonPath('amount', '123456789012345678.123456789012');
        $this->rate($scope, $session, '0.1200')->assertCreated()->assertJsonPath('version', 2);
        $this->getJson("$scope/rates?variant_id=$session")->assertOk()->assertJsonCount(2, 'items')
            ->assertJsonFragment(['amount' => '123456789012345678.123456789012', 'version' => 1])
            ->assertJsonFragment(['amount' => '0.1200', 'version' => 2]);
        $this->rate($scope, $exclusive, '50.00')->assertCreated();
        $this->patchJson($status, ['status' => 'published'])->assertConflict();
        $this->getJson($this->availability($scope, $session))->assertNotFound();

        $this->interval("$scope/resources/$resource/windows", '14:00', '16:00', ['variant_id' => $exclusive])->assertCreated();
        $this->interval("$scope/resources/$resource/windows", '15:30', '18:00', ['variant_id' => $exclusive])->assertCreated();
        $this->interval("$scope/resources/$resource/blocks", '15:00', '16:30')->assertCreated();
        $this->interval("$scope/resources/$resource/blocks", '16:00', '17:00')->assertCreated();
        $this->patchJson($status, ['status' => 'published'])->assertOk()->assertJsonPath('status', 'published');
        $this->postJson("$scope/product-variants", ['product_id' => $product, 'name' => 'Extra', 'modality' => 'session'])->assertConflict();

        $this->getJson($this->availability($scope, $session))->assertOk()
            ->assertJsonPath('time_zone', 'America/Cancun')->assertJsonPath('items.0.session_id', $capacity)
            ->assertJsonPath('items.0.available_quantity', 5);
        $this->getJson($this->availability($scope, $exclusive)."&resource_id=$resource")->assertOk()->assertExactJson([
            'variant_id' => $exclusive, 'time_zone' => 'America/Cancun',
            'items' => [
                ['resource_id' => $resource, 'start_at' => '2035-07-12T14:00:00.000000Z', 'end_at' => '2035-07-12T15:00:00.000000Z'],
                ['resource_id' => $resource, 'start_at' => '2035-07-12T17:00:00.000000Z', 'end_at' => '2035-07-12T18:00:00.000000Z'],
            ],
        ]);
        $this->getJson("$scope/audit-events")->assertOk()->assertJsonFragment(['action' => 'product.published']);
        $this->patchJson($status, ['status' => 'suspended'])->assertOk();
        $this->getJson($this->availability($scope, $exclusive))->assertNotFound();
    }

    public function test_catalog_and_inventory_enforce_organization_and_role(): void
    {
        $north = $this->organization('Norte');
        $south = $this->organization('Sur');
        app(Membership::class)->create(basename($north), auth()->id(), Role::Admin);
        $product = $this->postJson("$north/products", ['name' => 'Visita'])->assertCreated()->json('id');
        $variant = $this->variant($north, $product, 'exclusive_resource');
        $resource = $this->postJson("$south/resources", ['name' => 'Salón'])->assertCreated()->json('id');

        $this->getJson("$south/products/$product")->assertNotFound();
        $this->getJson("$south/product-variants?product_id=$product")->assertNotFound();
        $this->rate($south, $variant, '20')->assertNotFound();
        $this->interval("$north/resources/$resource/windows", '14:00', '18:00', ['variant_id' => $variant])->assertNotFound();
        $this->interval("$north/sessions", '14:00', '18:00', ['variant_id' => $variant, 'capacity' => 2])
            ->assertConflict()->assertJsonPath('code', 'INVALID_INVENTORY_MODALITY');
        $this->interval("$south/resources/$resource/blocks", '18:00', '14:00')->assertUnprocessable();

        $agent = User::factory()->create();
        app(Membership::class)->create(basename($north), $agent->id, Role::BookingAgent);
        $this->actingAs($agent)->getJson("$north/products")->assertOk()->assertJsonCount(1, 'items');
        $this->postJson("$north/products", ['name' => 'Denegado'])->assertForbidden();
        $this->patchJson("$north/products/$product/status", ['status' => 'published'])->assertForbidden();
        $this->getJson("$south/resources")->assertNotFound();
    }

    private function organization(string $name): string
    {
        $user = User::factory()->create();
        $user->forceFill(['can_create_organizations' => true])->save();
        $this->actingAs($user);
        $id = $this->postJson('/api/v1/organizations', ['name' => $name, 'time_zone' => 'America/Cancun'])->assertCreated()->json('id');

        return '/api/v1/organizations/'.$id;
    }

    private function variant(string $scope, string $product, string $modality): string
    {
        return $this->postJson("$scope/product-variants", ['product_id' => $product, 'name' => $modality, 'modality' => $modality])
            ->assertCreated()->json('id');
    }

    private function rate(string $scope, string $variant, string $amount): TestResponse
    {
        return $this->postJson("$scope/rates", ['variant_id' => $variant, 'amount' => $amount, 'currency' => 'MXN']);
    }

    private function interval(string $route, string $from, string $to, array $extra = []): TestResponse
    {
        return $this->postJson($route, [...$extra, 'start_at' => "2035-07-12T{$from}:00Z", 'end_at' => "2035-07-12T{$to}:00Z"]);
    }

    private function availability(string $scope, string $variant): string
    {
        return "$scope/availability?variant_id=$variant&from=2035-07-12T13:00:00Z&to=2035-07-12T19:00:00Z";
    }
}
