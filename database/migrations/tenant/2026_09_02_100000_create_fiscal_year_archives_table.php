<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Metadata row recording that a closed fiscal year's ledger was copied
     * out to a standalone, self-contained SQLite file (see
     * App\Support\FiscalYear\FiscalYearArchiver) - the row lives here, in
     * the live tenant database, but the archived data itself never does.
     * `fiscal_year_id` is unique: a fiscal year can only be archived once.
     */
    public function up(): void
    {
        Schema::create('fiscal_year_archives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_year_id')->unique()->constrained()->restrictOnDelete();
            $table->string('file_path');
            $table->unsignedInteger('voucher_count');
            $table->unsignedInteger('line_count');
            $table->foreignId('archived_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('archived_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fiscal_year_archives');
    }
};
