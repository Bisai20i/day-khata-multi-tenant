<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A receipt is a plain cash/bank settlement voucher against a customer
     * - see App\Models\Receipt::post(). payment_mode has only 'cash'/'bank'
     * (unlike Sale/Purchase's 'credit'/'partial' options): a receipt IS the
     * settlement, so those two don't apply here.
     */
    public function up(): void
    {
        Schema::create('receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
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
        Schema::dropIfExists('receipts');
    }
};
