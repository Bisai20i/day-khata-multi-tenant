<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A per-unit barcode - e.g. an item's "Box of 12" carries its own printed
 * barcode distinct from the base unit's. Scanning it (Sales/Create.vue,
 * Pos.vue, Purchases/Create.vue - see T12's cross-file wiring) selects both
 * the item AND this specific unit, instead of only ever matching the item's
 * own `barcode` column at the base unit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('item_units', function (Blueprint $table) {
            $table->string('barcode', 100)->nullable()->unique()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('item_units', function (Blueprint $table) {
            $table->dropUnique(['barcode']);
            $table->dropColumn('barcode');
        });
    }
};
