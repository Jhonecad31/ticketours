<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->string('name', 200);
            $table->enum('status', ['draft', 'review', 'published', 'suspended', 'archived']);
            $table->unique(['organization_id', 'id']);
            $table->index(['organization_id', 'status', 'id']);
        });
        Schema::create('product_variants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('product_id');
            $table->string('name', 200);
            $table->enum('modality', ['session', 'exclusive_resource']);
            $table->unique(['organization_id', 'id']);
            $table->index(['organization_id', 'product_id', 'id']);
            $table->foreign(['organization_id', 'product_id'])->references(['organization_id', 'id'])->on('products')->restrictOnDelete();
        });
        Schema::create('inventory_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('variant_id');
            $table->timestampTz('start_at');
            $table->timestampTz('end_at');
            $table->integer('capacity');
            $table->index(['organization_id', 'variant_id', 'start_at']);
            $table->foreign(['organization_id', 'variant_id'])->references(['organization_id', 'id'])->on('product_variants')->restrictOnDelete();
        });
        Schema::create('resources', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->string('name', 200);
            $table->unique(['organization_id', 'id']);
        });
        Schema::create('resource_windows', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('resource_id');
            $table->uuid('variant_id');
            $table->timestampTz('start_at');
            $table->timestampTz('end_at');
            $table->index(['organization_id', 'variant_id', 'start_at']);
            $table->foreign(['organization_id', 'resource_id'])->references(['organization_id', 'id'])->on('resources')->restrictOnDelete();
            $table->foreign(['organization_id', 'variant_id'])->references(['organization_id', 'id'])->on('product_variants')->restrictOnDelete();
        });
        Schema::create('resource_blocks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('resource_id');
            $table->timestampTz('start_at');
            $table->timestampTz('end_at');
            $table->index(['organization_id', 'resource_id', 'start_at']);
            $table->foreign(['organization_id', 'resource_id'])->references(['organization_id', 'id'])->on('resources')->restrictOnDelete();
        });
        Schema::create('base_rate_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('variant_id');
            $table->integer('version');
            $table->string('amount', 31);
            $table->char('currency', 3);
            $table->unique(['organization_id', 'variant_id', 'version']);
            $table->foreign(['organization_id', 'variant_id'])->references(['organization_id', 'id'])->on('product_variants')->restrictOnDelete();
        });
        Schema::create('product_policy_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('product_id');
            $table->integer('version');
            $table->text('terms');
            $table->unique(['organization_id', 'product_id', 'version']);
            $table->foreign(['organization_id', 'product_id'])->references(['organization_id', 'id'])->on('products')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_policy_versions');
        Schema::dropIfExists('base_rate_versions');
        Schema::dropIfExists('resource_blocks');
        Schema::dropIfExists('resource_windows');
        Schema::dropIfExists('resources');
        Schema::dropIfExists('inventory_sessions');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('products');
    }
};
