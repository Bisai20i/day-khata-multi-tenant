<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * item_unit_id is nullable - null means the line was entered in the
     * item's own base unit, exactly as every sale line worked before this
     * migration. unit_conversion_factor records the factor actually applied
     * at posting time (defaulting to 1, a no-op) so a line's original
     * base-unit math stays reconstructable even if the referenced ItemUnit
     * is later edited or deleted (nullOnDelete deliberately keeps the line
     * itself intact - see Sale::post()).
     */
    public function up(): void
    {
        Schema::table('sale_lines', function (Blueprint $table) {
            $table->foreignId('item_unit_id')->nullable()->after('item_id')->constrained()->nullOnDelete();
            $table->decimal('unit_conversion_factor', 15, 4)->default(1)->after('quantity');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sale_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('item_unit_id');
            $table->dropColumn('unit_conversion_factor');
        });
    }
};
