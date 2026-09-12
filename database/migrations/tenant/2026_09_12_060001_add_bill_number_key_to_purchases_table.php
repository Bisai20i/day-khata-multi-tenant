<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stops the same supplier bill being entered twice.
 *
 * The rule is "at most one LIVE purchase per (supplier, bill number)": once a
 * purchase is cancelled its bill number must become enterable again. Neither
 * MySQL nor SQLite supports a partial unique index portably, so the condition
 * is carried in the data instead: `bill_number_key` holds the bill number while
 * the purchase is live and is nulled when it is cancelled. Both engines treat
 * NULLs in a unique index as distinct, so cancelled rows stop competing for the
 * number while live ones still collide.
 *
 * App\Models\Purchase::post() also checks this inside its transaction, holding
 * the supplier row with lockForUpdate(), so two concurrent posts get a readable
 * error rather than a raw constraint violation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->string('bill_number_key')->nullable()->after('bill_number');
        });

        $this->backfill();

        Schema::table('purchases', function (Blueprint $table) {
            $table->unique(['supplier_id', 'bill_number_key'], 'purchases_supplier_bill_number_unique');
        });
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropUnique('purchases_supplier_bill_number_unique');
            $table->dropColumn('bill_number_key');
        });
    }

    /**
     * Claims the key for the oldest live purchase of each (supplier, bill
     * number) pair and leaves every later duplicate null, so an existing
     * double-entered bill does not block the unique index from being created.
     * Those rows stay visible and unchanged apart from the new column; a tenant
     * that wants them reconciled cancels the duplicate by hand.
     */
    private function backfill(): void
    {
        $claimed = [];

        $purchases = DB::table('purchases')
            ->whereNotNull('bill_number')
            ->where('bill_number', '!=', '')
            ->where('status', '!=', 'cancelled')
            ->orderBy('id')
            ->get(['id', 'supplier_id', 'bill_number']);

        foreach ($purchases as $purchase) {
            $key = $purchase->supplier_id.'|'.$purchase->bill_number;

            if (isset($claimed[$key])) {
                continue;
            }

            $claimed[$key] = true;

            DB::table('purchases')->where('id', $purchase->id)->update([
                'bill_number_key' => $purchase->bill_number,
            ]);
        }
    }
};
