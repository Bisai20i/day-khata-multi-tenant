<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * T14 (accounting parity): recoverable input VAT on a fixed asset
     * purchase, posted Dr ASA23 (Vat Receivable) alongside the asset's own
     * cost line so it reaches the VAT books (see FixedAsset::post()).
     * Defaulted to '0.00' rather than left nullable-without-default, so
     * every existing row (all posted before this column existed, hence no
     * VAT recorded against them) reads as an exact Money zero rather than
     * null - the same "no doctrine/dbal, backfill explicitly" rule this
     * repo's migrations.md documents, applied here via the column default
     * since there is nothing to derive a real value from for old rows.
     */
    public function up(): void
    {
        Schema::table('fixed_assets', function (Blueprint $table) {
            $table->decimal('vat_amount', 20, 2)->default(0)->after('cost');
        });
    }

    public function down(): void
    {
        Schema::table('fixed_assets', function (Blueprint $table) {
            $table->dropColumn('vat_amount');
        });
    }
};
