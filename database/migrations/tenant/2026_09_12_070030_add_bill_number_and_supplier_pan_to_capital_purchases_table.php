<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Purchase VAT book has to list the supplier's bill number and PAN against
 * every input-VAT claim, and a capital purchase claims input VAT on ASA23 just
 * like a stock purchase does (audit P0-20). Neither field existed here, so a
 * capital purchase could never appear in the book at all.
 *
 * `bill_number_guard` is how the same supplier bill is stopped from being
 * entered twice. A partial unique index (`... where status <> 'cancelled'`) is
 * not portable: SQLite supports it, MySQL does not. A plain unique index over
 * a nullable column is portable, and both engines ignore NULLs when enforcing
 * it - so the model writes "{supplier_id}|{bill_number}" while the purchase is
 * live and clears it on cancellation, which gives exactly the intended rule
 * (unique among non-cancelled purchases) with one ordinary index.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('capital_purchases', function (Blueprint $table) {
            $table->string('bill_number')->nullable()->after('type');
            $table->string('supplier_pan')->nullable()->after('supplier_id');
            $table->string('bill_number_guard')->nullable()->after('bill_number');

            $table->unique('bill_number_guard', 'capital_purchases_bill_number_guard_unique');
        });

        $this->backfillSupplierPan();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('capital_purchases', function (Blueprint $table) {
            $table->dropUnique('capital_purchases_bill_number_guard_unique');
            $table->dropColumn(['bill_number', 'supplier_pan', 'bill_number_guard']);
        });
    }

    /**
     * Snapshots the supplier's PAN onto purchases posted before the column
     * existed. Nothing to do for the bill number: it was never captured, so
     * there is no historical value to recover. Idempotent: only rows with no
     * snapshot yet are touched.
     */
    private function backfillSupplierPan(): void
    {
        // lazyById(), not each(): the update below takes each row back out of
        // the `supplier_pan is null` filter, and offset-based chunking would
        // then skip a whole page of purchases every time it advanced.
        foreach (DB::table('capital_purchases')->whereNull('supplier_pan')->whereNotNull('supplier_id')->lazyById() as $capitalPurchase) {
            $tpin = DB::table('suppliers')->where('id', $capitalPurchase->supplier_id)->value('tpin');

            if ($tpin === null || $tpin === '') {
                continue;
            }

            DB::table('capital_purchases')->where('id', $capitalPurchase->id)->update(['supplier_pan' => $tpin]);
        }
    }
};
