<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a sale, a sales return and a receipt name an optional project (cost
 * centre). The document posts it on every line of its journal voucher(s).
 *
 * Nullable and never backfilled: existing documents keep no project. Each
 * step is guarded, so a partly applied run can be re-run. The explicit short
 * index name doubles as the foreign key's own index on MySQL, which is why
 * down() drops the foreign key before the index.
 */
return new class extends Migration
{
    /**
     * Table name => index name (each well under 64 characters).
     *
     * @var array<string, string>
     */
    private const TABLES = [
        'sales' => 'sales_project_idx',
        'sales_returns' => 'sales_returns_project_idx',
        'receipts' => 'receipts_project_idx',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (self::TABLES as $table => $index) {
            $hasColumn = Schema::hasColumn($table, 'project_id');
            $hasIndex = $hasColumn && Schema::hasIndex($table, $index);
            $hasForeign = $hasColumn && $this->hasProjectForeignKey($table);

            if ($hasColumn && $hasIndex && $hasForeign) {
                continue;
            }

            $alter = function () use ($table, $hasColumn, $hasIndex, $hasForeign, $index) {
                Schema::table($table, function (Blueprint $blueprint) use ($hasColumn, $hasIndex, $hasForeign, $index) {
                    if (! $hasColumn) {
                        $blueprint->unsignedBigInteger('project_id')->nullable();
                    }

                    // Index before the foreign key so MySQL reuses it for the FK.
                    if (! $hasIndex) {
                        $blueprint->index('project_id', $index);
                    }

                    if (! $hasForeign) {
                        $blueprint->foreign('project_id')->references('id')->on('projects')->nullOnDelete();
                    }
                });
            };

            // The column is nullable and never backfilled, so every existing row
            // holds NULL and the foreign key needs no validation scan. MySQL skips
            // it with the checks off; SQLite rebuilds the table and never validates.
            if (DB::connection()->getDriverName() === 'sqlite') {
                $alter();
            } else {
                Schema::withoutForeignKeyConstraints($alter);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (self::TABLES as $table => $index) {
            if (! Schema::hasColumn($table, 'project_id')) {
                continue;
            }

            // Separate statements, in this order: MySQL refuses to drop an
            // index its foreign key still needs.
            if ($this->hasProjectForeignKey($table)) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->dropForeign(['project_id']);
                });
            }

            if (Schema::hasIndex($table, $index)) {
                Schema::table($table, function (Blueprint $blueprint) use ($index) {
                    $blueprint->dropIndex($index);
                });
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn('project_id');
            });
        }
    }

    private function hasProjectForeignKey(string $table): bool
    {
        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            if ($foreignKey['columns'] === ['project_id'] && $foreignKey['foreign_table'] === 'projects') {
                return true;
            }
        }

        return false;
    }
};
