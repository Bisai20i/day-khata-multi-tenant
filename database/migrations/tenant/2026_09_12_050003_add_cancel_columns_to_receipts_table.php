<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The shared cancellation columns every cancellable document carries
     * (CONTRACTS C5). A cancelled receipt used to record nothing but its
     * status: who cancelled it, when, why, and which voucher reversed it
     * all lived only in the narration of a voucher nobody linked back.
     */
    public function up(): void
    {
        Schema::table('receipts', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancel_reason')->nullable();
            $table->foreignId('reversal_journal_voucher_id')->nullable()->constrained('journal_vouchers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('receipts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reversal_journal_voucher_id');
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['cancelled_at', 'cancel_reason']);
        });
    }
};
