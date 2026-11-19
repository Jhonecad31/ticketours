<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_entries', fn (Blueprint $t) => $t->unique(['provider_id', 'agency_id', 'currency', 'id'], 'commission_settlement_scope_key'));

        Schema::create('ticket_batches', function (Blueprint $t): void {
            $this->owned($t);
            $t->uuid('reservation_id');
            $t->unsignedInteger('quantity');
            $t->text('encrypted_secret');
            $t->unique(['organization_id', 'reservation_id']);
            $t->foreign(['organization_id', 'reservation_id'])->references(['organization_id', 'id'])->on('reservations')->restrictOnDelete();
        });
        Schema::create('ticket_checkins', function (Blueprint $t): void {
            $this->owned($t);
            $t->uuid('batch_id');
            $t->unsignedInteger('ticket_number');
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->timestampTz('recorded_at');
            $t->unique(['organization_id', 'batch_id', 'ticket_number']);
            $t->foreign(['organization_id', 'batch_id'])->references(['organization_id', 'id'])->on('ticket_batches')->restrictOnDelete();
            $t->index(['organization_id', 'recorded_at']);
        });
        Schema::create('checkin_validators', function (Blueprint $t): void {
            $t->uuid('organization_id');
            $t->foreignId('user_id');
            $t->primary(['organization_id', 'user_id']);
            $t->foreign(['organization_id', 'user_id'])->references(['organization_id', 'user_id'])->on('memberships')->restrictOnDelete();
        });

        Schema::create('settlements', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('provider_id')->constrained('organizations')->restrictOnDelete();
            $t->foreignUuid('agency_id')->constrained('organizations')->restrictOnDelete();
            $t->char('currency', 3);
            $t->text('total');
            $t->enum('status', ['reconciled', 'approved', 'paid']);
            $t->uuid('payment_reference')->nullable()->unique();
            $t->timestampTz('created_at');
            $t->timestampTz('approved_at')->nullable();
            $t->timestampTz('paid_at')->nullable();
            $t->unique(['provider_id', 'agency_id', 'currency', 'id'], 'settlements_parties_currency_key');
            $t->index(['provider_id', 'id']);
            $t->index(['agency_id', 'id']);
        });
        Schema::create('settlement_items', function (Blueprint $t): void {
            $t->uuid('commission_entry_id')->primary();
            $t->uuid('provider_id');
            $t->uuid('agency_id');
            $t->char('currency', 3);
            $t->uuid('settlement_id');
            $t->foreign(['provider_id', 'agency_id', 'currency', 'settlement_id'], 'settlement_items_settlement_fk')
                ->references(['provider_id', 'agency_id', 'currency', 'id'])->on('settlements')->restrictOnDelete();
            $t->foreign(['provider_id', 'agency_id', 'currency', 'commission_entry_id'], 'settlement_items_commission_fk')
                ->references(['provider_id', 'agency_id', 'currency', 'id'])->on('commission_entries')->restrictOnDelete();
            $t->index('settlement_id');
        });

        Schema::create('webhook_events', function (Blueprint $t): void {
            $this->owned($t);
            $t->uuid('external_id');
            $t->uuid('reservation_ref');
            $t->string('type', 50);
            $t->char('fingerprint', 64);
            $t->enum('status', ['pending', 'processed']);
            $t->unsignedInteger('attempts')->default(0);
            $t->timestampTz('processed_at')->nullable();
            $t->unique(['organization_id', 'external_id']);
        });
    }

    public function down(): void
    {
        foreach (['ticket_batches', 'ticket_checkins', 'settlements', 'webhook_events'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Operational history must be preserved.');
            }
        }
        foreach (['webhook_events', 'settlement_items', 'settlements', 'checkin_validators', 'ticket_checkins', 'ticket_batches'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('commission_entries', fn (Blueprint $t) => $t->dropUnique('commission_settlement_scope_key'));
    }

    private function owned(Blueprint $t): void
    {
        $t->uuid('id')->primary();
        $t->foreignUuid('organization_id')->constrained()->restrictOnDelete();
        $t->unique(['organization_id', 'id']);
    }
};
