<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-branch (plan 12, BR25): raw material and finished goods may now move
 * between branches. Their value leaves AS12 / AS14 in the sender's branch at
 * dispatch and enters it in the receiver's branch at receipt, so the two
 * vouchers of a transfer need to know how much of the stored total is
 * perpetual, and in which class each line was.
 *
 * - stock_transfers.perpetual_value: the raw material plus finished goods
 *   part of total_value, written at dispatch. Zero for every existing
 *   transfer and needs no backfill: StockTransfer::post() refused perpetual
 *   items between branches until this release, and a transfer inside one
 *   branch posts no voucher at all.
 * - stock_transfer_lines.inventory_class: the item's class when the line was
 *   written, so the receipt voucher splits the stored line_value by the
 *   class it left under rather than re-reading anything at receipt. Existing
 *   lines are backfilled from their item (an item's class cannot change once
 *   it has a stock movement, and every line has two), by one correlated
 *   UPDATE that runs the same on SQLite and MySQL. Only non-trading items
 *   are touched, so a re-run changes nothing.
 *
 * No foreign keys or indexes, so down() just drops the two columns.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('stock_transfers', 'perpetual_value')) {
            Schema::table('stock_transfers', function (Blueprint $table) {
                $table->decimal('perpetual_value', 15, 2)->default(0);
            });
        }

        if (! Schema::hasColumn('stock_transfer_lines', 'inventory_class')) {
            Schema::table('stock_transfer_lines', function (Blueprint $table) {
                $table->string('inventory_class', 20)->default('trading');
            });
        }

        DB::table('stock_transfer_lines')
            ->whereExists(fn (Builder $item) => $item
                ->selectRaw('1')
                ->from('items')
                ->whereColumn('items.id', 'stock_transfer_lines.item_id')
                ->where('items.inventory_class', '!=', 'trading'))
            ->update([
                'inventory_class' => DB::raw('(select items.inventory_class from items where items.id = stock_transfer_lines.item_id)'),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('stock_transfer_lines', 'inventory_class')) {
            Schema::table('stock_transfer_lines', function (Blueprint $table) {
                $table->dropColumn('inventory_class');
            });
        }

        if (Schema::hasColumn('stock_transfers', 'perpetual_value')) {
            Schema::table('stock_transfers', function (Blueprint $table) {
                $table->dropColumn('perpetual_value');
            });
        }
    }
};
