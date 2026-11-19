<?php

use App\Modules\Audit\AuditController;
use App\Modules\Catalog\CatalogController;
use App\Modules\Commissions\SettlementController;
use App\Modules\Customers\CustomerController;
use App\Modules\Distribution\AgreementController;
use App\Modules\Distribution\DistributionController;
use App\Modules\Identity\SessionController;
use App\Modules\Integrations\WebhookController;
use App\Modules\Inventory\AvailabilityController;
use App\Modules\Inventory\InventoryController;
use App\Modules\Operations\CheckinController;
use App\Modules\Organizations\InvitationController;
use App\Modules\Organizations\OrganizationController;
use App\Modules\Pricing\PricingController;
use App\Modules\Reporting\ReportController;
use App\Modules\Reservations\HoldController;
use App\Modules\Reservations\ReservationController;
use App\Modules\Reservations\ReservationSettingsController;
use App\Modules\Sales\SalesController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

$uuid = '[0-9a-fA-F]{8}-(?:[0-9a-fA-F]{4}-){3}[0-9a-fA-F]{12}';

Route::prefix('api/v1')->group(function () use ($uuid) {
    Route::get('auth/csrf', [SessionController::class, 'csrf']);
    Route::post('auth/login', [SessionController::class, 'login'])->middleware('throttle:login');
    Route::post('invitations/accept', [InvitationController::class, 'accept'])->middleware('throttle:10,1');

    Route::post('integrations/{org}/webhook', [WebhookController::class, 'receive'])
        ->whereUuid('org')->withoutMiddleware(ValidateCsrfToken::class)->middleware('throttle:60,1');

    Route::middleware('auth')->group(function () use ($uuid) {
        Route::get('auth/me', [SessionController::class, 'show']);
        Route::post('auth/logout', [SessionController::class, 'logout']);
        Route::get('organizations', [OrganizationController::class, 'index']);
        Route::post('organizations', [OrganizationController::class, 'store']);

        Route::prefix('organizations/{org}')->where(['org' => $uuid, 'id' => $uuid])->group(function () {
            Route::get('memberships', [OrganizationController::class, 'memberships']);
            Route::post('invitations', [InvitationController::class, 'store'])->middleware('throttle:20,1');
            Route::get('audit-events', [AuditController::class, 'index']);

            Route::get('products', [CatalogController::class, 'index']);
            Route::post('products', [CatalogController::class, 'store']);
            Route::get('products/{id}', [CatalogController::class, 'show']);
            Route::patch('products/{id}', [CatalogController::class, 'update']);
            Route::patch('products/{id}/status', [CatalogController::class, 'changeStatus']);
            Route::get('product-variants', [CatalogController::class, 'variants']);
            Route::post('product-variants', [CatalogController::class, 'createVariant']);

            Route::get('sessions', [InventoryController::class, 'sessions']);
            Route::post('sessions', [InventoryController::class, 'createSession']);
            Route::get('resources', [InventoryController::class, 'resources']);
            Route::post('resources', [InventoryController::class, 'createResource']);
            Route::post('resources/{id}/windows', [InventoryController::class, 'createWindow']);
            Route::post('resources/{id}/blocks', [InventoryController::class, 'createBlock']);
            Route::get('availability', AvailabilityController::class);

            Route::get('rates', [PricingController::class, 'rates']);
            Route::post('rates', [PricingController::class, 'createRate']);
            Route::get('products/{id}/policies', [PricingController::class, 'policies']);
            Route::post('products/{id}/policies', [PricingController::class, 'createPolicy']);

            Route::get('customers', [CustomerController::class, 'index']);
            Route::post('customers', [CustomerController::class, 'store']);
            Route::post('quotes', [HoldController::class, 'quote']);
            Route::post('holds', [HoldController::class, 'store']);
            Route::post('holds/{id}/release', [HoldController::class, 'release']);
            Route::post('reservations', [ReservationController::class, 'store']);
            Route::get('reservations/{id}', [ReservationController::class, 'show']);
            Route::post('reservations/{id}/confirm', [ReservationController::class, 'confirm']);
            Route::post('reservations/{id}/cancel', [ReservationController::class, 'cancel']);
            Route::get('reservations/{id}/order', [SalesController::class, 'order']);
            Route::post('reservations/{id}/payments', [SalesController::class, 'pay']);
            Route::get('reservations/{id}/voucher', [SalesController::class, 'voucher']);
            Route::get('distribution/agreements', [AgreementController::class, 'index']);
            Route::post('distribution/agreements', [AgreementController::class, 'store']);
            Route::patch('distribution/agreements/{id}', [AgreementController::class, 'transition']);
            Route::post('distribution/agreements/{id}/offers', [AgreementController::class, 'publish']);
            Route::get('distribution/offers', [DistributionController::class, 'offers']);
            Route::patch('distribution/offers/{id}', [AgreementController::class, 'changeOffer']);
            Route::get('distribution/offers/{id}/availability', [DistributionController::class, 'availability']);
            Route::post('distribution/offers/{id}/quotes', [DistributionController::class, 'quote']);
            Route::post('distribution/offers/{id}/holds', [DistributionController::class, 'hold']);
            Route::post('distribution/offers/{id}/holds/{hold}/release', [DistributionController::class, 'release'])->whereUuid('hold');
            Route::post('distribution/offers/{id}/reservations', [DistributionController::class, 'reserve']);
            Route::get('distribution/sales', [DistributionController::class, 'sales']);
            Route::get('distribution/commissions', [DistributionController::class, 'commissions']);
            Route::get('distribution/commission-balances', [DistributionController::class, 'balances']);
            Route::get('reservations/{id}/tickets', [CheckinController::class, 'tickets']);
            Route::post('operations/check-ins', [CheckinController::class, 'scan']);
            Route::get('operations/validators', [CheckinController::class, 'validators']);
            Route::patch('operations/validators', [CheckinController::class, 'grant']);
            Route::get('distribution/settlements', [SettlementController::class, 'index']);
            Route::post('distribution/settlements', [SettlementController::class, 'store']);
            Route::patch('distribution/settlements/{id}', [SettlementController::class, 'transition']);
            Route::get('distribution/settlements/{id}/items', [SettlementController::class, 'items']);
            Route::get('reports/summary', ReportController::class);
            Route::post('integrations/simulate', [WebhookController::class, 'simulate'])->middleware('throttle:60,1');
            Route::get('reservation-settings', [ReservationSettingsController::class, 'show']);
            Route::patch('reservation-settings', [ReservationSettingsController::class, 'update']);
        });
    });
});
