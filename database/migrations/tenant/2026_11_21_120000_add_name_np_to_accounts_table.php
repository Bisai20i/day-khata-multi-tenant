<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nepali display names for the chart of accounts (todo/erp-parity, chunk S2).
 * A nullable name_np beside every table that carries a printed `name`: heads,
 * groups, subgroups and accounts. Statements fall back to `name` when it is
 * null. Purely additive; guarded so a re-run is a no-op.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private const TABLES = ['account_heads', 'account_groups', 'account_subgroups', 'accounts'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (self::TABLES as $tableName) {
            if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'name_np')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table): void {
                $table->string('name_np')->nullable()->after('name');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (self::TABLES as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'name_np')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn('name_np');
            });
        }
    }
};
