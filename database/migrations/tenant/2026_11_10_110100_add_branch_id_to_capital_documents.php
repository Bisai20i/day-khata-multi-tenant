<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-branch (plan 12, BR02): capital purchases and capital sales carry a
 * NOT NULL branch_id. Existing documents all belong to the Main branch, which
 * BR01 inserts before this migration runs. The column default is Main's id so
 * code that does not yet set a branch keeps working, and the composite
 * (branch_id, date) index serves the branch-filtered, date-sorted list pages
 * (and doubles as the foreign key's own index, so MySQL does not add another).
 */
return new class extends Migration
{
    /**
     * Tables that get the column, mapped to their (branch_id, date) index name.
     * Names are explicit and short (MySQL caps identifiers at 64 characters).
     *
     * @var array<string, string>
     */
    private array $tables = [
        'capital_purchases' => 'cap_purchases_branch_date_idx',
        'capital_sales' => 'cap_sales_branch_date_idx',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $mainId = $this->mainBranchId();

        foreach ($this->tables as $table => $indexName) {
            $hasColumn = Schema::hasColumn($table, 'branch_id');
            $hasIndex = $hasColumn && Schema::hasIndex($table, $indexName);
            $hasForeign = $hasColumn && $this->hasBranchForeignKey($table);

            if (! $hasColumn || ! $hasIndex || ! $hasForeign) {
                Schema::table($table, function (Blueprint $blueprint) use ($hasColumn, $hasIndex, $hasForeign, $mainId, $indexName) {
                    if (! $hasColumn) {
                        $blueprint->unsignedBigInteger('branch_id')->default($mainId);
                    }

                    // Index before the foreign key so MySQL reuses it for the FK.
                    if (! $hasIndex) {
                        $blueprint->index(['branch_id', 'date'], $indexName);
                    }

                    if (! $hasForeign) {
                        $blueprint->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
                    }
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach ($this->tables as $table => $indexName) {
            if (! Schema::hasColumn($table, 'branch_id')) {
                continue;
            }

            // Separate statements, in this order: MySQL refuses to drop an
            // index its foreign key still needs, and SQLite rebuilds the whole
            // table when a foreign key is dropped.
            if ($this->hasBranchForeignKey($table)) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->dropForeign(['branch_id']);
                });
            }

            if (Schema::hasIndex($table, $indexName)) {
                Schema::table($table, function (Blueprint $blueprint) use ($indexName) {
                    $blueprint->dropIndex($indexName);
                });
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn('branch_id');
            });
        }
    }

    /**
     * The Main branch id; BR01 creates it, this migration never does.
     */
    private function mainBranchId(): int
    {
        if (! Schema::hasTable('branches')) {
            throw new RuntimeException('The branches table is missing. Run the branches migrations (2026_11_10_100000 to 100003) before adding branch_id to documents.');
        }

        $mainId = DB::table('branches')->where('is_main', 1)->value('id');

        if ($mainId === null) {
            throw new RuntimeException('No Main branch exists. The branches migrations must insert it before branch_id is added to documents.');
        }

        return (int) $mainId;
    }

    private function hasBranchForeignKey(string $table): bool
    {
        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            if ($foreignKey['columns'] === ['branch_id'] && $foreignKey['foreign_table'] === 'branches') {
                return true;
            }
        }

        return false;
    }
};
