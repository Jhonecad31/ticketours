<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_policy_versions', fn (Blueprint $t) => $t->boolean('allows_partial_payment')->default(false));
        Schema::create('orders', function (Blueprint $t): void {
            $this->owned($t);
            $t->uuid('reservation_id');
            $t->foreign(['organization_id', 'reservation_id'])->references(['organization_id', 'id'])->on('reservations')->restrictOnDelete();
            $t->unique(['organization_id', 'reservation_id']);
            $t->string('total', 44);
            $t->char('currency', 3);
            $t->boolean('allows_partial_payment');
            $t->enum('status', ['open', 'completed', 'cancelled', 'expired']);
        });
        Schema::create('payments', function (Blueprint $t): void {
            $this->owned($t);
            $t->uuid('order_id');
            $t->foreign(['organization_id', 'order_id'])->references(['organization_id', 'id'])->on('orders')->restrictOnDelete();
            $t->index(['organization_id', 'order_id']);
            $t->string('amount', 44);
            $t->char('currency', 3);
            $t->uuid('simulator_reference')->unique();
            $t->timestampTz('recorded_at');
        });
        Schema::create('refunds', function (Blueprint $t): void {
            $this->owned($t);
            $t->uuid('payment_id');
            $t->foreign(['organization_id', 'payment_id'])->references(['organization_id', 'id'])->on('payments')->restrictOnDelete();
            $t->unique(['organization_id', 'payment_id']);
            $t->string('amount', 44);
            $t->char('currency', 3);
            $t->uuid('simulator_reference')->unique();
            $t->timestampTz('recorded_at');
        });
        Schema::create('vouchers', function (Blueprint $t): void {
            $this->owned($t);
            $t->uuid('order_id');
            $t->foreign(['organization_id', 'order_id'])->references(['organization_id', 'id'])->on('orders')->restrictOnDelete();
            $t->unique(['organization_id', 'order_id']);
            $t->timestampTz('issued_at');
        });

        DB::table('reservations')->select('reservations.*')->orderBy('id')->chunkById(200, function ($rows): void {
            foreach ($rows as $row) {
                $item = DB::table('reservation_items')->where(['organization_id' => $row->organization_id, 'reservation_id' => $row->id])->first();
                DB::table('orders')->insert([
                    'id' => (string) Str::uuid(), 'organization_id' => $row->organization_id, 'reservation_id' => $row->id,
                    'total' => $item->total, 'currency' => $item->currency, 'allows_partial_payment' => false,
                    'status' => match ($row->status) {
                        'confirmed' => 'completed', 'held' => 'open', default => $row->status
                    },
                ]);
            }
        });
    }

    public function down(): void
    {
        if (DB::table('payments')->exists() || DB::table('refunds')->exists() || DB::table('vouchers')->exists()) {
            throw new RuntimeException('Sales history must be preserved.');
        }

        foreach (['vouchers', 'refunds', 'payments', 'orders'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('product_policy_versions', fn (Blueprint $t) => $t->dropColumn('allows_partial_payment'));
    }

    private function owned(Blueprint $t): void
    {
        $t->uuid('id')->primary();
        $t->foreignUuid('organization_id')->constrained()->restrictOnDelete();
        $t->unique(['organization_id', 'id']);
    }
};
