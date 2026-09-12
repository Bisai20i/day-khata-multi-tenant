<?php

namespace App\Support\Billing;

use App\Support\Money\Money;
use App\Support\Money\Quantity;

/**
 * One calculated document line.
 *
 * Everything a caller needs to persist a sale, purchase or quotation line is
 * here, already exact: the stock side takes `baseQuantity`, the money side
 * takes `gross`, `discountAmount` and `lineTotal`. Nothing downstream should
 * recompute any of these from the raw inputs.
 */
final readonly class LineTotals
{
    public function __construct(
        public Quantity $quantity,
        public Quantity $rate,
        public Quantity $conversionFactor,
        public Quantity $baseQuantity,
        public Money $gross,
        public Money $discountAmount,
        public Money $lineTotal,
        public string $discountType,
        public string $discountValue,
        public bool $vatable,
    ) {}

    /**
     * Snake_case, string values: the shape the golden-vector fixture stores and
     * the frontend mirror returns.
     *
     * @return array{quantity: string, rate: string, conversion_factor: string, base_quantity: string,
     *     gross: string, discount_type: string, discount_value: string, discount_amount: string,
     *     line_total: string, vatable: bool}
     */
    public function toArray(): array
    {
        return [
            'quantity' => $this->quantity->toString(),
            'rate' => $this->rate->toString(),
            'conversion_factor' => $this->conversionFactor->toString(),
            'base_quantity' => $this->baseQuantity->toString(),
            'gross' => $this->gross->toString(),
            'discount_type' => $this->discountType,
            'discount_value' => $this->discountValue,
            'discount_amount' => $this->discountAmount->toString(),
            'line_total' => $this->lineTotal->toString(),
            'vatable' => $this->vatable,
        ];
    }
}
