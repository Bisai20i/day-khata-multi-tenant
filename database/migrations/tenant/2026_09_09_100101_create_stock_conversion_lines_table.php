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
        Schema::create('stock_conversion_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_conversion_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained()->restrictOnDelete();
            // 'out' = an input item consumed by this conversion, 'in' = an
            // output item produced by it - same direction vocabulary as
            // stock_adjustment_lines, just driven by input/output section
            // instead of a user-picked dropdown (see StockConversion::post()).
            $table->string('direction');
            $table->decimal('quantity', 15, 4);
            $table->decimal('unit_cost_rate', 15, 4)->nullable();
            $table->decimal('line_value', 15, 2)->default(0);
            $table->string('remarks')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_conversion_lines');
    }
};
