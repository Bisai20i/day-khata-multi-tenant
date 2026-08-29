<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sales_returns', function (Blueprint $table) {
            $table->string('status')->default('posted');
            $table->foreignId('refund_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('refund_journal_voucher_id')->nullable()->constrained('journal_vouchers')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales_returns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('refund_journal_voucher_id');
            $table->dropConstrainedForeignId('refund_account_id');
            $table->dropColumn('status');
        });
    }
};
