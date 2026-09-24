<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Later settlements of a credit or partial capital/service purchase (audit
 * CS-01, ported from legacy capital_service_settlements). Each row is one
 * payment against exactly one capital purchase, backed by its own posted
 * voucher; the bill's outstanding balance is its unpaid amount less the sum
 * of live (not cancelled) settlements.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('capital_purchase_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('capital_purchase_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->decimal('amount', 15, 2);
            $table->string('payment_mode');
            $table->foreignId('bank_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->string('reference_number')->nullable();
            $table->string('narration')->nullable();
            $table->string('status')->default('posted');
            $table->foreignId('journal_voucher_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancel_reason')->nullable();
            $table->foreignId('reversal_journal_voucher_id')->nullable()->constrained('journal_vouchers')->restrictOnDelete();
            $table->timestamps();

            $table->index(['capital_purchase_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('capital_purchase_settlements');
    }
};
