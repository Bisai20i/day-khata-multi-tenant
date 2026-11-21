<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Project dimension (ERP parity P3): capital purchases, their settlements and
 * capital sales carry an optional project_id, the document-level project
 * every journal line they post inherits. Nullable and never backfilled:
 * existing documents stay untagged. The explicit short index name doubles as
 * the foreign key's own index on MySQL.
 */
return new class extends Migration
{
    /**
     * Tables that get the column, mapped to their project_id index name
     * (explicit and short, MySQL caps identifiers at 64 characters).
     *
     * @var array<string, string>
     */
    private array $tables = [
        'capital_purchases' => 'cap_purchases_project_idx',
        'capital_purchase_settlements' => 'cap_settlements_project_idx',
        'capital_sales' => 'cap_sales_project_idx',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach ($this->tables as $table => $indexName) {
            $hasColumn = Schema::hasColumn($table, 'project_id');
            $hasIndex = $hasColumn && Schema::hasIndex($table, $indexName);
            $hasForeign = $hasColumn && $this->hasProjectForeignKey($table);

            if ($hasColumn && $hasIndex && $hasForeign) {
                continue;
            }

            $alter = function () use ($table, $hasColumn, $hasIndex, $hasForeign, $indexName) {
                Schema::table($table, function (Blueprint $blueprint) use ($hasColumn, $hasIndex, $hasForeign, $indexName) {
                    if (! $hasColumn) {
                        $blueprint->unsignedBigInteger('project_id')->nullable();
                    }

                    // Index before the foreign key so MySQL reuses it for the FK.
                    if (! $hasIndex) {
                        $blueprint->index('project_id', $indexName);
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
        foreach ($this->tables as $table => $indexName) {
            if (! Schema::hasColumn($table, 'project_id')) {
                continue;
            }

            // Separate statements, in this order: MySQL refuses to drop an
            // index its foreign key still needs, and SQLite rebuilds the whole
            // table when a foreign key is dropped.
            if ($this->hasProjectForeignKey($table)) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->dropForeign(['project_id']);
                });
            }

            if (Schema::hasIndex($table, $indexName)) {
                Schema::table($table, function (Blueprint $blueprint) use ($indexName) {
                    $blueprint->dropIndex($indexName);
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
