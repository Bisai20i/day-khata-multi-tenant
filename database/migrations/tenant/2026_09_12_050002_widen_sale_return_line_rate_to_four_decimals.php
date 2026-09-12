<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `sale_return_lines.rate` is a copy of the original sale line's rate,
     * and that column holds 4 decimals - so a rate of 1.2345 was written
     * into a 2-decimal column and came back as 1.23 on the credit note,
     * silently disagreeing with the invoice it credits (audit P1,
     * "sale_return_lines.rate precision"). Widening it to decimal(15,4)
     * makes the two agree; no value shrinks, so nothing needs backfilling.
     *
     * A plain `->change()` is safe on both drivers here: it only widens the
     * scale of an existing non-null column, and the column carries no index
     * or foreign key. Laravel's native schema change (no doctrine/dbal)
     * rewrites the table on SQLite and issues a MODIFY on MySQL.
     */
    public function up(): void
    {
        Schema::table('sale_return_lines', function (Blueprint $table) {
            $table->decimal('rate', 15, 4)->change();
        });
    }

    public function down(): void
    {
        Schema::table('sale_return_lines', function (Blueprint $table) {
            $table->decimal('rate', 15, 2)->change();
        });
    }
};
