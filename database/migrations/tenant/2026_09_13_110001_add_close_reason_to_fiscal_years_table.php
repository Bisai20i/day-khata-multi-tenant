<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * App\Models\FiscalYear::close() now refuses to close a year that has
     * not reached its end_date unless an admin supplies a written reason
     * (T11 task 7). The reason is worth keeping: an early close freezes a
     * period that could still legitimately receive documents, and an auditor
     * asking "why does 2081/82 stop in Chaitra?" should find the answer on
     * the year itself rather than in an activity log line.
     *
     * Nullable with no backfill: a year closed before this migration, and
     * every year closed on or after its end_date, simply has null here.
     */
    public function up(): void
    {
        Schema::table('fiscal_years', function (Blueprint $table) {
            $table->text('close_reason')->nullable()->after('closed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fiscal_years', function (Blueprint $table) {
            $table->dropColumn('close_reason');
        });
    }
};
