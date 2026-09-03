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
        Schema::table('tenants', function (Blueprint $table) {
            // Set by TenantController::suspend(), cleared by resume(). Grace
            // period end is computed at read time from this plus
            // platform_settings.default_grace_period_days - not stored, so it
            // can never drift out of sync with the settings value.
            $table->timestamp('suspended_at')->nullable()->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('suspended_at');
        });
    }
};
