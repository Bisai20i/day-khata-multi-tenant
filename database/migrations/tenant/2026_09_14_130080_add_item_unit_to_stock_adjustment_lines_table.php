<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alternate-unit entry for stock adjustments (audit section 4 polish): a
 * clerk can now count "2 Box" instead of converting to 24 pieces by hand.
 * `quantity` keeps meaning "as entered" (mirrors purchase_lines.quantity);
 * `unit_conversion_factor` (default 1, meaning the item's own base unit) is
 * what StockAdjustment::post() multiplies by before it ever reaches
 * Item::recordStockMovement(), which - like every other document in this
 * app - only ever records a BASE-unit quantity (CONTRACTS C10).
 * `unit_cost_rate`/`line_value` are unaffected: cost stays entered per base
 * unit, same as before this column existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_adjustment_lines', function (Blueprint $table) {
            $table->foreignId('item_unit_id')->nullable()->after('item_id')->constrained('item_units')->nullOnDelete();
            $table->decimal('unit_conversion_factor', 15, 4)->default(1)->after('item_unit_id');
        });
    }

    public function down(): void
    {
        Schema::table('stock_adjustment_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('item_unit_id');
            $table->dropColumn('unit_conversion_factor');
        });
    }
};
