<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Exact mirror of the receipts table (App\Models\Payment::post()) for
     * money paid out to a supplier instead of received from a customer.
     */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->decimal('amount', 20, 2);
            $table->string('payment_mode');
            $table->foreignId('bank_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->string('reference_number')->nullable();
            $table->string('narration')->nullable();
            $table->string('status')->default('posted');
            $table->foreignId('journal_voucher_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
