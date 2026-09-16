<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An "unlinked" sales return (audit section 3 "Sales"): goods a customer
 * hands back with no bill this system ever issued - a pre-cutover sale, a
 * walk-in who lost their receipt, a return against a paper invoice from
 * before this tenant went live. See SalesReturn::postUnlinked().
 *
 * `sale_id` and `sale_return_lines.sale_line_id` become nullable via
 * `->change()` - Laravel 11+ no longer needs doctrine/dbal for this; this
 * repo already does the identical thing for `sales_returns.journal_
 * voucher_id` (2026_09_09_200000_add_pending_workflow_to_sales_returns_
 * table.php) - so every existing linked return is unaffected: its sale_id/
 * sale_line_id stay exactly as posted.
 *
 * `customer_id` lets an unlinked return still name who it credits without a
 * Sale row to derive one from. `vat_rate` freezes the company rate that was
 * in effect at posting (an unlinked return has no parent invoice to read a
 * rate off), matching how `sales.vat_rate` already works.
 * `sale_return_lines.item_id`/`item_unit_id`/`unit_conversion_factor` let an
 * unlinked line name an item and unit directly instead of inheriting them
 * from a sale line - mirrors T13's identical purchase-side migration
 * (2026_09_14_130030_add_unlinked_support_to_purchase_returns_tables.php)
 * for naming consistency between the two modules.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_returns', function (Blueprint $table) {
            $table->foreignId('sale_id')->nullable()->change();
            $table->foreignId('customer_id')->nullable()->after('sale_id')->constrained('customers')->nullOnDelete();
            $table->boolean('is_unlinked')->default(false)->after('credit_note_number');
            $table->decimal('vat_rate', 5, 2)->nullable()->after('taxable_amount');
        });

        Schema::table('sale_return_lines', function (Blueprint $table) {
            $table->foreignId('sale_line_id')->nullable()->change();
            $table->foreignId('item_id')->nullable()->after('sale_line_id')->constrained('items')->nullOnDelete();
            $table->foreignId('item_unit_id')->nullable()->after('item_id')->constrained('item_units')->nullOnDelete();
            $table->decimal('unit_conversion_factor', 15, 4)->nullable()->after('item_unit_id');
            $table->boolean('vatable')->default(false)->after('unit_conversion_factor');
        });
    }

    public function down(): void
    {
        Schema::table('sale_return_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('item_id');
            $table->dropConstrainedForeignId('item_unit_id');
            $table->dropColumn(['unit_conversion_factor', 'vatable']);
            $table->foreignId('sale_line_id')->nullable(false)->change();
        });

        Schema::table('sales_returns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
            $table->dropColumn(['is_unlinked', 'vat_rate']);
            $table->foreignId('sale_id')->nullable(false)->change();
        });
    }
};
