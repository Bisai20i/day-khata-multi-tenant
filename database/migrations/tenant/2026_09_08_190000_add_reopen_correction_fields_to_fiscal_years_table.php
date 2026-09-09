<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * All-nullable, no backfill needed - a fiscal year closed before this
     * migration lands simply has null closed_by/closed_at forever (App\
     * Models\FiscalYear::reopen() makes a best-effort backfill of those two
     * only at the moment such a year is first reopened). A closed year with
     * reopened_at set and relocked_at still null is "open for correction" -
     * see FiscalYear::isOpenForCorrection().
     */
    public function up(): void
    {
        Schema::table('fiscal_years', function (Blueprint $table) {
            $table->foreignId('closed_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable()->after('closed_by');
            $table->foreignId('reopened_by')->nullable()->after('closed_at')->constrained('users')->nullOnDelete();
            $table->timestamp('reopened_at')->nullable()->after('reopened_by');
            $table->text('reopen_reason')->nullable()->after('reopened_at');
            $table->timestamp('relocked_at')->nullable()->after('reopen_reason');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fiscal_years', function (Blueprint $table) {
            $table->dropConstrainedForeignId('closed_by');
            $table->dropColumn('closed_at');
            $table->dropConstrainedForeignId('reopened_by');
            $table->dropColumn(['reopened_at', 'reopen_reason', 'relocked_at']);
        });
    }
};
