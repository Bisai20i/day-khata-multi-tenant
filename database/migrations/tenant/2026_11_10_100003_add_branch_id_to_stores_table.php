<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Every store belongs to exactly one branch. The column is NOT NULL with
     * Main's id as its default, so existing stores land on Main when it is
     * added and raw inserts that omit it stay valid (the Store model also
     * sets it explicitly). The column and the foreign key are checked
     * separately so a half-applied run is completed rather than skipped.
     */
    public function up(): void
    {
        $mainId = $this->mainBranchId();

        $hasColumn = Schema::hasColumn('stores', 'branch_id');
        $hasForeign = $hasColumn && $this->hasBranchForeignKey();

        if (! $hasColumn || ! $hasForeign) {
            Schema::table('stores', function (Blueprint $table) use ($hasColumn, $hasForeign, $mainId) {
                if (! $hasColumn) {
                    $table->unsignedBigInteger('branch_id')->after('name')->default($mainId);
                }

                if (! $hasForeign) {
                    $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * Separate statements, in this order: the foreign key goes first so the
     * column can be dropped without a dangling constraint (and MySQL can drop
     * the index the key was using), and SQLite rebuilds the table when a
     * foreign key is dropped.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('stores', 'branch_id')) {
            return;
        }

        if ($this->hasBranchForeignKey()) {
            Schema::table('stores', function (Blueprint $table) {
                $table->dropForeign(['branch_id']);
            });
        }

        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('branch_id');
        });
    }

    /**
     * The Main branch id; the branches migration creates it, this one never does.
     */
    private function mainBranchId(): int
    {
        if (! Schema::hasTable('branches')) {
            throw new RuntimeException('The branches table is missing. Run the branches migrations (2026_11_10_100000 to 100003) before adding branch_id to stores.');
        }

        $mainId = DB::table('branches')->where('is_main', 1)->value('id');

        if ($mainId === null) {
            throw new RuntimeException('No Main branch exists. The branches migrations must insert it before branch_id is added to stores.');
        }

        return (int) $mainId;
    }

    private function hasBranchForeignKey(): bool
    {
        foreach (Schema::getForeignKeys('stores') as $foreignKey) {
            if ($foreignKey['columns'] === ['branch_id'] && $foreignKey['foreign_table'] === 'branches') {
                return true;
            }
        }

        return false;
    }
};
