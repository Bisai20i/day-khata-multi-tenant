<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bonus / free quantity on a sale line (audit section 3 "Sales"): the
 * business hands over extra pieces at no charge alongside the paid quantity
 * - "buy 10 get 1 free". Stock moves `quantity + bonus_quantity` (see
 * Sale::post()); the money side is unaffected, DocumentCalculator never sees
 * the bonus quantity, so revenue and VAT are exactly what the paid quantity
 * would have produced on its own.
 *
 * `sale_return_lines.bonus_quantity` is the bonus portion of that particular
 * return line: a customer may hand back bonus units alongside (or instead
 * of) paid ones, and those credit zero rupees but still restock (C6's
 * money-component rule only ever applies to the paid `quantity`).
 *
 * Both default to 0.0000 (not nullable) so every existing row reads back as
 * "no bonus", identical to today's behaviour - matches the same convention
 * T13 used for the purchase side in the same phase (see
 * 2026_09_14_130020_add_bonus_quantity_to_purchase_lines_tables.php).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_lines', function (Blueprint $table) {
            $table->decimal('bonus_quantity', 15, 4)->default(0)->after('quantity');
        });

        Schema::table('sale_return_lines', function (Blueprint $table) {
            $table->decimal('bonus_quantity', 15, 4)->default(0)->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('sale_lines', function (Blueprint $table) {
            $table->dropColumn('bonus_quantity');
        });

        Schema::table('sale_return_lines', function (Blueprint $table) {
            $table->dropColumn('bonus_quantity');
        });
    }
};
