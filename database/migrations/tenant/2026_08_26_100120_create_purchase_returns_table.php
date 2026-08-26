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
        Schema::create('purchase_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained()->restrictOnDelete();
            $table->foreignId('journal_voucher_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->string('reason')->nullable();
            $table->decimal('taxable_amount', 15, 2);
            $table->decimal('nontaxable_amount', 15, 2);
            $table->decimal('vat_amount', 15, 2);
            $table->decimal('total', 15, 2);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_returns');
    }
};
