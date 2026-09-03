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
        // provisioning.failed entries (Phase D, App\Listeners\
        // RecordProvisioningFailure) are recorded from a queue worker with
        // no authenticated platform admin - Auth::guard('platform')->id()
        // is null there, so the column that held every prior (human-
        // initiated) action's actor can no longer be required.
        Schema::table('platform_admin_activity_logs', function (Blueprint $table) {
            $table->foreignId('platform_admin_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('platform_admin_activity_logs', function (Blueprint $table) {
            $table->foreignId('platform_admin_id')->nullable(false)->change();
        });
    }
};
