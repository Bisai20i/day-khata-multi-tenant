<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier bill numbers restart every fiscal year, so "one live purchase per
 * (supplier, bill number)" must be scoped to the fiscal year the purchase was
 * posted into (audit PUR-01). The purchase row now records that year, taken
 * from its voucher, and the unique key becomes
 * (supplier_id, fiscal_year_id, bill_number_key).
 *
 * The new index is created BEFORE the old one is dropped: on MySQL the
 * supplier_id foreign key needs an index that starts with supplier_id at every
 * moment, and both indexes do.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->foreignId('fiscal_year_id')->nullable()->after('journal_voucher_id')
                ->constrained('fiscal_years')->nullOnDelete();
        });

        $this->backfill();

        Schema::table('purchases', function (Blueprint $table) {
            $table->unique(['supplier_id', 'fiscal_year_id', 'bill_number_key'], 'purchases_supplier_year_bill_number_unique');
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->dropUnique('purchases_supplier_bill_number_unique');
        });
    }

    public function down(): void
    {
        // The old key cannot be restored when two years already hold the same
        // live bill number for one supplier, so keep the earliest claim and
        // free the later ones (they stay visible, only the key is nulled).
        $claimed = [];

        foreach (DB::table('purchases')->whereNotNull('bill_number_key')->orderBy('id')->get(['id', 'supplier_id', 'bill_number_key']) as $purchase) {
            $key = $purchase->supplier_id.'|'.$purchase->bill_number_key;

            if (isset($claimed[$key])) {
                DB::table('purchases')->where('id', $purchase->id)->update(['bill_number_key' => null]);

                continue;
            }

            $claimed[$key] = true;
        }

        Schema::table('purchases', function (Blueprint $table) {
            $table->unique(['supplier_id', 'bill_number_key'], 'purchases_supplier_bill_number_unique');
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->dropUnique('purchases_supplier_year_bill_number_unique');
            $table->dropConstrainedForeignId('fiscal_year_id');
        });
    }

    /**
     * Idempotent: only rows still without a fiscal year are touched.
     */
    private function backfill(): void
    {
        $purchases = DB::table('purchases')
            ->whereNull('fiscal_year_id')
            ->whereNotNull('journal_voucher_id')
            ->orderBy('id')
            ->get(['id', 'journal_voucher_id']);

        foreach ($purchases as $purchase) {
            $fiscalYearId = DB::table('journal_vouchers')->where('id', $purchase->journal_voucher_id)->value('fiscal_year_id');

            if ($fiscalYearId !== null) {
                DB::table('purchases')->where('id', $purchase->id)->update(['fiscal_year_id' => $fiscalYearId]);
            }
        }
    }
};
