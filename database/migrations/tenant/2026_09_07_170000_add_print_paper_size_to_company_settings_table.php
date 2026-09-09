<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A4/A5 page-size preference for printed invoices/documents. Defaulted
     * at the DB level (not nullable) so every existing tenant row gets a
     * sane 'a4' value without a backfill step.
     */
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->string('print_paper_size')->default('a4')->after('invoice_footer_note');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn('print_paper_size');
        });
    }
};
