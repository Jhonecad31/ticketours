<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('can_create_organizations')->default(false);
        });

        Schema::create('organizations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 200);
            $table->string('time_zone', 100);
            $table->timestampTz('created_at');
        });

        Schema::create('memberships', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->boolean('active');
            $table->timestampTz('created_at');
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'user_id']);
            $table->index(['user_id', 'active', 'organization_id']);
        });

        Schema::create('membership_roles', function (Blueprint $table): void {
            $table->uuid('organization_id');
            $table->uuid('membership_id');
            $table->enum('role', ['owner', 'admin', 'booking_agent']);
            $table->primary(['organization_id', 'membership_id', 'role']);
            $table->foreign(['organization_id', 'membership_id'])->references(['organization_id', 'id'])->on('memberships')->restrictOnDelete();
        });

        Schema::create('organization_invitations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->string('email', 254);
            $table->enum('role', ['admin', 'booking_agent']);
            $table->char('token_hash', 64)->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('created_at');
            $table->index(['organization_id', 'email', 'accepted_at', 'expires_at'], 'invitations_pending_lookup');
        });

        Schema::create('audit_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 100);
            $table->string('entity_type', 100);
            $table->string('entity_id', 128);
            $table->timestampTz('occurred_at');
            $table->uuid('correlation_id');
            $table->index(['organization_id', 'occurred_at', 'id']);
            $table->index('correlation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
        Schema::dropIfExists('organization_invitations');
        Schema::dropIfExists('membership_roles');
        Schema::dropIfExists('memberships');
        Schema::dropIfExists('organizations');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('can_create_organizations');
        });
    }
};
