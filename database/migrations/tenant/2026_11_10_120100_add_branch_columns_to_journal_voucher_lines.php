<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-branch (plan 12, BR03): every ledger line carries a NOT NULL branch_id
 * (the source of truth for branch reports) and a nullable counterparty_branch_id
 * (set only on system "Inter-Branch Clearing" lines).
 *
 * LOCK RISK: journal_voucher_lines is by far the largest table in a tenant
 * database. Adding a NOT NULL column with a default, an index and a foreign key
 * can hold a metadata lock or rebuild the table for a long time on MySQL. Rehearse
 * this migration (and its rollback) on a copy of the biggest tenant, record the
 * timing in mem.md, and schedule a maintenance window if it is slow. The
 * migration is additive only and never rewrites rows: existing lines get Main
 * through the column default. Lines are immutable at the model layer
 * (JournalVoucherLine throws on update and delete), so no backfill is ever run
 * through Eloquent here.
 *
 * To keep the number of ALTERs down, both columns, the index and both foreign
 * keys go through one blueprint: MySQL then issues one ALTER for the two
 * columns, one for the index and one per foreign key. On MySQL/MariaDB the
 * foreign keys are added with FOREIGN_KEY_CHECKS=0, which lets InnoDB add them
 * in place without the full-table validation scan (ALGORITHM=COPY). That is
 * safe here because every existing row holds Main (branch_id, via the default)
 * or NULL (counterparty_branch_id), and Main exists. When rehearsing, time each
 * of those statements separately and confirm none fall back to a table copy.
 *
 * Index names are explicit and short (MySQL caps identifiers at 64 characters).
 * The (branch_id, account_id) index leads with branch_id so it doubles as that
 * foreign key's own index.
 */
return new class extends Migration
{
    private const TABLE = 'journal_voucher_lines';

    private const BRANCH_ACCOUNT_INDEX = 'jvl_branch_account_idx';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $mainId = $this->mainBranchId();

        $hasColumn = Schema::hasColumn(self::TABLE, 'branch_id');
        $hasIndex = $hasColumn && Schema::hasIndex(self::TABLE, self::BRANCH_ACCOUNT_INDEX);
        $hasForeign = $hasColumn && $this->hasBranchForeignKey('branch_id');
        $hasCounterparty = Schema::hasColumn(self::TABLE, 'counterparty_branch_id');
        $hasCounterpartyForeign = $hasCounterparty && $this->hasBranchForeignKey('counterparty_branch_id');

        if ($hasColumn && $hasIndex && $hasForeign && $hasCounterparty && $hasCounterpartyForeign) {
            return;
        }

        $alter = function () use ($hasColumn, $hasIndex, $hasForeign, $hasCounterparty, $hasCounterpartyForeign, $mainId) {
            Schema::table(self::TABLE, function (Blueprint $blueprint) use ($hasColumn, $hasIndex, $hasForeign, $hasCounterparty, $hasCounterpartyForeign, $mainId) {
                if (! $hasColumn) {
                    $blueprint->unsignedBigInteger('branch_id')->default($mainId);
                }

                if (! $hasCounterparty) {
                    $blueprint->unsignedBigInteger('counterparty_branch_id')->nullable();
                }

                // Index before the foreign key so MySQL reuses it for the FK.
                if (! $hasIndex) {
                    $blueprint->index(['branch_id', 'account_id'], self::BRANCH_ACCOUNT_INDEX);
                }

                if (! $hasForeign) {
                    $blueprint->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
                }

                if (! $hasCounterpartyForeign) {
                    $blueprint->foreign('counterparty_branch_id')->references('id')->on('branches')->restrictOnDelete();
                }
            });
        };

        // SQLite adds foreign keys by rebuilding the table and never validates
        // existing rows, so the switch only matters (and only runs) elsewhere.
        // Skipping it on SQLite also leaves a caller's own foreign key setting
        // untouched instead of forcing it back on afterwards.
        if (DB::connection()->getDriverName() === 'sqlite') {
            $alter();
        } else {
            Schema::withoutForeignKeyConstraints($alter);
        }

        // Deliberately no UPDATE ... WHERE branch_id IS NULL repair pass here
        // (unlike the header migrations): the column is NOT NULL with a default,
        // so it can never hold NULL, and a full-table write on this table is
        // exactly what we are avoiding.
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Separate statements, in this order: MySQL refuses to drop an index
        // its foreign key still needs, and SQLite rebuilds the whole table
        // when a foreign key is dropped.
        if (Schema::hasColumn(self::TABLE, 'counterparty_branch_id')) {
            if ($this->hasBranchForeignKey('counterparty_branch_id')) {
                Schema::table(self::TABLE, function (Blueprint $blueprint) {
                    $blueprint->dropForeign(['counterparty_branch_id']);
                });
            }

            Schema::table(self::TABLE, function (Blueprint $blueprint) {
                $blueprint->dropColumn('counterparty_branch_id');
            });
        }

        if (! Schema::hasColumn(self::TABLE, 'branch_id')) {
            return;
        }

        if ($this->hasBranchForeignKey('branch_id')) {
            Schema::table(self::TABLE, function (Blueprint $blueprint) {
                $blueprint->dropForeign(['branch_id']);
            });
        }

        if (Schema::hasIndex(self::TABLE, self::BRANCH_ACCOUNT_INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $blueprint) {
                $blueprint->dropIndex(self::BRANCH_ACCOUNT_INDEX);
            });
        }

        Schema::table(self::TABLE, function (Blueprint $blueprint) {
            $blueprint->dropColumn('branch_id');
        });
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

    private function hasBranchForeignKey(string $column): bool
    {
        foreach (Schema::getForeignKeys(self::TABLE) as $foreignKey) {
            if ($foreignKey['columns'] === [$column]) {
                return true;
            }
        }

        return false;
    }
};
