<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Header-level (sale_id, not sale_line_id) unlike SalesReturn's
     * per-line references - a receipt settles money against an invoice as
     * a whole, it isn't tied to specific line quantities. A receipt's
     * allocations may sum to less than its own amount (an accepted
     * on-account remainder not tied to any invoice) but never exceed a
     * single sale's own remaining Sale::outstandingAmount() - enforced in
     * App\Models\Receipt::post(), not here.
     */
    public function up(): void
    {
        Schema::create('receipt_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 20, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipt_allocations');
    }
};
