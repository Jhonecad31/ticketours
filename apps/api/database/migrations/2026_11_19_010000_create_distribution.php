<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agreements', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('provider_id')->constrained('organizations')->restrictOnDelete();
            $t->foreignUuid('agency_id')->constrained('organizations')->restrictOnDelete();
            $t->enum('status', ['proposed', 'active', 'suspended', 'terminated']);
            $t->string('commission_percent', 8);
            $t->unique(['provider_id', 'id']);
            $t->unique(['provider_id', 'agency_id', 'id'], 'agreements_parties_key');
            $t->index(['agency_id', 'id']);
        });
        Schema::create('distribution_offers', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('provider_id');
            $t->uuid('agreement_id');
            $t->uuid('variant_id');
            $t->uuid('rate_version_id');
            $t->boolean('enabled')->default(true);
            $t->unique(['provider_id', 'id']);
            $t->unique(['provider_id', 'agreement_id', 'id'], 'distribution_offers_agreement_key');
            $t->index(['agreement_id', 'enabled', 'id']);
            $this->reference($t, 'provider_id', 'agreement_id', 'agreements', 'provider_id');
            $this->reference($t, 'provider_id', 'variant_id', 'product_variants');
            $this->reference($t, 'provider_id', 'rate_version_id', 'base_rate_versions');
        });
        Schema::table('quotes', function (Blueprint $t): void {
            $t->uuid('distribution_offer_id')->nullable();
            $this->reference($t, 'organization_id', 'distribution_offer_id', 'distribution_offers', 'provider_id');
        });
        Schema::table('customers', fn (Blueprint $t) => $t->boolean('is_agency_guest')->default(false));
        Schema::create('agency_sales', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('provider_id');
            $t->uuid('agency_id');
            $t->uuid('agreement_id');
            $t->uuid('offer_id');
            $t->uuid('reservation_id');
            $t->uuid('agency_customer_id');
            $t->string('commission_percent', 8);
            $t->unique(['provider_id', 'id']);
            $t->unique(['provider_id', 'agency_id', 'id'], 'agency_sales_parties_key');
            $t->unique(['provider_id', 'reservation_id']);
            $t->index(['agency_id', 'id']);
            $t->foreign(['provider_id', 'agency_id', 'agreement_id'], 'agency_sales_agreement_fk')
                ->references(['provider_id', 'agency_id', 'id'])->on('agreements')->restrictOnDelete();
            $t->foreign(['provider_id', 'agreement_id', 'offer_id'], 'agency_sales_offer_fk')
                ->references(['provider_id', 'agreement_id', 'id'])->on('distribution_offers')->restrictOnDelete();
            $this->reference($t, 'provider_id', 'reservation_id', 'reservations');
            $this->reference($t, 'agency_id', 'agency_customer_id', 'customers');
        });
        Schema::create('commission_entries', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('provider_id');
            $t->uuid('agency_id');
            $t->uuid('sale_id');
            $t->uuid('payment_id');
            $t->uuid('refund_id')->nullable();
            $t->enum('type', ['accrual', 'reversal']);
            $t->string('amount', 64);
            $t->char('currency', 3);
            $t->timestampTz('recorded_at');
            $t->index(['provider_id', 'id']);
            $t->index(['agency_id', 'id']);
            $t->unique(['provider_id', 'payment_id', 'type'], 'commission_payment_type_key');
            $t->foreign(['provider_id', 'agency_id', 'sale_id'], 'commission_sale_fk')
                ->references(['provider_id', 'agency_id', 'id'])->on('agency_sales')->restrictOnDelete();
            $this->reference($t, 'provider_id', 'payment_id', 'payments');
            $this->reference($t, 'provider_id', 'refund_id', 'refunds');
        });
    }

    public function down(): void
    {
        if (DB::table('agency_sales')->exists() || DB::table('commission_entries')->exists()) {
            throw new RuntimeException('Commercial history must be preserved.');
        }
        Schema::dropIfExists('commission_entries');
        Schema::dropIfExists('agency_sales');
        Schema::table('quotes', function (Blueprint $t): void {
            $t->dropForeign(['organization_id', 'distribution_offer_id']);
            $t->dropColumn('distribution_offer_id');
        });
        Schema::table('customers', fn (Blueprint $t) => $t->dropColumn('is_agency_guest'));
        Schema::dropIfExists('distribution_offers');
        Schema::dropIfExists('agreements');
    }

    private function reference(Blueprint $t, string $scope, string $column, string $table, string $targetScope = 'organization_id'): void
    {
        $t->foreign([$scope, $column])->references([$targetScope, 'id'])->on($table)->restrictOnDelete();
    }
};
