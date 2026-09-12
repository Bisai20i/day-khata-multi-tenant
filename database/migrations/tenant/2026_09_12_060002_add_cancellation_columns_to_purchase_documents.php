<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The CONTRACTS C5 cancellation trail for the three purchase-side documents.
 *
 * Until now a cancelled document only flipped `status`: who cancelled it, when
 * and why were nowhere, and the reversing voucher could only be found by
 * reading narrations. All four columns are nullable because every existing row
 * predates the trail and a live document legitimately has none of them.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const TABLES = ['purchases', 'purchase_returns', 'payments'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->timestamp('cancelled_at')->nullable();
                $blueprint->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
                $blueprint->text('cancel_reason')->nullable();
                $blueprint->foreignId('reversal_journal_voucher_id')->nullable()
                    ->constrained('journal_vouchers')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropConstrainedForeignId('cancelled_by');
                $blueprint->dropConstrainedForeignId('reversal_journal_voucher_id');
                $blueprint->dropColumn(['cancelled_at', 'cancel_reason']);
            });
        }
    }
};
