<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. Mirrors 2026_09_09_160001_add_item_unit_to_sale_
     * lines_table.php exactly - see that migration's docblock.
     */
    public function up(): void
    {
        Schema::table('purchase_lines', function (Blueprint $table) {
            $table->foreignId('item_unit_id')->nullable()->after('item_id')->constrained()->nullOnDelete();
            $table->decimal('unit_conversion_factor', 15, 4)->default(1)->after('quantity');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchase_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('item_unit_id');
            $table->dropColumn('unit_conversion_factor');
        });
    }
};
