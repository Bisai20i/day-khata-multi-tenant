<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-branch (plan 12, BR03): stock transfers know which branch the goods
 * leave (from_branch_id) and reach (to_branch_id), and track the two-step
 * dispatch/receive flow. Both branch columns default to Main's id (BR01
 * inserts it before this migration runs) so code that does not yet set them
 * keeps working, and existing transfers are then backfilled from their
 * stores' branch (stores.branch_id via from_store_id / to_store_id), so a
 * transfer between stores of different branches is never mislabelled Main.
 *
 * `status` is a plain string column (default 'posted') with no cast or enum, so
 * the new `in_transit` and `received` values need no schema change.
 *
 * journal_voucher_id links the BranchStockTransfer voucher posted at dispatch.
 * Its foreign key restricts deletes (a posted voucher is never deleted while a
 * document points at it, matching every other document's voucher link);
 * received_by is a user and nulls out on delete.
 */
return new class extends Migration
{
    private const TABLE = 'stock_transfers';

    /**
     * NOT NULL branch columns, mapped to their explicit short index name.
     *
     * @var array<string, string>
     */
    private array $branchColumns = [
        'from_branch_id' => 'stock_transfers_from_branch_idx',
        'to_branch_id' => 'stock_transfers_to_branch_idx',
    ];

    /**
     * Nullable foreign keys, mapped to their parent table and on-delete rule.
     *
     * @var array<string, array{parent: string, onDelete: 'null'|'restrict'}>
     */
    private array $nullableForeignKeys = [
        'received_by' => ['parent' => 'users', 'onDelete' => 'null'],
        'journal_voucher_id' => ['parent' => 'journal_vouchers', 'onDelete' => 'restrict'],
    ];

    /**
     * Branch column mapped to the store column whose branch it inherits.
     *
     * @var array<string, string>
     */
    private array $storeColumns = [
        'from_branch_id' => 'from_store_id',
        'to_branch_id' => 'to_store_id',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $mainId = $this->mainBranchId();

        foreach ($this->branchColumns as $column => $indexName) {
            $hasColumn = Schema::hasColumn(self::TABLE, $column);
            $hasIndex = $hasColumn && Schema::hasIndex(self::TABLE, $indexName);
            $hasForeign = $hasColumn && $this->hasForeignKey($column);

            if (! $hasColumn || ! $hasIndex || ! $hasForeign) {
                Schema::table(self::TABLE, function (Blueprint $blueprint) use ($column, $indexName, $hasColumn, $hasIndex, $hasForeign, $mainId) {
                    if (! $hasColumn) {
                        $blueprint->unsignedBigInteger($column)->default($mainId);
                    }

                    // Index before the foreign key so MySQL reuses it for the FK.
                    if (! $hasIndex) {
                        $blueprint->index($column, $indexName);
                    }

                    if (! $hasForeign) {
                        $blueprint->foreign($column)->references('id')->on('branches')->restrictOnDelete();
                    }
                });
            }

            // The column is NOT NULL with a default, so existing rows already
            // sit on Main; this only repairs rows from a half-applied run.
            DB::table(self::TABLE)->whereNull($column)->update([$column => $mainId]);

            $this->backfillFromStores($column, $this->storeColumns[$column]);
        }

        foreach (['dispatched_at', 'received_at'] as $column) {
            if (! Schema::hasColumn(self::TABLE, $column)) {
                Schema::table(self::TABLE, function (Blueprint $blueprint) use ($column) {
                    $blueprint->timestamp($column)->nullable();
                });
            }
        }

        foreach ($this->nullableForeignKeys as $column => $spec) {
            $hasColumn = Schema::hasColumn(self::TABLE, $column);
            $hasForeign = $hasColumn && $this->hasForeignKey($column);

            if (! $hasColumn || ! $hasForeign) {
                Schema::table(self::TABLE, function (Blueprint $blueprint) use ($column, $spec, $hasColumn, $hasForeign) {
                    if (! $hasColumn) {
                        $blueprint->unsignedBigInteger($column)->nullable();
                    }

                    if (! $hasForeign) {
                        $foreign = $blueprint->foreign($column)->references('id')->on($spec['parent']);

                        if ($spec['onDelete'] === 'null') {
                            $foreign->nullOnDelete();
                        } else {
                            $foreign->restrictOnDelete();
                        }
                    }
                });
            }
        }
    }

    /**
     * Point existing transfers at their stores' branch, via a correlated
     * subquery so it runs unchanged on MySQL and SQLite. A store's branch never
     * changes once it has movements (invariant I5) and a transfer's end must
     * match its store's branch, so re-running this is a no-op on correct rows
     * and also finishes a run that stopped between adding the column and the
     * backfill. Rows whose store has no branch keep Main from the default.
     */
    private function backfillFromStores(string $branchColumn, string $storeColumn): void
    {
        if (! Schema::hasColumn('stores', 'branch_id')) {
            return;
        }

        $table = self::TABLE;
        $storeBranch = "(select stores.branch_id from stores where stores.id = {$table}.{$storeColumn})";

        DB::table($table)
            ->whereRaw("{$storeBranch} is not null")
            ->update([$branchColumn => DB::raw($storeBranch)]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Separate statements, in this order: MySQL refuses to drop an index
        // its foreign key still needs, and SQLite rebuilds the whole table
        // when a foreign key is dropped.
        foreach (array_keys($this->nullableForeignKeys) as $column) {
            if (! Schema::hasColumn(self::TABLE, $column)) {
                continue;
            }

            if ($this->hasForeignKey($column)) {
                Schema::table(self::TABLE, function (Blueprint $blueprint) use ($column) {
                    $blueprint->dropForeign([$column]);
                });
            }

            Schema::table(self::TABLE, function (Blueprint $blueprint) use ($column) {
                $blueprint->dropColumn($column);
            });
        }

        foreach (['dispatched_at', 'received_at'] as $column) {
            if (Schema::hasColumn(self::TABLE, $column)) {
                Schema::table(self::TABLE, function (Blueprint $blueprint) use ($column) {
                    $blueprint->dropColumn($column);
                });
            }
        }

        foreach ($this->branchColumns as $column => $indexName) {
            if (! Schema::hasColumn(self::TABLE, $column)) {
                continue;
            }

            if ($this->hasForeignKey($column)) {
                Schema::table(self::TABLE, function (Blueprint $blueprint) use ($column) {
                    $blueprint->dropForeign([$column]);
                });
            }

            if (Schema::hasIndex(self::TABLE, $indexName)) {
                Schema::table(self::TABLE, function (Blueprint $blueprint) use ($indexName) {
                    $blueprint->dropIndex($indexName);
                });
            }

            Schema::table(self::TABLE, function (Blueprint $blueprint) use ($column) {
                $blueprint->dropColumn($column);
            });
        }
    }

    /**
     * The Main branch id; BR01 creates it, this migration never does.
     */
    private function mainBranchId(): int
    {
        if (! Schema::hasTable('branches')) {
            throw new RuntimeException('The branches table is missing. Run the branches migrations (2026_11_10_100000 to 100003) before adding branch columns to stock transfers.');
        }

        $mainId = DB::table('branches')->where('is_main', 1)->value('id');

        if ($mainId === null) {
            throw new RuntimeException('No Main branch exists. The branches migrations must insert it before branch columns are added to stock transfers.');
        }

        return (int) $mainId;
    }

    private function hasForeignKey(string $column): bool
    {
        foreach (Schema::getForeignKeys(self::TABLE) as $foreignKey) {
            if ($foreignKey['columns'] === [$column]) {
                return true;
            }
        }

        return false;
    }
};
