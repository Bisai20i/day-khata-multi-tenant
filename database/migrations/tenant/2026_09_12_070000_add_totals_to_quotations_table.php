<?php

use App\Support\Money\Money;
use App\Support\Money\Quantity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A quotation used to store no totals at all, so every screen added the bill
 * up for itself and the three of them disagreed: the create preview applied
 * the header discount to all lines and no VAT, the list and the PDF applied
 * VAT to every line, and the sale the quotation converted into applied VAT to
 * the vatable lines only (audit P0-9). The quote a customer accepted could
 * therefore never be trusted to equal the bill they were later handed.
 *
 * Storing the calculated totals here makes the quotation a real document: one
 * calculation (DocumentCalculator) writes these columns, and every screen just
 * renders them.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->decimal('taxable_amount', 15, 2)->default(0)->after('vat_rate');
            $table->decimal('nontaxable_amount', 15, 2)->default(0)->after('taxable_amount');
            $table->decimal('vat_amount', 15, 2)->default(0)->after('nontaxable_amount');
            $table->decimal('total', 15, 2)->default(0)->after('vat_amount');
        });

        $this->backfillTotals();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropColumn(['taxable_amount', 'nontaxable_amount', 'vat_amount', 'total']);
        });
    }

    /**
     * Fills the new columns for quotations written before they existed.
     *
     * This deliberately repeats the C3 arithmetic with Money/Quantity instead
     * of calling DocumentCalculator: the calculator refuses a document that
     * breaks a rule (a line discount larger than the line, a non-positive
     * subtotal), which is right for a user saving a bill and wrong for a
     * migration that must not abort on a historical oddity. Every step below
     * is still exact decimal arithmetic with the same single roundings.
     *
     * Idempotent: only rows still sitting at the schema default of 0 are
     * touched, and the computation is a pure function of the stored lines.
     */
    private function backfillTotals(): void
    {
        $vatableItemIds = DB::table('items')->where('is_vatable', true)->pluck('id')->all();
        $vatableItemIds = array_flip($vatableItemIds);

        // lazyById(), not each(): the update below takes each row back out of
        // the `total = 0` filter, and offset-based chunking would then skip a
        // whole page of quotations every time it advanced.
        foreach (DB::table('quotations')->where('total', 0)->lazyById() as $quotation) {
            $lines = DB::table('quotation_lines')->where('quotation_id', $quotation->id)->orderBy('id')->get();

            $vatableSubtotal = Money::zero();
            $nonVatableSubtotal = Money::zero();

            foreach ($lines as $line) {
                $gross = Money::round(
                    Quantity::of($line->quantity)->toBigDecimal()->multipliedBy(Quantity::of($line->rate)->toBigDecimal())
                );

                $discount = Money::of($line->discount);
                if ($discount->isNegative()) {
                    $discount = Money::zero();
                }
                if ($discount->isGreaterThan($gross->abs())) {
                    $discount = $gross->abs();
                }

                $lineTotal = $gross->minus($discount);

                if (isset($vatableItemIds[$line->item_id])) {
                    $vatableSubtotal = $vatableSubtotal->plus($lineTotal);
                } else {
                    $nonVatableSubtotal = $nonVatableSubtotal->plus($lineTotal);
                }
            }

            $subtotal = $vatableSubtotal->plus($nonVatableSubtotal);

            $headerDiscount = Money::of($quotation->discount);
            if ($headerDiscount->isNegative()) {
                $headerDiscount = Money::zero();
            }
            if ($headerDiscount->isGreaterThan($subtotal)) {
                $headerDiscount = Money::max($subtotal, Money::zero());
            }

            $headerDiscountVatable = Money::zero();
            $headerDiscountNonVatable = Money::zero();

            if ($headerDiscount->isPositive() && ! $vatableSubtotal->isNegative() && ! $nonVatableSubtotal->isNegative()) {
                [$headerDiscountVatable, $headerDiscountNonVatable] = $headerDiscount
                    ->allocate([$vatableSubtotal, $nonVatableSubtotal]);
            } elseif ($headerDiscount->isPositive()) {
                // A historical row with a negative group cannot be split
                // proportionally without handing out a share with the wrong
                // sign, so the whole discount stays on the vatable side, which
                // is where the legacy code put it anyway.
                $headerDiscountVatable = $headerDiscount;
            }

            $taxableAmount = $vatableSubtotal->minus($headerDiscountVatable);
            $nontaxableAmount = $nonVatableSubtotal->minus($headerDiscountNonVatable);

            $vatRate = Money::of($quotation->vat_rate);
            if ($vatRate->isNegative()) {
                $vatRate = Money::zero();
            }
            if ($vatRate->isGreaterThan(Money::of(100))) {
                $vatRate = Money::of(100);
            }

            $vatAmount = $taxableAmount->percent($vatRate->toBigDecimal());

            DB::table('quotations')->where('id', $quotation->id)->update([
                'taxable_amount' => $taxableAmount->toString(),
                'nontaxable_amount' => $nontaxableAmount->toString(),
                'vat_amount' => $vatAmount->toString(),
                'total' => $taxableAmount->plus($nontaxableAmount)->plus($vatAmount)->toString(),
            ]);
        }
    }
};
