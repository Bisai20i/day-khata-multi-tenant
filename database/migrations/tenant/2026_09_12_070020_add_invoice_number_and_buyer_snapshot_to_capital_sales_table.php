<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A capital sale is a real tax invoice - it credits Output VAT and belongs in
 * the Sales VAT book - but it had no invoice number of its own and no
 * printable bill at all. Screens derived a display number from the voucher at
 * render time, which means a settings change silently renumbered documents
 * that had already been handed to customers (audit C7).
 *
 * The number is stored once at posting, unique inside its fiscal year, and
 * nothing re-derives it afterwards. The buyer snapshot is stored for the same
 * reason: a customer who later changes their name or PAN must not change what
 * an already-issued invoice says.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('capital_sales', function (Blueprint $table) {
            $table->foreignId('fiscal_year_id')->nullable()->after('journal_voucher_id')->constrained()->restrictOnDelete();
            $table->string('invoice_number', 40)->nullable()->after('fiscal_year_id');
            $table->string('buyer_name')->nullable()->after('customer_id');
            $table->string('buyer_pan')->nullable()->after('buyer_name');
            $table->string('buyer_address')->nullable()->after('buyer_pan');

            $table->unique(['fiscal_year_id', 'invoice_number'], 'capital_sales_fy_invoice_number_unique');
        });

        $this->backfill();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('capital_sales', function (Blueprint $table) {
            $table->dropUnique('capital_sales_fy_invoice_number_unique');
            $table->dropConstrainedForeignId('fiscal_year_id');
            $table->dropColumn(['invoice_number', 'buyer_name', 'buyer_pan', 'buyer_address']);
        });
    }

    /**
     * Gives every already-posted capital sale the number its screens were
     * deriving anyway, so nothing a customer holds changes.
     *
     * The prefix comes from the settings column when T03 has added one and
     * falls back to `CS`, which is what CapitalSale::invoicePrefix() uses.
     * Idempotent: only rows with no stored number are touched.
     */
    private function backfill(): void
    {
        $prefix = 'CS';

        if (Schema::hasColumn('company_settings', 'capital_sale_prefix')) {
            $configured = DB::table('company_settings')->orderBy('id')->value('capital_sale_prefix');
            $prefix = is_string($configured) && $configured !== '' ? $configured : $prefix;
        }

        // lazyById(), not each(): the update below takes each row back out of
        // the `invoice_number is null` filter, and offset-based chunking would
        // then skip a whole page of invoices every time it advanced.
        foreach (DB::table('capital_sales')->whereNull('invoice_number')->lazyById() as $capitalSale) {
            $voucher = DB::table('journal_vouchers')->where('id', $capitalSale->journal_voucher_id)->first();

            if ($voucher === null) {
                continue;
            }

            $customer = $capitalSale->customer_id
                ? DB::table('customers')->where('id', $capitalSale->customer_id)->first()
                : null;

            DB::table('capital_sales')->where('id', $capitalSale->id)->update([
                'fiscal_year_id' => $voucher->fiscal_year_id,
                'invoice_number' => "{$prefix}-{$voucher->voucher_number}",
                'buyer_name' => $customer?->name,
                'buyer_pan' => $customer?->tpin,
                'buyer_address' => $customer?->address,
            ]);
        }
    }
};
