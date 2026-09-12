<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contract C5's four cancellation columns for the two capital documents.
 *
 * Cancelling used to leave nothing behind but `status = 'cancelled'` and an
 * untraceable second voucher: who cancelled it, when, why, and which voucher
 * reversed it were all unrecoverable. These columns make a cancellation an
 * auditable fact, and `reversal_journal_voucher_id` is what lets the VAT and
 * TDS reports show the reversal in the period it was actually made instead of
 * making the original bill disappear from a month that has already been filed
 * (audit P0-20).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (['capital_sales', 'capital_purchases'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->timestamp('cancelled_at')->nullable()->after('status');
                $blueprint->foreignId('cancelled_by')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
                $blueprint->text('cancel_reason')->nullable()->after('cancelled_by');
                $blueprint->foreignId('reversal_journal_voucher_id')->nullable()->after('cancel_reason')
                    ->constrained('journal_vouchers')->restrictOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['capital_sales', 'capital_purchases'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropConstrainedForeignId('reversal_journal_voucher_id');
                $blueprint->dropConstrainedForeignId('cancelled_by');
                $blueprint->dropColumn(['cancelled_at', 'cancel_reason']);
            });
        }
    }
};
