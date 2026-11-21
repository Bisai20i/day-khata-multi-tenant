<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tags every ledger line with an optional project.
 *
 * LOCK RISK: journal_voucher_lines is the largest table in a tenant database.
 * The column is nullable and is deliberately never backfilled: existing lines
 * stay NULL (no project). Rehearse this migration and its rollback on a copy of
 * the biggest tenant before shipping. On MySQL/MariaDB the foreign key is added
 * with FOREIGN_KEY_CHECKS=0 so InnoDB skips the validation scan, which is safe
 * because every existing row holds NULL.
 *
 * The explicit short index name doubles as the foreign key's own index.
 */
return new class extends Migration
{
    private const TABLE = 'journal_voucher_lines';

    private const PROJECT_INDEX = 'jvl_project_idx';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $hasColumn = Schema::hasColumn(self::TABLE, 'project_id');
        $hasIndex = $hasColumn && Schema::hasIndex(self::TABLE, self::PROJECT_INDEX);
        $hasForeign = $hasColumn && $this->hasProjectForeignKey();

        if ($hasColumn && $hasIndex && $hasForeign) {
            return;
        }

        $alter = function () use ($hasColumn, $hasIndex, $hasForeign) {
            Schema::table(self::TABLE, function (Blueprint $blueprint) use ($hasColumn, $hasIndex, $hasForeign) {
                if (! $hasColumn) {
                    $blueprint->unsignedBigInteger('project_id')->nullable();
                }

                // Index before the foreign key so MySQL reuses it for the FK.
                if (! $hasIndex) {
                    $blueprint->index('project_id', self::PROJECT_INDEX);
                }

                if (! $hasForeign) {
                    $blueprint->foreign('project_id')->references('id')->on('projects')->nullOnDelete();
                }
            });
        };

        // SQLite adds foreign keys by rebuilding the table and never validates
        // existing rows, so the switch only matters (and only runs) elsewhere.
        if (DB::connection()->getDriverName() === 'sqlite') {
            $alter();
        } else {
            Schema::withoutForeignKeyConstraints($alter);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn(self::TABLE, 'project_id')) {
            return;
        }

        // Separate statements, in this order: MySQL refuses to drop an index
        // its foreign key still needs, and SQLite rebuilds the table when a
        // foreign key is dropped.
        if ($this->hasProjectForeignKey()) {
            Schema::table(self::TABLE, function (Blueprint $blueprint) {
                $blueprint->dropForeign(['project_id']);
            });
        }

        if (Schema::hasIndex(self::TABLE, self::PROJECT_INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $blueprint) {
                $blueprint->dropIndex(self::PROJECT_INDEX);
            });
        }

        Schema::table(self::TABLE, function (Blueprint $blueprint) {
            $blueprint->dropColumn('project_id');
        });
    }

    private function hasProjectForeignKey(): bool
    {
        foreach (Schema::getForeignKeys(self::TABLE) as $foreignKey) {
            if ($foreignKey['columns'] === ['project_id'] && $foreignKey['foreign_table'] === 'projects') {
                return true;
            }
        }

        return false;
    }
};
