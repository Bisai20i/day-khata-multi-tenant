<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-branch (plan 12, BR18): a transfer between two branches posts two
 * BranchStockTransfer vouchers, one per branch, so each branch balances on
 * its own. BR03's journal_voucher_id keeps the dispatch voucher (sender's
 * branch); receipt_journal_voucher_id is the receiving branch's voucher,
 * posted when the goods are received. Cancelling a transfer still in transit
 * reverses the dispatch voucher, and reversal_journal_voucher_id records that
 * reversal, like every other cancellable document's (CONTRACTS C5).
 *
 * received_on is the business date the goods arrived (the receipt voucher's
 * and the TransferIn legs' date), kept apart from BR03's received_at, which
 * is when the receipt was recorded, the same split as `date` / dispatched_at.
 *
 * All three columns are nullable (a transfer inside one branch posts no
 * voucher and is never received) and need no backfill: every existing
 * transfer is a one-step `posted` transfer, because StockTransfer::post()
 * refused two branches until this release. The foreign keys null out on delete, the same as every other
 * reversal_journal_voucher_id. Idempotent: each column and foreign key is
 * added only when missing, so a re-run, or a run that stopped half way,
 * finishes cleanly.
 */
return new class extends Migration
{
    private const TABLE = 'stock_transfers';

    /**
     * @var list<string>
     */
    private array $columns = ['receipt_journal_voucher_id', 'reversal_journal_voucher_id'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn(self::TABLE, 'received_on')) {
            Schema::table(self::TABLE, function (Blueprint $blueprint) {
                $blueprint->date('received_on')->nullable();
            });
        }

        foreach ($this->columns as $column) {
            $hasColumn = Schema::hasColumn(self::TABLE, $column);
            $hasForeign = $hasColumn && $this->hasForeignKey($column);

            if ($hasColumn && $hasForeign) {
                continue;
            }

            Schema::table(self::TABLE, function (Blueprint $blueprint) use ($column, $hasColumn) {
                if (! $hasColumn) {
                    $blueprint->unsignedBigInteger($column)->nullable();
                }

                $blueprint->foreign($column)->references('id')->on('journal_vouchers')->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // The foreign key first, in its own statement: MySQL refuses to drop a
        // column a foreign key still uses, and SQLite rebuilds the table for
        // each foreign key change.
        foreach ($this->columns as $column) {
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

        if (Schema::hasColumn(self::TABLE, 'received_on')) {
            Schema::table(self::TABLE, function (Blueprint $blueprint) {
                $blueprint->dropColumn('received_on');
            });
        }
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
