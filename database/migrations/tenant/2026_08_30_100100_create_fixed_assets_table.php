<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A fixed asset is a permanent register (like customers/suppliers/items)
     * - not fiscal-year-scoped. accumulated_depreciation simply keeps
     * growing year over year; only the ledger postings (via account_id /
     * the Accumulated Depreciation / Depreciation Expense accounts) carry
     * forward through FiscalYear's existing balance-sheet carry-forward
     * mechanism, so no opening-balance step is needed here at FY close.
     */
    public function up(): void
    {
        Schema::create('fixed_assets', function (Blueprint $table) {
            $table->id();
            $table->string('asset_code')->unique();
            $table->string('asset_name');
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->string('category');
            $table->date('purchase_date');
            $table->decimal('cost', 20, 2);
            $table->decimal('salvage_value', 20, 2)->default(0);
            $table->string('depreciation_method');
            $table->decimal('depreciation_rate', 5, 2)->default(0);
            $table->decimal('accumulated_depreciation', 20, 2)->default(0);
            $table->string('status')->default('active');
            $table->date('disposal_date')->nullable();
            $table->decimal('disposal_amount', 20, 2)->nullable();
            $table->foreignId('journal_voucher_id')->constrained()->restrictOnDelete();
            $table->foreignId('disposal_journal_voucher_id')->nullable()->constrained('journal_vouchers')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_assets');
    }
};
