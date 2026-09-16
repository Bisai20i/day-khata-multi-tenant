<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two independent, additive purchase-header facts (audit section 3
 * "Purchase"):
 *
 * - `tds_rate`: the percentage the clerk picks, so `Purchase::post()` can
 *   compute `tds_amount = (taxable + nontaxable) x rate` itself instead of
 *   trusting a free-typed amount. Null on every existing row and on any new
 *   purchase that still types the amount directly (backward compatible).
 * - `force_non_taxable`: the PAN / non-VAT purchase toggle. When true,
 *   DocumentCalculator is told `force_non_taxable`, so every line lands in
 *   the exempt column of the Purchase VAT book, matching a PAN bill that
 *   carries no VAT at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->decimal('tds_rate', 5, 2)->nullable()->after('tds_account_id');
            $table->boolean('force_non_taxable')->default(false)->after('vat_rate');
        });
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropColumn(['tds_rate', 'force_non_taxable']);
        });
    }
};
