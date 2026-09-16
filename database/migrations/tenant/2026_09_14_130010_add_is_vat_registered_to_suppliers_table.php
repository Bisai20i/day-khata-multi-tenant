<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drives the default state of the PAN / non-VAT toggle on the purchase form:
 * a supplier who is not VAT registered can only ever issue a PAN bill, so a
 * fresh purchase against them opens with `force_non_taxable` pre-checked.
 * Defaults to true (registered) so every existing supplier keeps behaving
 * exactly as before this column existed - nothing was force-non-taxable
 * before this migration, and this keeps it that way until a tenant marks a
 * supplier otherwise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->boolean('is_vat_registered')->default(true)->after('tpin');
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn('is_vat_registered');
        });
    }
};
