<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two item-catalog gaps (audit section 3 "Purchase" / section 4 polish):
 *
 * - `mrp`: the base-unit maximum retail price, alongside the per-alternate-
 *   unit `item_units.mrp` that already existed. Nullable, like every other
 *   optional rate on this table.
 * - A unique index on `name`: the app-level check (ItemController::
 *   validated(), case-insensitive via LOWER()) is the primary gate; this is
 *   the race backstop, the same relationship `purchases.bill_number_key`
 *   has with Purchase::assertBillNumberUnused(). Duplicate names among
 *   EXISTING rows are renamed first (appending " (duplicate #id)") so the
 *   index can be added at all - this never touches an item any document
 *   already references by id, only its display name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->decimal('mrp', 15, 4)->nullable()->after('sale_rate');
        });

        $this->renameDuplicates();

        Schema::table('items', function (Blueprint $table) {
            $table->unique('name');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropUnique(['name']);
            $table->dropColumn('mrp');
        });
    }

    /**
     * Nothing here reads a money column as a raw decimal, so the SQLite REAL
     * affinity backfill trap does not apply - this only rewrites text.
     */
    private function renameDuplicates(): void
    {
        $duplicateNames = DB::table('items')
            ->select('name')
            ->groupBy('name')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('name');

        foreach ($duplicateNames as $name) {
            $rows = DB::table('items')->where('name', $name)->orderBy('id')->get(['id']);

            // The first row keeps the plain name; every later duplicate is
            // disambiguated by its own id, which is already unique.
            foreach ($rows->skip(1) as $row) {
                DB::table('items')->where('id', $row->id)->update([
                    'name' => "{$name} (duplicate #{$row->id})",
                ]);
            }
        }
    }
};
