<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ledger lines are immutable (audit JE-01): deleting a voucher must be
     * refused by the database, not silently cascade its lines away.
     */
    public function up(): void
    {
        Schema::table('journal_voucher_lines', function (Blueprint $table) {
            $table->dropForeign(['journal_voucher_id']);
            $table->foreign('journal_voucher_id')->references('id')->on('journal_vouchers')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('journal_voucher_lines', function (Blueprint $table) {
            $table->dropForeign(['journal_voucher_id']);
            $table->foreign('journal_voucher_id')->references('id')->on('journal_vouchers')->cascadeOnDelete();
        });
    }
};
