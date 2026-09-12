<?php

use App\Support\Money\Money;
use App\Support\Money\Quantity;
use Brick\Math\BigDecimal;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Three related groups of columns, all required by CONTRACTS C5/C7:
     *
     * 1. `fiscal_year_id` + `invoice_number`: the printed invoice number
     *    becomes a stored fact instead of being re-derived from the current
     *    prefix setting and the voucher number at display time (audit P0-15,
     *    P1 "Invoice and IRD compliance"). Unique per fiscal year so a series
     *    can never issue the same number twice.
     * 2. Buyer snapshot (`buyer_name`, `buyer_pan`, `buyer_address`): what the
     *    invoice was actually issued to, frozen at posting. Editing a customer
     *    later must not silently rewrite a filed tax invoice.
     * 3. Cancel columns (C5): who cancelled, when, why, and the reversal
     *    voucher, instead of burying the reason inside a narration string.
     *
     * Plus `discount_amount` on both `sales` and `sale_lines`: the rupee value
     * the discount actually removed. `discount` alone is ambiguous (a raw
     * percentage when `discount_type` is 'percentage'), and the old algebraic
     * reconstruction in Sale::discountAmount() cannot be exact once the header
     * discount is split proportionally across the taxable and exempt subtotals
     * (C3 step 4). Storing it lets the PDF print a stored value instead of
     * recomputing the bill (audit P0-8).
     *
     * Every column is nullable, so no backfill is needed for the table to be
     * valid; the data backfill below is a best-effort reconstruction for rows
     * posted before this migration, run only for rows that are still null (so
     * re-running it is a no-op).
     */
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('fiscal_year_id')->nullable()->after('journal_voucher_id')->constrained()->restrictOnDelete();
            $table->string('invoice_number', 40)->nullable()->after('fiscal_year_id');
            $table->string('buyer_name')->nullable()->after('invoice_number');
            $table->string('buyer_pan')->nullable()->after('buyer_name');
            $table->string('buyer_address')->nullable()->after('buyer_pan');
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancel_reason')->nullable();
            $table->foreignId('reversal_journal_voucher_id')->nullable()->constrained('journal_vouchers')->nullOnDelete();
            $table->decimal('discount_amount', 15, 2)->default(0)->after('discount_type');
        });

        Schema::table('sale_lines', function (Blueprint $table) {
            $table->decimal('discount_amount', 15, 2)->default(0)->after('discount_type');
        });

        $this->backfill();
        $this->backfillDiscountAmounts();

        Schema::table('sales', function (Blueprint $table) {
            $table->unique(['fiscal_year_id', 'invoice_number'], 'sales_fiscal_year_invoice_number_unique');
        });
    }

    /**
     * Reconstructs the number every already-posted sale was printing until
     * now: `{prefix}-{voucher_number}`, with the prefix read from the current
     * company settings exactly as SaleController::print() did, so an old bill
     * reprints identically. A tenant that set two invoice-type prefixes to the
     * same string could produce a collision inside one fiscal year, which the
     * unique index added afterwards would reject - such a row falls back to
     * `{prefix}-{voucher_number}-{sale id}` so the migration still completes
     * and the clash is visible on the bill rather than blocking the upgrade.
     */
    private function backfill(): void
    {
        $settings = DB::table('company_settings')->first();

        $prefixes = [
            'full' => $settings->sale_full_prefix ?? 'SL',
            'abbreviated' => $settings->sale_abbreviated_prefix ?? 'SLA',
            'pan' => $settings->sale_pan_prefix ?? 'SLP',
        ];

        $taken = DB::table('sales')
            ->whereNotNull('invoice_number')
            ->get(['fiscal_year_id', 'invoice_number'])
            ->map(fn ($row) => $row->fiscal_year_id.'|'.$row->invoice_number)
            ->flip();

        DB::table('sales')
            ->leftJoin('journal_vouchers', 'sales.journal_voucher_id', '=', 'journal_vouchers.id')
            ->leftJoin('customers', 'sales.customer_id', '=', 'customers.id')
            ->whereNull('sales.invoice_number')
            ->select([
                'sales.id',
                'sales.invoice_type',
                'journal_vouchers.fiscal_year_id',
                'journal_vouchers.voucher_number',
                'customers.name as customer_name',
                'customers.tpin as customer_tpin',
                'customers.address as customer_address',
            ])
            ->chunkById(200, function ($sales) use ($prefixes, $taken) {
                foreach ($sales as $sale) {
                    $prefix = $prefixes[$sale->invoice_type] ?? $prefixes['full'];
                    $number = $prefix.'-'.($sale->voucher_number ?? $sale->id);
                    $key = $sale->fiscal_year_id.'|'.$number;

                    if ($taken->has($key)) {
                        $number .= '-'.$sale->id;
                        $key = $sale->fiscal_year_id.'|'.$number;
                    }

                    $taken->put($key, true);

                    DB::table('sales')->where('id', $sale->id)->update([
                        'fiscal_year_id' => $sale->fiscal_year_id,
                        'invoice_number' => $number,
                        'buyer_name' => $sale->customer_name,
                        'buyer_pan' => $sale->customer_tpin,
                        'buyer_address' => $sale->customer_address,
                    ]);
                }
            }, 'sales.id', 'id');
    }

    /**
     * Rebuilds the rupee discount for rows posted before the column existed.
     *
     * A line's discount is simply what the stored line total is short of its
     * gross, so it can be recovered exactly. A header percentage discount
     * cannot: only the post-discount taxable amount was ever stored, so the
     * pre-discount subtotal is reconstructed algebraically - the same
     * approximation the old Sale::discountAmount() printed, kept so an old
     * bill reprints with the number it always showed. Only rows still at the
     * column default are touched, so this is safe to re-run.
     *
     * Every stored value is read through `Money::round()`/`Quantity::round()`
     * rather than `::of()`. These are raw `DB::table()` reads with no cast, and
     * on SQLite a DECIMAL column comes back as a PHP float - a row written by
     * the old float code can round-trip as `404984.71000000002`, which `of()`
     * would refuse outright and take the whole upgrade down with it. `round()`
     * is the sanctioned way to bring an external value to scale (C1).
     */
    private function backfillDiscountAmounts(): void
    {
        DB::table('sale_lines')
            ->where('discount_amount', 0)
            ->select(['id', 'quantity', 'rate', 'line_total'])
            ->chunkById(500, function ($lines) {
                foreach ($lines as $line) {
                    $gross = Money::round(
                        Quantity::round($line->quantity)->toBigDecimal()->multipliedBy(Quantity::round($line->rate)->toBigDecimal())
                    );
                    $discount = $gross->minus(Money::round($line->line_total));

                    if (! $discount->isZero()) {
                        DB::table('sale_lines')->where('id', $line->id)->update(['discount_amount' => $discount->toString()]);
                    }
                }
            });

        DB::table('sales')
            ->where('discount_amount', 0)
            ->where('discount', '>', 0)
            ->select(['id', 'discount', 'discount_type', 'taxable_amount'])
            ->chunkById(500, function ($sales) {
                foreach ($sales as $sale) {
                    $percentage = Money::round($sale->discount)->toBigDecimal();

                    if ($sale->discount_type !== 'percentage') {
                        $amount = Money::round($sale->discount);
                    } elseif ($percentage->isGreaterThanOrEqualTo(100)) {
                        $amount = Money::zero();
                    } else {
                        $amount = Money::round($sale->taxable_amount)
                            ->multipliedByFraction($percentage, BigDecimal::of(100)->minus($percentage));
                    }

                    DB::table('sales')->where('id', $sale->id)->update(['discount_amount' => $amount->toString()]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropUnique('sales_fiscal_year_invoice_number_unique');
            $table->dropConstrainedForeignId('reversal_journal_voucher_id');
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropConstrainedForeignId('fiscal_year_id');
            $table->dropColumn([
                'invoice_number',
                'discount_amount',
                'buyer_name',
                'buyer_pan',
                'buyer_address',
                'cancelled_at',
                'cancel_reason',
            ]);
        });

        Schema::table('sale_lines', function (Blueprint $table) {
            $table->dropColumn('discount_amount');
        });
    }
};
