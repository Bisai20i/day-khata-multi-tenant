<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Every created_by FK in this app (journal_vouchers, sales, purchases,
     * stock_adjustments, sales_returns, purchase_returns) is
     * restrictOnDelete(), so a user who has ever posted anything can never
     * be hard-deleted. Deactivation is the only viable lifecycle action for
     * an employee who leaves - matches the "never silently delete
     * financial records" pattern already used for Customer/Supplier.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('role_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
