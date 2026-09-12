<?php

namespace App\Support\Money;

use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;

/**
 * An exact rupee amount, held at 2 decimals (paisa).
 *
 * Every amount in the application - line totals, discounts, VAT, TDS, ledger
 * postings, stock values - is a Money. See `DecimalValue` for the rules that
 * govern construction, rounding and comparison.
 */
final class Money extends DecimalValue
{
    protected const SCALE = 2;

    /**
     * `round(this x pct / 100)` with a single rounding.
     *
     * The percentage is a plain decimal with at most 2 decimals (VAT 13,
     * discount 6.27, commission 2.5); it is validated at the edge, not here.
     */
    public function percent(BigDecimal|string|int $pct): self
    {
        return new self(
            $this->value->multipliedBy(self::factor($pct))->dividedBy(100, self::SCALE, RoundingMode::HalfUp)
        );
    }

    /**
     * Splits this amount into parts proportional to the given weights, with
     * the parts always summing back to exactly this amount.
     *
     * Largest remainder at 0.01: every share is floored to a whole paisa, then
     * the leftover paisa are handed out one at a time to the largest fractional
     * remainders, ties going to the lowest index. This is what keeps a header
     * discount split across the VAT and exempt subtotals from inventing or
     * losing a paisa (CONTRACTS C3 step 4).
     *
     * A negative amount is allocated by absolute value and every part is then
     * negated, so a credit note splits exactly like the invoice it mirrors.
     *
     * @param  array<int, self|Quantity|BigDecimal|string|int>  $weights  Non-negative, not all zero.
     * @return list<self>
     */
    public function allocate(array $weights): array
    {
        $weights = array_values($weights);

        if ($weights === []) {
            throw InvalidAmount::invalidWeights('Cannot allocate an amount without any weights.');
        }

        $scale = 0;
        $decimals = [];

        foreach ($weights as $weight) {
            $decimal = self::parse($weight);

            if ($decimal->isNegative()) {
                throw InvalidAmount::invalidWeights(
                    sprintf('Cannot allocate an amount with the negative weight %s.', $decimal->toString())
                );
            }

            $decimals[] = $decimal;
            $scale = max($scale, $decimal->getScale());
        }

        // Bring every weight to a common scale so the split is pure integer
        // arithmetic: no division happens until the exact quotient below.
        $integerWeights = array_map(
            static fn (BigDecimal $decimal): BigInteger => $decimal->toScale($scale)->getUnscaledValue(),
            $decimals
        );

        $totalWeight = BigInteger::sum(...$integerWeights);

        if ($totalWeight->isZero()) {
            throw InvalidAmount::invalidWeights('Cannot allocate an amount when every weight is zero.');
        }

        $paisa = $this->value->abs()->getUnscaledValue();
        $shares = [];
        $remainders = [];
        $allocated = BigInteger::zero();

        foreach ($integerWeights as $index => $weight) {
            [$share, $remainder] = $paisa->multipliedBy($weight)->quotientAndRemainder($totalWeight);
            $shares[$index] = $share;
            $remainders[$index] = $remainder;
            $allocated = $allocated->plus($share);
        }

        $leftover = $paisa->minus($allocated)->toInt();
        $order = array_keys($shares);

        usort($order, static function (int $a, int $b) use ($remainders): int {
            $comparison = $remainders[$b]->compareTo($remainders[$a]);

            return $comparison !== 0 ? $comparison : $a <=> $b;
        });

        for ($i = 0; $i < $leftover; $i++) {
            $index = $order[$i];
            $shares[$index] = $shares[$index]->plus(1);
        }

        $negative = $this->value->isNegative();

        return array_map(function (BigInteger $share) use ($negative): self {
            $part = new self(BigDecimal::ofUnscaledValue($share, self::SCALE));

            return $negative ? $part->negated() : $part;
        }, $shares);
    }

    /**
     * Indian grouping for PDFs and exports: "12,34,567.50", "-1,234.00".
     * Display only; nothing parses this back.
     */
    public function format(): string
    {
        return $this->groupIndianStyle($this->toString());
    }
}
