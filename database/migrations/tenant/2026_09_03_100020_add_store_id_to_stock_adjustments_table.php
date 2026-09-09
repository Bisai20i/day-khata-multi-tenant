<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Tenants provisioned before the stores feature already have
        // stock_adjustments rows, so the new NOT NULL column needs a
        // concrete default (SQLite rejects NOT NULL + NULL default on a
        // non-empty table). Backfill existing rows into a default store.
        $storeId = DB::table('stores')->value('id') ?? DB::table('stores')->insertGetId([
            'name' => 'Main Store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::table('stock_adjustments', function (Blueprint $table) use ($storeId) {
            $table->foreignId('store_id')->after('date')->default($storeId)->constrained()->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_adjustments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('store_id');
        });
    }
};
