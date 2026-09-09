<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * purchase_rate/sale_rate are nullable (no backfill needed for existing
     * rows - see mem.md's tenant-migration gotcha, which only applies to
     * NOT NULL columns). Scale matches the existing rate-column convention
     * (sale_lines.rate/purchase_lines.rate: decimal(15,4)).
     */
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->decimal('purchase_rate', 15, 4)->nullable()->after('min_stock');
            $table->decimal('sale_rate', 15, 4)->nullable()->after('purchase_rate');
            $table->string('image_path')->nullable()->after('sale_rate');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn(['purchase_rate', 'sale_rate', 'image_path']);
        });
    }
};
