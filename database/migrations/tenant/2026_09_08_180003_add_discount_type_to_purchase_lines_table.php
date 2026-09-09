<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Same discount_type/discount pairing as the purchases table (see that
     * migration's docblock), added per line instead of only at the header.
     */
    public function up(): void
    {
        Schema::table('purchase_lines', function (Blueprint $table) {
            $table->string('discount_type')->default('flat')->after('discount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchase_lines', function (Blueprint $table) {
            $table->dropColumn('discount_type');
        });
    }
};
