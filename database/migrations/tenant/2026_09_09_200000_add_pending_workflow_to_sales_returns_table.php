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
        // A SalesReturn::request() (see the model's own docblock) is a
        // 'pending' return that hasn't posted a journal voucher yet - it
        // only gets one once approve() actually posts it, or never (if
        // reject()ed instead). Every existing row is already 'posted' or
        // 'cancelled' and keeps a real journal_voucher_id, unaffected -
        // same nullable-FK pattern already used for
        // platform_admin_activity_logs.platform_admin_id.
        Schema::table('sales_returns', function (Blueprint $table) {
            $table->foreignId('journal_voucher_id')->nullable()->change();
            $table->text('rejection_reason')->nullable()->after('reason');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales_returns', function (Blueprint $table) {
            $table->dropColumn('rejection_reason');
            $table->foreignId('journal_voucher_id')->nullable(false)->change();
        });
    }
};
