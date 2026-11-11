<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Round Off is a signed adjustment (+ adds to the bill, - reduces it) held
     * separately from the discount and already INCLUDED in `total`, so every
     * report that reads `total` keeps reading the amount actually payable.
     *
     * The two company_settings columns let a tenant switch the feature on
     * (off by default, so nothing changes for existing tenants) and pick the
     * step the suggestion rounds to. The step is a short string from a fixed
     * list (CompanySetting::ROUND_OFF_PRECISIONS), not a money value.
     */
    public function up(): void
    {
        foreach (['sales', 'purchases'] as $tableName) {
            if (Schema::hasTable($tableName) && ! Schema::hasColumn($tableName, 'round_off')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->decimal('round_off', 15, 2)->default(0)->after('total');
                });
            }
        }

        Schema::table('company_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('company_settings', 'round_off_enabled')) {
                $table->boolean('round_off_enabled')->default(false);
            }

            if (! Schema::hasColumn('company_settings', 'round_off_precision')) {
                $table->string('round_off_precision', 8)->default('1');
            }
        });
    }

    public function down(): void
    {
        foreach (['sales', 'purchases'] as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'round_off')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->dropColumn('round_off');
                });
            }
        }

        foreach (['round_off_precision', 'round_off_enabled'] as $column) {
            if (Schema::hasColumn('company_settings', $column)) {
                Schema::table('company_settings', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
