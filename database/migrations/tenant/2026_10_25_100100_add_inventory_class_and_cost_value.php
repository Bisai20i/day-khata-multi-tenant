<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hybrid perpetual inventory (manufacturing MC1, MC3).
 *
 * - `items.inventory_class`: trading | raw_material | finished_good. NOT
 *   NULL with a default of 'trading', so every existing row is backfilled by
 *   the column default itself and keeps behaving exactly as before.
 * - `item_stock_movements.cost_value`: the cost a perpetual item's movement
 *   added to / removed from its value balance. Nullable on purpose: it is
 *   null for every trading item and for every movement written before this
 *   migration, which is correct because none of those is perpetual.
 *
 * Idempotent through hasColumn guards.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('items', 'inventory_class')) {
            Schema::table('items', function (Blueprint $table) {
                $table->string('inventory_class', 20)->default('trading')->index('items_inventory_class_index');
            });
        }

        if (! Schema::hasColumn('item_stock_movements', 'cost_value')) {
            Schema::table('item_stock_movements', function (Blueprint $table) {
                $table->decimal('cost_value', 15, 2)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('item_stock_movements', 'cost_value')) {
            Schema::table('item_stock_movements', function (Blueprint $table) {
                $table->dropColumn('cost_value');
            });
        }

        if (Schema::hasColumn('items', 'inventory_class')) {
            Schema::table('items', function (Blueprint $table) {
                $table->dropIndex('items_inventory_class_index');
            });

            Schema::table('items', function (Blueprint $table) {
                $table->dropColumn('inventory_class');
            });
        }
    }
};
