<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Frozen list of every non-core module key as of the entitlements
     * rollout. Deliberately not read from config/permissions.php: config
     * changes later, and this backfill must keep meaning "everything that
     * existed at the time" so existing tenants lose no feature.
     *
     * @var array<int, string>
     */
    private const ALL_MODULES_AT_ROLLOUT = [
        'sales', 'pos', 'agents', 'quotations', 'purchases',
        'inventory', 'accounting', 'reports', 'admin',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('tenants', 'enabled_modules')) {
            Schema::table('tenants', function (Blueprint $table) {
                $table->json('enabled_modules')->nullable();
            });
        }

        DB::table('tenants')
            ->whereNull('enabled_modules')
            ->update(['enabled_modules' => json_encode(self::ALL_MODULES_AT_ROLLOUT)]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('tenants', 'enabled_modules')) {
            Schema::table('tenants', function (Blueprint $table) {
                $table->dropColumn('enabled_modules');
            });
        }
    }
};
