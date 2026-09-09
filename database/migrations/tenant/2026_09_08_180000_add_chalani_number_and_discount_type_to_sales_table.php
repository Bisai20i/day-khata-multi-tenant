<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * chalani_number is a nullable, purely informational reference number
     * (no downstream calculation depends on it). discount_type records
     * whether the existing `discount` column's value is a flat Rs amount or
     * a percentage - app-level `in:percentage,flat` validation enforces the
     * two allowed values (see Sale::post()), matching how `print_paper_size`
     * is already done rather than a DB-level enum. Both columns are
     * nullable/defaulted so every existing tenant row stays valid with no
     * backfill step.
     */
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->string('chalani_number')->nullable()->after('invoice_type');
            $table->string('discount_type')->default('flat')->after('discount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn(['chalani_number', 'discount_type']);
        });
    }
};
