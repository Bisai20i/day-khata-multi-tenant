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
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('journal_voucher_id')->constrained()->restrictOnDelete();
            $table->string('invoice_type');
            $table->date('date');
            $table->string('payment_mode');
            $table->foreignId('bank_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('taxable_amount', 15, 2);
            $table->decimal('nontaxable_amount', 15, 2);
            $table->decimal('vat_rate', 5, 2)->default(13.00);
            $table->decimal('vat_amount', 15, 2);
            $table->decimal('total', 15, 2);
            $table->decimal('cash_amount', 15, 2)->nullable();
            $table->decimal('bank_amount', 15, 2)->nullable();
            $table->foreignId('tds_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->decimal('tds_amount', 15, 2)->default(0);
            $table->string('narration')->nullable();
            $table->string('status')->default('posted');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales');
    }
};
