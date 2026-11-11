<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-branch (plan 12, BR03): staff, payroll, staff advances and fixed assets
 * carry a NOT NULL branch_id. Staff and their payroll rows use the staff
 * member's home branch; everything that exists today belongs to Main, which
 * BR01 inserts before this migration runs.
 *
 * Two shapes live here, because the HR migration (2026_11_05_100000) already
 * declared a nullable, indexed, FK-less branch_id on four of the tables:
 *
 *  - $existing: hr_staff, hr_payroll_runs, hr_payroll_payments,
 *    hr_staff_advances. NULL rows are backfilled to Main and orphan ids (the
 *    FK-less column could hold any number) are remapped to Main, then the
 *    column is changed to NOT NULL with Main as its default and the foreign
 *    key is added. Remapping first means the foreign key can never fail on
 *    MySQL (error 1452) and leave the table half-migrated. down() restores
 *    the nullable, FK-less shape and keeps the column (the HR migration owns
 *    it).
 *  - $added: hr_payroll_lines, fixed_assets. The column, index and foreign key
 *    are new; down() removes all three.
 *
 * Index names are explicit and short (MySQL caps identifiers at 64 characters).
 */
return new class extends Migration
{
    /**
     * Tables whose branch_id column already exists (nullable, indexed, no FK),
     * mapped to the index name used only if that index is somehow missing.
     *
     * @var array<string, string>
     */
    private array $existing = [
        'hr_staff' => 'hr_staff_branch_idx',
        'hr_payroll_runs' => 'hr_runs_branch_idx',
        'hr_payroll_payments' => 'hr_paypay_branch_idx',
        'hr_staff_advances' => 'hr_advances_branch_idx',
    ];

    /**
     * Tables that get a brand-new column, mapped to their index name.
     *
     * @var array<string, string>
     */
    private array $added = [
        'hr_payroll_lines' => 'hr_lines_branch_idx',
        'fixed_assets' => 'fixed_assets_branch_idx',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $mainId = $this->mainBranchId();

        foreach ([...$this->existing, ...$this->added] as $table => $indexName) {
            $column = collect(Schema::getColumns($table))->firstWhere('name', 'branch_id');

            if ($column === null) {
                Schema::table($table, function (Blueprint $blueprint) use ($mainId) {
                    $blueprint->unsignedBigInteger('branch_id')->default($mainId);
                });
            } else {
                // Backfill first: the NOT NULL change below must never see a
                // NULL, and the foreign key must never see an id with no branch.
                DB::table($table)->whereNull('branch_id')->update(['branch_id' => $mainId]);
                DB::table($table)
                    ->whereNotNull('branch_id')
                    ->whereNotIn('branch_id', DB::table('branches')->select('id'))
                    ->update(['branch_id' => $mainId]);

                if ($column['nullable']) {
                    Schema::table($table, function (Blueprint $blueprint) use ($mainId) {
                        $blueprint->unsignedBigInteger('branch_id')->default($mainId)->change();
                    });
                }
            }

            $hasIndex = Schema::hasIndex($table, ['branch_id']) || Schema::hasIndex($table, $indexName);
            $hasForeign = $this->hasBranchForeignKey($table);

            if (! $hasIndex || ! $hasForeign) {
                Schema::table($table, function (Blueprint $blueprint) use ($hasIndex, $hasForeign, $indexName) {
                    // Index before the foreign key so MySQL reuses it for the FK.
                    if (! $hasIndex) {
                        $blueprint->index('branch_id', $indexName);
                    }

                    if (! $hasForeign) {
                        $blueprint->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
                    }
                });
            }

            // Repairs rows from a half-applied run; a no-op once NOT NULL.
            DB::table($table)->whereNull('branch_id')->update(['branch_id' => $mainId]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Separate statements, in this order: MySQL refuses to drop an index
        // its foreign key still needs, and SQLite rebuilds the whole table
        // when a foreign key is dropped.
        foreach (array_keys($this->existing) as $table) {
            if (! Schema::hasColumn($table, 'branch_id')) {
                continue;
            }

            if ($this->hasBranchForeignKey($table)) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->dropForeign(['branch_id']);
                });
            }

            // Back to the HR migration's shape: nullable, no default, still indexed.
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->unsignedBigInteger('branch_id')->nullable()->change();
            });
        }

        foreach ($this->added as $table => $indexName) {
            if (! Schema::hasColumn($table, 'branch_id')) {
                continue;
            }

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
            throw new RuntimeException('The branches table is missing. Run the branches migrations (2026_11_10_100000 to 100003) before adding branch_id to staff and assets.');
        }

        $mainId = DB::table('branches')->where('is_main', 1)->value('id');

        if ($mainId === null) {
            throw new RuntimeException('No Main branch exists. The branches migrations must insert it before branch_id is added to staff and assets.');
        }

        return (int) $mainId;
    }

    private function hasBranchForeignKey(string $table): bool
    {
        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            if ($foreignKey['columns'] === ['branch_id']) {
                return true;
            }
        }

        return false;
    }
};
