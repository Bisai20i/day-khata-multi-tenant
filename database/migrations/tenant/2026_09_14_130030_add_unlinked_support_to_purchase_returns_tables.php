<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An "unlinked" purchase return (audit section 3 "Purchase"): goods a tenant
 * holds from opening stock, or from a purchase made before this system went
 * live, that need to go back to a supplier with no Purchase row to point at.
 * See PurchaseReturn::postUnlinked().
 *
 * `purchase_id` and `purchase_return_lines.purchase_line_id` become nullable
 * via `->change()` - Laravel 11+ no longer needs doctrine/dbal for this (this
 * repo already does the identical thing for `sales_returns.journal_
 * voucher_id`, see 2026_09_09_200000_add_pending_workflow_to_sales_returns_
 * table.php), so every existing linked return is unaffected: its
 * purchase_id/purchase_line_id stay exactly as posted.
 *
 * `supplier_id` lets an unlinked return still name a supplier without a
 * Purchase row to derive one from. `vat_rate`/`cash_amount`/`bank_amount`/
 * `bank_account_id` carry what a linked return has always read off its
 * parent purchase or a later refund: an unlinked return has neither, so it
 * settles cash/bank at posting time and applies the company's own VAT rate
 * directly. `purchase_return_lines.item_id`/`item_unit_id`/
 * `unit_conversion_factor` let an unlinked line name an item and unit
 * directly instead of inheriting them from a purchase line.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_returns', function (Blueprint $table) {
            $table->foreignId('purchase_id')->nullable()->change();
            $table->foreignId('supplier_id')->nullable()->after('purchase_id')->constrained('suppliers')->nullOnDelete();
            $table->boolean('is_unlinked')->default(false)->after('debit_note_number');
            $table->decimal('vat_rate', 5, 2)->nullable()->after('taxable_amount');
            $table->decimal('cash_amount', 15, 2)->nullable()->after('refund_account_id');
            $table->decimal('bank_amount', 15, 2)->nullable()->after('cash_amount');
            $table->foreignId('bank_account_id')->nullable()->after('bank_amount')->constrained('accounts')->nullOnDelete();
        });

        Schema::table('purchase_return_lines', function (Blueprint $table) {
            $table->foreignId('purchase_line_id')->nullable()->change();
            $table->foreignId('item_id')->nullable()->after('purchase_line_id')->constrained('items')->nullOnDelete();
            $table->foreignId('item_unit_id')->nullable()->after('item_id')->constrained('item_units')->nullOnDelete();
            $table->decimal('unit_conversion_factor', 15, 4)->nullable()->after('item_unit_id');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_return_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('item_id');
            $table->dropConstrainedForeignId('item_unit_id');
            $table->dropColumn('unit_conversion_factor');
            $table->foreignId('purchase_line_id')->nullable(false)->change();
        });

        Schema::table('purchase_returns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supplier_id');
            $table->dropConstrainedForeignId('bank_account_id');
            $table->dropColumn(['is_unlinked', 'vat_rate', 'cash_amount', 'bank_amount']);
            $table->foreignId('purchase_id')->nullable(false)->change();
        });
    }
};
