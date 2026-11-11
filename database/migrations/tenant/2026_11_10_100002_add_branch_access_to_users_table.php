<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Every existing user keeps access to all branches (they were never
     * branch scoped) and gets Main as their default branch. The column and
     * the foreign key are checked separately so a half-applied run is
     * completed rather than skipped.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'all_branches')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('all_branches')->default(true)->after('store_id');
            });
        }

        $hasColumn = Schema::hasColumn('users', 'default_branch_id');
        $hasForeign = $hasColumn && $this->hasDefaultBranchForeignKey();

        if (! $hasColumn || ! $hasForeign) {
            Schema::table('users', function (Blueprint $table) use ($hasColumn, $hasForeign) {
                if (! $hasColumn) {
                    $table->unsignedBigInteger('default_branch_id')->nullable()->after('all_branches');
                }

                if (! $hasForeign) {
                    $table->foreign('default_branch_id')->references('id')->on('branches')->nullOnDelete();
                }
            });
        }

        $mainId = DB::table('branches')->where('is_main', true)->value('id');

        if ($mainId !== null) {
            DB::table('users')->whereNull('default_branch_id')->update(['default_branch_id' => $mainId]);
        }
    }

    /**
     * Reverse the migrations.
     *
     * Separate statements, in this order: the foreign key goes first so the
     * column can be dropped without a dangling constraint, and SQLite
     * rebuilds the table when a foreign key is dropped.
     */
    public function down(): void
    {
        if (Schema::hasColumn('users', 'default_branch_id')) {
            if ($this->hasDefaultBranchForeignKey()) {
                Schema::table('users', function (Blueprint $table) {
                    $table->dropForeign(['default_branch_id']);
                });
            }

            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('default_branch_id');
            });
        }

        if (Schema::hasColumn('users', 'all_branches')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('all_branches');
            });
        }
    }

    private function hasDefaultBranchForeignKey(): bool
    {
        foreach (Schema::getForeignKeys('users') as $foreignKey) {
            if ($foreignKey['columns'] === ['default_branch_id'] && $foreignKey['foreign_table'] === 'branches') {
                return true;
            }
        }

        return false;
    }
};
