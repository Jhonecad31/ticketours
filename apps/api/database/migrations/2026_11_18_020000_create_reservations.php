<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', fn (Blueprint $t) => $t->unsignedSmallInteger('hold_minutes')->default(15));
        Schema::table('audit_events', fn (Blueprint $t) => $t->unsignedBigInteger('actor_id')->nullable()->change());
        Schema::table('inventory_sessions', fn (Blueprint $t) => $t->unique(['organization_id', 'id']));
        Schema::table('base_rate_versions', fn (Blueprint $t) => $t->unique(['organization_id', 'id']));
        Schema::table('product_policy_versions', fn (Blueprint $t) => $t->unique(['organization_id', 'id']));

        Schema::create('customers', function (Blueprint $t): void {
            $this->owned($t);
            $t->string('name', 200);
            $t->string('email', 254)->nullable();
        });

        Schema::create('quotes', function (Blueprint $t): void {
            $this->owned($t);
            $this->reference($t, 'variant_id', 'product_variants');
            $this->reference($t, 'rate_version_id', 'base_rate_versions');
            $this->reference($t, 'policy_version_id', 'product_policy_versions');
            $t->json('selection');
            $t->string('total', 42);
            $t->char('currency', 3);
            $t->timestampTz('expires_at');
        });

        Schema::create('holds', function (Blueprint $t): void {
            $this->owned($t);
            $this->reference($t, 'quote_id', 'quotes');
            $t->unique(['organization_id', 'quote_id']);
            $this->reference($t, 'session_id', 'inventory_sessions', nullable: true);
            $this->reference($t, 'resource_id', 'resources', nullable: true);
            $t->unsignedInteger('quantity');
            $t->timestampTz('start_at');
            $t->timestampTz('end_at');
            $t->timestampTz('expires_at');
            $t->enum('status', ['active', 'committed', 'released', 'expired']);
            $t->index(['organization_id', 'session_id', 'status', 'expires_at']);
            $t->index(['organization_id', 'resource_id', 'status', 'expires_at']);
            $t->index(['status', 'expires_at']);
        });

        Schema::create('reservations', function (Blueprint $t): void {
            $this->owned($t);
            $this->reference($t, 'hold_id', 'holds');
            $this->reference($t, 'customer_id', 'customers');
            $t->enum('status', ['held', 'confirmed', 'cancelled', 'expired']);
            $t->unique(['organization_id', 'hold_id']);
            $t->index(['organization_id', 'status', 'id']);
        });

        Schema::create('reservation_items', function (Blueprint $t): void {
            $this->owned($t);
            $this->reference($t, 'reservation_id', 'reservations');
            $this->reference($t, 'variant_id', 'product_variants');
            $this->reference($t, 'rate_version_id', 'base_rate_versions');
            $this->reference($t, 'policy_version_id', 'product_policy_versions');
            $t->json('selection');
            $t->json('participants');
            $t->string('total', 42);
            $t->char('currency', 3);
            $t->text('policy_terms');
            $t->unique(['organization_id', 'reservation_id']);
        });

        Schema::create('reservation_history', function (Blueprint $t): void {
            $this->owned($t);
            $this->reference($t, 'reservation_id', 'reservations');
            $t->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('status', 25);
            $t->string('reason', 500)->nullable();
            $t->uuid('correlation_id');
            $t->timestampTz('occurred_at');
            $t->index(['organization_id', 'reservation_id', 'occurred_at']);
        });

        Schema::create('idempotent_responses', function (Blueprint $t): void {
            $t->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('operation', 40);
            $t->string('request_key', 128);
            $t->char('fingerprint', 64);
            $t->json('response')->nullable();
            $t->primary(['organization_id', 'actor_id', 'operation', 'request_key'], 'idempotent_responses_pk');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
            DB::statement("ALTER TABLE holds ADD CONSTRAINT holds_resource_exclusion EXCLUDE USING gist (
                organization_id WITH =, resource_id WITH =, tstzrange(start_at, end_at, '[)') WITH &&
            ) WHERE (resource_id IS NOT NULL AND status IN ('active', 'committed'))");
            DB::statement('ALTER TABLE holds ADD CONSTRAINT holds_shape_check CHECK (
                (session_id IS NOT NULL) <> (resource_id IS NOT NULL) AND quantity > 0 AND start_at < end_at
            )');
        }
    }

    public function down(): void
    {
        foreach (['idempotent_responses', 'reservation_history', 'reservation_items', 'reservations', 'holds', 'quotes', 'customers'] as $table) {
            Schema::dropIfExists($table);
        }
        foreach (['inventory_sessions', 'base_rate_versions', 'product_policy_versions'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropUnique(['organization_id', 'id']));
        }
        Schema::table('organizations', fn (Blueprint $t) => $t->dropColumn('hold_minutes'));
        // Preserve nullable actors for retained system audit events.
    }

    private function owned(Blueprint $t): void
    {
        $t->uuid('id')->primary();
        $t->foreignUuid('organization_id')->constrained()->restrictOnDelete();
        $t->unique(['organization_id', 'id']);
    }

    private function reference(Blueprint $t, string $column, string $table, bool $nullable = false): void
    {
        $t->uuid($column)->nullable($nullable);
        $t->foreign(['organization_id', $column])->references(['organization_id', 'id'])->on($table)->restrictOnDelete();
    }
};
