<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Gives credit notes (sales returns) and debit notes (purchase returns)
     * their own configurable prefix, alongside the sale and purchase ones that
     * already existed. Both defaults are the literals the print controllers
     * hard-coded before this ("SR-{n}" and "PR-{n}"), so every document that
     * has already been printed keeps reprinting with exactly the number it was
     * issued under.
     *
     * Settings validation now also requires all six prefixes to be distinct:
     * two series sharing a prefix print the same "SL-7" on two different
     * documents, which is the failure the stored per-series invoice number
     * (CONTRACTS C7) exists to prevent. Both new columns have a DB-level
     * default, so every existing tenant row is valid with no backfill step
     * (no doctrine/dbal in this project, so a NOT NULL column needs a real
     * default rather than ->change()).
     */
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->string('sale_return_prefix')->default('SR')->after('purchase_prefix');
            $table->string('purchase_return_prefix')->default('PR')->after('sale_return_prefix');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn(['sale_return_prefix', 'purchase_return_prefix']);
        });
    }
};
