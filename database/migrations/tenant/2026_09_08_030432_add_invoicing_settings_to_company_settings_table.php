<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds the Phase A settings-foundation columns (logo, default VAT rate,
     * negative-stock policy, default store, and per-invoice-type
     * prefix/enable pairs) to the company_settings singleton. All new
     * columns are nullable or have a DB-level default so every existing
     * tenant row is valid with no backfill step (no doctrine/dbal in this
     * project, so NOT NULL columns need a real default rather than
     * ->change()).
     */
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->string('logo_path')->nullable()->after('invoice_footer_note');
            $table->decimal('default_vat_rate', 5, 2)->default(13.00)->after('print_paper_size');
            $table->boolean('allow_negative_stock')->default(false)->after('default_vat_rate');
            $table->foreignId('default_store_id')->nullable()->after('allow_negative_stock')->constrained('stores')->nullOnDelete();
            $table->string('sale_full_prefix')->default('SL')->after('default_store_id');
            $table->boolean('sale_full_enabled')->default(true)->after('sale_full_prefix');
            $table->string('sale_abbreviated_prefix')->default('SLA')->after('sale_full_enabled');
            $table->boolean('sale_abbreviated_enabled')->default(true)->after('sale_abbreviated_prefix');
            $table->string('sale_pan_prefix')->default('SLP')->after('sale_abbreviated_enabled');
            $table->boolean('sale_pan_enabled')->default(true)->after('sale_pan_prefix');
            $table->string('purchase_prefix')->default('PU')->after('sale_pan_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_store_id');
            $table->dropColumn([
                'logo_path',
                'default_vat_rate',
                'allow_negative_stock',
                'sale_full_prefix',
                'sale_full_enabled',
                'sale_abbreviated_prefix',
                'sale_abbreviated_enabled',
                'sale_pan_prefix',
                'sale_pan_enabled',
                'purchase_prefix',
            ]);
        });
    }
};
