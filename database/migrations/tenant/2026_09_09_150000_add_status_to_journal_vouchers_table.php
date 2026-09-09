<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds the same 'posted'/'cancelled' status column every other
     * transactional voucher table already has (see e.g. payments,
     * sales) - JournalVoucher previously had no cancel/reversal path at
     * all, so no status column existed yet.
     */
    public function up(): void
    {
        Schema::table('journal_vouchers', function (Blueprint $table) {
            $table->string('status')->default('posted')->after('reason');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('journal_vouchers', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
