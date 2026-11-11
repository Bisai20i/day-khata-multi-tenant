<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-branch (plan 12, BR20, section 5.8): per-fiscal-year numbering scope.
 *
 * - fiscal_years.numbering_scope: `shared` (every existing year, today's one
 *   company-wide series per voucher type) or `per_branch` (one series per
 *   voucher type and header branch). Default `shared`, so nothing changes
 *   for an existing year.
 * - journal_vouchers.series_branch_id and voucher_sequences.series_branch_id:
 *   unsigned big integer NOT NULL default 0, no foreign key; 0 is the shared
 *   series, otherwise the branch whose series the number came from. The
 *   unique keys gain the column: a per_branch year has a number 1 in every
 *   branch (invariant I7 is per (fiscal year, type, series_branch_id)).
 * - material_issue_notes and hr_payroll_runs store the bare voucher number in
 *   a unique (fiscal_year_id, number) key (BR08 carry-forward F3), so they get
 *   the same column and the same widened key.
 *
 * Every unique key here leads with fiscal_year_id, which is also a foreign
 * key. MySQL needs an index starting with that column at every moment, so the
 * new key is created BEFORE the old one is dropped, in separate statements
 * (.ai/rules/migrations.md trap 3); down() restores the old key first for the
 * same reason. Existing rows need no backfill: the NOT NULL default 0 puts
 * them in the shared series, which is exactly where they were numbered.
 *
 * Idempotent: every column and index is only added or dropped when the
 * schema says it is still needed, so a re-run or a half-finished run
 * completes cleanly. Works on SQLite and MySQL (plain columns and indexes).
 */
return new class extends Migration
{
    /**
     * table => [old unique name, old columns, new unique name, new columns].
     * Names are explicit and short (MySQL caps identifiers at 64 characters);
     * the voucher_sequences key was created without a name, so its old name
     * is Laravel's generated one.
     *
     * @var array<string, array{0: string, 1: list<string>, 2: string, 3: list<string>}>
     */
    private array $keys = [
        'journal_vouchers' => [
            'journal_vouchers_fy_type_number_unique',
            ['fiscal_year_id', 'voucher_type', 'voucher_number'],
            'jv_fy_type_series_number_unique',
            ['fiscal_year_id', 'voucher_type', 'series_branch_id', 'voucher_number'],
        ],
        'voucher_sequences' => [
            'voucher_sequences_fiscal_year_id_voucher_type_unique',
            ['fiscal_year_id', 'voucher_type'],
            'voucher_seq_fy_type_series_unique',
            ['fiscal_year_id', 'voucher_type', 'series_branch_id'],
        ],
        'material_issue_notes' => [
            'mat_issue_notes_fy_number_unique',
            ['fiscal_year_id', 'number'],
            'mat_issue_notes_fy_series_number_unique',
            ['fiscal_year_id', 'series_branch_id', 'number'],
        ],
        'hr_payroll_runs' => [
            'hr_runs_year_number_unique',
            ['fiscal_year_id', 'run_number'],
            'hr_runs_year_series_number_unique',
            ['fiscal_year_id', 'series_branch_id', 'run_number'],
        ],
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('fiscal_years', 'numbering_scope')) {
            Schema::table('fiscal_years', function (Blueprint $table) {
                $table->string('numbering_scope', 20)->default('shared');
            });
        }

        // NOT NULL with a default already fills existing rows; this only
        // repairs a value a half-applied run or a manual edit left behind.
        DB::table('fiscal_years')
            ->where(fn ($query) => $query->whereNull('numbering_scope')->orWhereNotIn('numbering_scope', ['shared', 'per_branch']))
            ->update(['numbering_scope' => 'shared']);

        foreach ($this->keys as $table => [$oldName, $oldColumns, $newName, $newColumns]) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            if (! Schema::hasColumn($table, 'series_branch_id')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->unsignedBigInteger('series_branch_id')->default(0);
                });
            }

            if ($this->uniqueIndexName($table, $newName, $newColumns) === null) {
                Schema::table($table, function (Blueprint $blueprint) use ($newColumns, $newName) {
                    $blueprint->unique($newColumns, $newName);
                });
            }

            $existingOld = $this->uniqueIndexName($table, $oldName, $oldColumns);

            if ($existingOld !== null) {
                Schema::table($table, function (Blueprint $blueprint) use ($existingOld) {
                    $blueprint->dropUnique($existingOld);
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     *
     * The old keys cannot hold per-branch numbers: two branches that each
     * issued number 1 in a per_branch year would collide on
     * (fiscal_year_id, voucher_type, voucher_number). Renumbering a legally
     * issued series is not something a rollback may do, so down() refuses
     * while any row sits in a branch series and changes nothing.
     */
    public function down(): void
    {
        foreach (array_keys($this->keys) as $table) {
            if (Schema::hasTable($table)
                && Schema::hasColumn($table, 'series_branch_id')
                && DB::table($table)->where('series_branch_id', '<>', 0)->exists()) {
                throw new RuntimeException(
                    "Cannot roll back per-branch numbering: {$table} has rows numbered in a per-branch series. Those numbers would collide under the old shared unique key."
                );
            }
        }

        foreach ($this->keys as $table => [$oldName, $oldColumns, $newName, $newColumns]) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            // Old key first, so fiscal_year_id keeps an index on MySQL while
            // the new one is dropped.
            if ($this->uniqueIndexName($table, $oldName, $oldColumns) === null) {
                Schema::table($table, function (Blueprint $blueprint) use ($oldColumns, $oldName) {
                    $blueprint->unique($oldColumns, $oldName);
                });
            }

            $existingNew = $this->uniqueIndexName($table, $newName, $newColumns);

            if ($existingNew !== null) {
                Schema::table($table, function (Blueprint $blueprint) use ($existingNew) {
                    $blueprint->dropUnique($existingNew);
                });
            }

            if (Schema::hasColumn($table, 'series_branch_id')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->dropColumn('series_branch_id');
                });
            }
        }

        if (Schema::hasColumn('fiscal_years', 'numbering_scope')) {
            Schema::table('fiscal_years', function (Blueprint $table) {
                $table->dropColumn('numbering_scope');
            });
        }
    }

    /**
     * The actual name of the unique index on exactly $columns: $expected when
     * it exists, else whatever name an older schema gave the same key (the
     * voucher_sequences key was created unnamed). Null when there is none.
     *
     * @param  list<string>  $columns
     */
    private function uniqueIndexName(string $table, string $expected, array $columns): ?string
    {
        foreach (Schema::getIndexes($table) as $index) {
            if ($index['name'] === $expected) {
                return $index['name'];
            }
        }

        foreach (Schema::getIndexes($table) as $index) {
            if ($index['unique'] && ! $index['primary'] && $index['columns'] === $columns) {
                return $index['name'];
            }
        }

        return null;
    }
};
