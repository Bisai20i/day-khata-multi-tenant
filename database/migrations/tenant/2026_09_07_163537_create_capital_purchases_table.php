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
        Schema::create('capital_purchases', function (Blueprint $table) {
            $table->id();
            // Nullable at the DB level: only required for credit/partial
            // settlement, enforced in CapitalPurchase::post() rather than a
            // DB constraint (mirrors how bank_account_id is conditionally
            // required below).
            $table->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('journal_voucher_id')->constrained()->restrictOnDelete();
            $table->string('type');
            $table->date('date');
            $table->string('narration')->nullable();
            $table->string('payment_mode');
            $table->foreignId('bank_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->decimal('cash_amount', 15, 2)->nullable();
            $table->decimal('bank_amount', 15, 2)->nullable();
            $table->decimal('vat_amount', 15, 2)->default(0);
            $table->decimal('total', 15, 2);
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
        Schema::dropIfExists('capital_purchases');
    }
};
