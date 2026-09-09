<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * An item's alternate units of sale/purchase - e.g. an item whose base
     * `unit` is "pcs" can also be bought/sold by the "Box" (conversion_factor
     * 12) or "Dozen" (conversion_factor 12) without a separate Item row per
     * unit. Purely additive: an item with zero rows here behaves exactly as
     * it always has (see Item::currentStock()/Sale::post()/Purchase::post(),
     * which treat a missing item_unit_id as the base unit with a conversion
     * factor of 1). purchase_rate/sale_rate/mrp are nullable overrides -
     * null means "fall back to the item's own rate", matching legacy
     * day_khata's InventorysettingDetails (altUnits/equals/buyrate/sellrate/
     * mrp) without carrying over its per-unit barcode/discount% columns,
     * which nothing in this rewrite's scope reads yet.
     */
    public function up(): void
    {
        Schema::create('item_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // How many of the item's base `unit` one of this alt unit equals
            // - e.g. 12 for a "Box" of a "pcs"-based item. Never zero/negative
            // (enforced by ItemController's validation), so it's always safe
            // to multiply or divide by.
            $table->decimal('conversion_factor', 15, 4);
            $table->decimal('purchase_rate', 15, 4)->nullable();
            $table->decimal('sale_rate', 15, 4)->nullable();
            $table->decimal('mrp', 15, 4)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['item_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('item_units');
    }
};
