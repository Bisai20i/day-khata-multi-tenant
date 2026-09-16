<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bonus / free quantity (audit section 3 "Purchase"): the supplier hands
 * over extra pieces at no charge alongside the paid quantity - "buy 10 get
 * 1 free". Stock records `quantity + bonus_quantity` (see Purchase::post()),
 * the money side is unaffected (DocumentCalculator never sees the bonus
 * quantity), so the average cost per base unit falls exactly as it should
 * when more units come in for the same rupees.
 *
 * `purchase_return_lines.bonus_quantity` is the free-goods portion of that
 * particular return line (PurchaseReturn::prepareLine() derives it, the
 * clerk never types it directly): the physical quantity returned that
 * PurchaseReturn::post() decided came out of the line's bonus allotment
 * rather than its paid one, and therefore credited nothing.
 *
 * Both default to 0.0000 (not nullable) so every existing row reads back
 * as "no bonus", identical to today's behaviour.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_lines', function (Blueprint $table) {
            $table->decimal('bonus_quantity', 15, 4)->default(0)->after('quantity');
        });

        Schema::table('purchase_return_lines', function (Blueprint $table) {
            $table->decimal('bonus_quantity', 15, 4)->default(0)->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_lines', function (Blueprint $table) {
            $table->dropColumn('bonus_quantity');
        });

        Schema::table('purchase_return_lines', function (Blueprint $table) {
            $table->dropColumn('bonus_quantity');
        });
    }
};
