<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PhaseFiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_agency_shares_capacity_and_earns_audited_commission_reversed_on_refund(): void
    {
        [$provider, $owner, $providerId] = $this->organization('Proveedor');
        [$agency, $agent, $agencyId] = $this->organization('Agencia');
        [$other, $outsider] = $this->organization('Tercero');

        $this->actingAs($owner);
        $product = $this->postJson("$provider/products", ['name' => 'Visita'])->assertCreated()->json('id');
        $variant = $this->postJson("$provider/product-variants", ['name' => 'Sesión', 'product_id' => $product, 'modality' => 'session'])->assertCreated()->json('id');
        $session = $this->postJson("$provider/sessions", [
            'variant_id' => $variant, 'capacity' => 1,
            'start_at' => '2035-07-12T14:00:00Z', 'end_at' => '2035-07-12T15:00:00Z',
        ])->assertCreated()->json('id');
        $rate = $this->postJson("$provider/rates", ['variant_id' => $variant, 'amount' => '100', 'currency' => 'MXN'])->assertCreated()->json('id');
        $this->postJson("$provider/products/$product/policies", ['terms' => 'Permite parcial', 'allows_partial_payment' => true])->assertCreated();
        $this->patchJson("$provider/products/$product/status", ['status' => 'published'])->assertOk();

        $agreement = $this->postJson("$provider/distribution/agreements", ['agency_id' => $agencyId, 'commission_percent' => '10'])->assertCreated()->json('id');
        $this->patchJson("$provider/distribution/agreements/$agreement", ['status' => 'active'])->assertConflict();
        $offerInput = ['variant_id' => $variant, 'rate_version_id' => $rate];
        $this->postJson("$provider/distribution/agreements/$agreement/offers", $offerInput)->assertConflict();
        $this->actingAs($outsider)->patchJson("$other/distribution/agreements/$agreement", ['status' => 'active'])->assertNotFound();
        $this->actingAs($agent)->patchJson("$agency/distribution/agreements/$agreement", ['status' => 'active'])->assertOk();
        $this->actingAs($owner);
        $id = $this->postJson("$provider/distribution/agreements/$agreement/offers", $offerInput)->assertCreated()->json('id');
        $this->postJson("$provider/rates", ['variant_id' => $variant, 'amount' => '200', 'currency' => 'MXN'])->assertCreated();
        $selection = ['session_id' => $session, 'quantity' => 1];
        $direct = $this->postJson("$provider/quotes", ['variant_id' => $variant, 'selection' => $selection])->assertCreated()->assertJsonPath('total', '200')->json('id');
        $dates = '?from=2035-07-12T13:00:00Z&to=2035-07-12T16:00:00Z';

        $this->actingAs($outsider)->getJson("$other/distribution/offers/$id/availability$dates")->assertNotFound();
        $this->actingAs($agent);
        $this->getJson("$agency/distribution/offers")->assertOk()->assertJsonPath('items.0.amount', '100');
        $scope = "$agency/distribution/offers/$id";
        $this->getJson("$scope/availability$dates")->assertOk()->assertJsonPath('items.0.available_quantity', 1);
        $quote = $this->postJson("$scope/quotes", ['selection' => $selection])->assertCreated()->assertJsonPath('total', '100')->json('id');
        $hold = $this->mutate("$scope/holds", ['quote_id' => $quote, 'selection' => $selection], 'hold')->assertCreated()->json('id');
        $customer = $this->postJson("$agency/customers", ['name' => 'Cliente de agencia', 'email' => 'privado@example.test'])->assertCreated()->json('id');
        $reservation = $this->mutate("$scope/reservations", ['hold_id' => $hold, 'customer_id' => $customer], 'reserve')->assertCreated()->assertJsonPath('customer_id', $customer)->json('id');
        $this->mutate("$scope/reservations", ['hold_id' => $hold, 'customer_id' => $customer], 'reserve')->assertCreated()->assertJsonPath('id', $reservation);
        $this->getJson("$provider/reservations/$reservation")->assertNotFound();
        $this->getJson("$agency/distribution/sales")->assertOk()->assertJsonPath('items.0.reservation_status', 'held');

        $this->actingAs($owner);
        $this->mutate("$provider/holds", ['quote_id' => $direct, 'selection' => $selection], 'direct')->assertConflict()->assertJsonPath('code', 'INVENTORY_UNAVAILABLE');
        $this->getJson("$provider/customers")->assertOk()->assertJsonCount(0, 'items');
        $this->assertDatabaseHas('customers', ['organization_id' => $providerId, 'is_agency_guest' => true, 'name' => 'Cliente de agencia', 'email' => null]);
        $this->assertDatabaseMissing('customers', ['organization_id' => $providerId, 'email' => 'privado@example.test']);
        $ref = "$provider/reservations/$reservation";
        $this->mutate("$ref/payments", ['amount' => '50'], 'pay-first')->assertCreated();
        $this->getJson("$provider/distribution/commission-balances")->assertOk()->assertJsonCount(0, 'items');
        $this->mutate("$ref/confirm", [], 'confirm')->assertOk();
        $this->getJson("$provider/distribution/commission-balances")->assertOk()->assertJsonPath('items.0.amount', '5')->assertJsonPath('items.0.direction', 'payable');
        $this->mutate("$ref/payments", ['amount' => '50'], 'pay-second')->assertCreated();
        $this->mutate("$ref/payments", ['amount' => '50'], 'pay-second')->assertCreated();
        $this->patchJson("$provider/distribution/agreements/$agreement", ['status' => 'suspended'])->assertOk();

        $this->actingAs($agent)->getJson("$scope/availability$dates")->assertNotFound();
        $this->getJson("$agency/distribution/commission-balances")->assertOk()->assertJsonPath('items.0.amount', '10')->assertJsonPath('items.0.direction', 'receivable');
        $this->getJson("$agency/distribution/sales")->assertOk()->assertJsonPath('items.0.order.payment_status', 'paid');
        $this->actingAs($owner);
        $settlement = $this->mutate("$provider/distribution/settlements", ['agency_id' => $agencyId, 'currency' => 'MXN'], 'settle')
            ->assertCreated()->assertJsonPath('total', '10')->json('id');
        $settlementRoute = "$provider/distribution/settlements/$settlement";
        $this->patchJson($settlementRoute, ['status' => 'paid'], ['Idempotency-Key' => 'early'])->assertConflict();
        $this->patchJson($settlementRoute, ['status' => 'approved'], ['Idempotency-Key' => 'approve'])->assertOk();
        $paid = $this->patchJson($settlementRoute, ['status' => 'paid'], ['Idempotency-Key' => 'pay'])->assertOk()->assertJsonPath('status', 'paid')->json('payment_reference');
        $this->patchJson($settlementRoute, ['status' => 'paid'], ['Idempotency-Key' => 'pay'])->assertOk()->assertJsonPath('payment_reference', $paid);
        $this->getJson("$settlementRoute/items")->assertOk()->assertJsonCount(2, 'items');
        $this->actingAs($agent)->getJson("$agency/distribution/settlements")->assertOk()->assertJsonPath('items.0.status', 'paid');
        $this->patchJson("$agency/distribution/settlements/$settlement", ['status' => 'reconciled'], ['Idempotency-Key' => 'forbidden'])->assertNotFound();
        $this->actingAs($owner)->mutate("$ref/cancel", ['reason' => 'Prueba'], 'cancel')->assertOk();
        $this->getJson("$provider/distribution/commissions")->assertOk()->assertJsonCount(4, 'items')->assertJsonFragment(['type' => 'reversal', 'amount' => '-5']);
        $this->getJson("$provider/distribution/commission-balances")->assertJsonPath('items.0.amount', '-10');
        $this->mutate("$provider/distribution/settlements", ['agency_id' => $agencyId, 'currency' => 'MXN'], 'negative')->assertConflict()
            ->assertJsonPath('code', 'SETTLEMENT_NOT_POSITIVE');
        $this->getJson("$settlementRoute/items")->assertOk()->assertJsonCount(2, 'items');
        $this->getJson("$provider/availability{$dates}&variant_id=$variant")->assertJsonPath('items.0.available_quantity', 1);

        $this->actingAs($outsider)->getJson("$other/distribution/sales")->assertOk()->assertJsonCount(0, 'items');
        $this->assertDatabaseHas('audit_events', ['organization_id' => $agencyId, 'action' => 'commission.reversal']);
    }

    private function organization(string $label): array
    {
        $actor = User::factory()->create();
        $actor->forceFill(['can_create_organizations' => true])->save();
        $id = $this->actingAs($actor)->postJson('/api/v1/organizations', ['name' => $label, 'time_zone' => 'America/Cancun'])->assertCreated()->json('id');

        return ['/api/v1/organizations/'.$id, $actor, $id];
    }

    private function mutate(string $route, array $body, string $key): TestResponse
    {
        return $this->postJson($route, $body, ['Idempotency-Key' => $key]);
    }
}
