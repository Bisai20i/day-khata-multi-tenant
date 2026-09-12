<?php

namespace App\Support\Billing;

use App\Support\Money\Money;

/**
 * The calculated totals of one document (sale, purchase, quotation, return).
 *
 * This is the single source of truth for what a bill adds up to: the
 * controller persists these values, the PDF prints them and the preview in the
 * browser mirrors them step for step. The audit found a quotation totalling
 * three different amounts on three different screens (P0-9) precisely because
 * each screen did its own arithmetic.
 */
final readonly class DocumentTotals
{
    /**
     * @param  list<LineTotals>  $lines
     */
    public function __construct(
        public array $lines,
        public Money $vatableSubtotal,
        public Money $nonVatableSubtotal,
        public Money $headerDiscount,
        public Money $headerDiscountVatable,
        public Money $headerDiscountNonVatable,
        public Money $taxableAmount,
        public Money $nontaxableAmount,
        public string $vatRate,
        public Money $vatAmount,
        public Money $total,
        public Money $tdsAmount,
        public Money $settlementDue,
    ) {}

    /**
     * The subtotal before any header discount. Kept as a method rather than a
     * stored property because it is exactly the sum of the two groups.
     */
    public function subtotal(): Money
    {
        return $this->vatableSubtotal->plus($this->nonVatableSubtotal);
    }

    /**
     * Snake_case keys, string values: the `expected` shape of the golden-vector
     * fixture and of the frontend mirror's `totals`.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'lines' => array_map(static fn (LineTotals $line): array => $line->toArray(), $this->lines),
            'vatable_subtotal' => $this->vatableSubtotal->toString(),
            'non_vatable_subtotal' => $this->nonVatableSubtotal->toString(),
            'header_discount' => $this->headerDiscount->toString(),
            'header_discount_vatable' => $this->headerDiscountVatable->toString(),
            'header_discount_non_vatable' => $this->headerDiscountNonVatable->toString(),
            'taxable_amount' => $this->taxableAmount->toString(),
            'nontaxable_amount' => $this->nontaxableAmount->toString(),
            'vat_rate' => $this->vatRate,
            'vat_amount' => $this->vatAmount->toString(),
            'total' => $this->total->toString(),
            'tds_amount' => $this->tdsAmount->toString(),
            'settlement_due' => $this->settlementDue->toString(),
        ];
    }
}
