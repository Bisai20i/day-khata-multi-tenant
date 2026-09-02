<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('item_stock_movements', function (Blueprint $table) {
            $table->foreignId('store_id')->after('item_id')->constrained()->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('item_stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('store_id');
        });
    }
};
