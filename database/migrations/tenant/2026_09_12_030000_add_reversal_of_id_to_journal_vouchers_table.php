<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Links a Reversal voucher back to the voucher it reverses
     * (JournalVoucher::reverse()). Before this, a cancellation's reversal was
     * only findable by parsing its narration, so nothing could tell whether a
     * document had already been reversed - two cancel requests in flight at
     * once each posted their own mirror and reversed the ledger twice.
     *
     * Unique, not merely indexed: "reversed at most once" is the actual
     * invariant, and a unique index on a nullable column still allows any
     * number of NULLs on both SQLite and MySQL, so ordinary vouchers are
     * unaffected. Nullable with no backfill needed - every existing row is a
     * non-reversal and NULL is exactly right for it.
     */
    public function up(): void
    {
        Schema::table('journal_vouchers', function (Blueprint $table) {
            $table->foreignId('reversal_of_id')
                ->nullable()
                ->after('status')
                ->constrained('journal_vouchers')
                ->restrictOnDelete();

            $table->unique('reversal_of_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('journal_vouchers', function (Blueprint $table) {
            $table->dropUnique(['reversal_of_id']);
            $table->dropConstrainedForeignId('reversal_of_id');
        });
    }
};
