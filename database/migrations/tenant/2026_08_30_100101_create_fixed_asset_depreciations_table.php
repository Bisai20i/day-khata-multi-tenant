<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per (fixed_asset, fiscal_year) - the unique constraint below
     * is the "already posted this year" guard FixedAsset::
     * postDepreciationForFiscalYear() relies on instead of re-deriving it
     * from the ledger on every call.
     */
    public function up(): void
    {
        Schema::create('fixed_asset_depreciations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fixed_asset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fiscal_year_id')->constrained()->cascadeOnDelete();
            $table->foreignId('journal_voucher_id')->constrained()->restrictOnDelete();
            $table->date('posted_date');
            $table->decimal('opening_wdv', 20, 2);
            $table->decimal('depreciation_amount', 20, 2);
            $table->decimal('closing_wdv', 20, 2);
            $table->timestamps();

            $table->unique(['fixed_asset_id', 'fiscal_year_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_depreciations');
    }
};
