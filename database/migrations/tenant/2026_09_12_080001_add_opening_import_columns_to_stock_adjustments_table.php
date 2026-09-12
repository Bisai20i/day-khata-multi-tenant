<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Two columns the opening-stock import needs, and nothing else uses.
     *
     * `is_opening_import` marks the single adjustment an opening-stock CSV
     * produced. Re-importing replaces that batch instead of stacking a
     * second set of opening quantities on top of the first (audit P1,
     * "Opening stock import stacks too"), and the replace has to be able to
     * find the previous batch reliably - matching on the free-text `note`
     * would break the moment anyone typed that note by hand.
     *
     * `journal_voucher_id` links the adjustment to the one ledger entry
     * opening stock does post (Dr AS11 Opening Stock / Cr Profit & Loss),
     * so replacing a batch can reverse exactly that voucher. Every other
     * stock document stays purely quantity-side and leaves this null - see
     * StockAdjustment's own docblock for the periodic-inventory rule.
     */
    public function up(): void
    {
        Schema::table('stock_adjustments', function (Blueprint $table) {
            $table->boolean('is_opening_import')->default(false)->after('status');
            $table->foreignId('journal_voucher_id')->nullable()->after('is_opening_import')->constrained()->nullOnDelete();
        });

        $this->backfill();
    }

    /**
     * Flags the adjustments earlier opening-stock imports created, so the
     * first re-import after this upgrade replaces them rather than stacking
     * on top. Before this migration the importer's only fingerprint was the
     * note it wrote, so that is what identifies them. `journal_voucher_id`
     * stays null for those rows: no ledger entry was ever posted for them,
     * and inventing one now would silently change a tenant's balance sheet.
     *
     * Idempotent: re-running only re-sets rows to the same flag.
     */
    private function backfill(): void
    {
        DB::table('stock_adjustments')
            ->where('note', 'Opening stock import')
            ->update(['is_opening_import' => true]);
    }

    public function down(): void
    {
        Schema::table('stock_adjustments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('journal_voucher_id');
            $table->dropColumn('is_opening_import');
        });
    }
};
