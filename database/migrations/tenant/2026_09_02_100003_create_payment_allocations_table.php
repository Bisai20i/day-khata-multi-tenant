<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Exact mirror of receipt_allocations (App\Models\Payment::post()) -
     * header-level purchase_id, may under-allocate, guarded against
     * over-allocation in App\Models\Payment::post() via
     * Purchase::outstandingAmount().
     */
    public function up(): void
    {
        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 20, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
    }
};
