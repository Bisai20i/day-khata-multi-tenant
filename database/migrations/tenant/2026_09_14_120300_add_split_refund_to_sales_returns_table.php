<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Split cash+bank refund on a sales return (audit section 4 polish, "split
 * cash+bank refund"), for both a linked and an unlinked return.
 *
 * `refund_account_id` (existing) keeps meaning exactly what it always has:
 * the bank account a refund's bank leg pays out of. The two new columns
 * carry the split itself - `refund_cash_amount` from the seeded cash account
 * (`AS1`), `refund_bank_amount` from `refund_account_id` - and
 * SalesReturn::postRefund() requires them to add up to the return's own
 * customer credit exactly (DocumentCalculator::assertExactSplit(), CONTRACTS
 * C3). A row that only ever set `refund_account_id` (every return posted
 * before this migration) keeps refunding its full customer credit through
 * that single account unchanged - the new columns are additive, not a
 * replacement, so no backfill is needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_returns', function (Blueprint $table) {
            $table->decimal('refund_cash_amount', 15, 2)->nullable()->after('refund_account_id');
            $table->decimal('refund_bank_amount', 15, 2)->nullable()->after('refund_cash_amount');
        });
    }

    public function down(): void
    {
        Schema::table('sales_returns', function (Blueprint $table) {
            $table->dropColumn(['refund_cash_amount', 'refund_bank_amount']);
        });
    }
};
