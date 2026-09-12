<?php

namespace App\Support\Billing;

use App\Support\Money\DecimalValue;
use App\Support\Money\InvalidAmount;
use App\Support\Money\Money;
use App\Support\Money\Quantity;
use Brick\Math\BigDecimal;

/**
 * The one calculator every document goes through: sales, POS, purchases,
 * quotations (create, list, print and conversion), returns and previews.
 *
 * Pure PHP - no facades, no models, no database - so it can be unit tested
 * against the golden-vector fixture and mirrored step for step by
 * `resources/js/lib/money.js`. Any screen that shows a total must either call
 * this or the JS mirror; nothing is allowed to add up a bill on its own again
 * (audit P0-8, P0-9).
 *
 * Rounding happens at exactly four places, each exactly once:
 *
 * 1. `base_quantity` = r4(quantity x conversion factor)
 * 2. `gross` = r2(quantity x rate)
 * 3. each discount amount (line and header) = r2
 * 4. `vat_amount` = r2, once per document, on the taxable amount after
 *    discounts - never per line, or the sum of the rounded parts would drift
 *    away from the VAT the customer is actually charged.
 *
 * Subtotals and the total are exact sums of values that are already at 2
 * decimals, so they need no rounding at all.
 */
final class DocumentCalculator
{
    /** Percentages (VAT, discounts) carry at most 2 decimals. */
    private const PERCENTAGE_SCALE = 2;

    /**
     * @param  array<int, array<string, mixed>>  $lines  quantity, rate, discount, discount_type,
     *                                                   vatable, conversion_factor
     * @param  array<string, mixed>  $header  vat_rate, discount, discount_type, tds_amount,
     *                                        force_non_taxable, expected_total
     *
     * @throws BillingException
     */
    public static function calculate(array $lines, array $header): DocumentTotals
    {
        $forceNonTaxable = self::boolean($header['force_non_taxable'] ?? false);

        // A PAN (abbreviated tax) invoice carries no VAT at all, so the rate is
        // forced to zero and every line is treated as non-taxable. Doing it
        // here means no caller can forget it.
        $vatRate = $forceNonTaxable
            ? BigDecimal::zero()->toScale(self::PERCENTAGE_SCALE)
            : self::percentageInRange($header['vat_rate'] ?? null, 'The VAT rate', isDiscount: false);

        $lineTotals = [];

        foreach (array_values($lines) as $index => $line) {
            $lineTotals[] = self::calculateLine($line, $index + 1, $forceNonTaxable);
        }

        $vatableSubtotal = Money::sum(array_map(
            static fn (LineTotals $line): Money => $line->vatable ? $line->lineTotal : Money::zero(),
            $lineTotals
        ));

        $nonVatableSubtotal = Money::sum(array_map(
            static fn (LineTotals $line): Money => $line->vatable ? Money::zero() : $line->lineTotal,
            $lineTotals
        ));

        $subtotal = $vatableSubtotal->plus($nonVatableSubtotal);

        if (! $subtotal->isPositive()) {
            throw BillingException::subtotalNotPositive($subtotal->toString());
        }

        $headerDiscount = self::headerDiscount($header, $subtotal);
        $headerDiscountVatable = Money::zero();
        $headerDiscountNonVatable = Money::zero();

        if ($headerDiscount->isPositive()) {
            // Allocating a discount over a negative group would hand out a
            // share with the wrong sign, so the groups are checked first.
            self::assertGroupNotNegative($vatableSubtotal, $nonVatableSubtotal);

            [$headerDiscountVatable, $headerDiscountNonVatable] = $headerDiscount
                ->allocate([$vatableSubtotal, $nonVatableSubtotal]);
        }

        $taxableAmount = $vatableSubtotal->minus($headerDiscountVatable);
        $nontaxableAmount = $nonVatableSubtotal->minus($headerDiscountNonVatable);

        self::assertGroupNotNegative($taxableAmount, $nontaxableAmount);

        $vatAmount = $taxableAmount->percent($vatRate);
        $total = $taxableAmount->plus($nontaxableAmount)->plus($vatAmount);

        if (! $total->isPositive()) {
            throw BillingException::totalNotPositive($total->toString());
        }

        $tdsBase = $taxableAmount->plus($nontaxableAmount);
        $tdsAmount = self::money($header['tds_amount'] ?? null, 'TDS', Money::zero());

        if ($tdsAmount->isNegative() || $tdsAmount->isGreaterThan($tdsBase)) {
            throw BillingException::tdsExceedsBase($tdsAmount->toString(), $tdsBase->toString());
        }

        $totals = new DocumentTotals(
            lines: $lineTotals,
            vatableSubtotal: $vatableSubtotal,
            nonVatableSubtotal: $nonVatableSubtotal,
            headerDiscount: $headerDiscount,
            headerDiscountVatable: $headerDiscountVatable,
            headerDiscountNonVatable: $headerDiscountNonVatable,
            taxableAmount: $taxableAmount,
            nontaxableAmount: $nontaxableAmount,
            vatRate: $vatRate->toScale(self::PERCENTAGE_SCALE)->toString(),
            vatAmount: $vatAmount,
            total: $total,
            tdsAmount: $tdsAmount,
            settlementDue: $total->minus($tdsAmount),
        );

        self::assertExpectedTotal($header, $totals->total);

        return $totals;
    }

    /**
     * A cash/bank split must land on the amount due to the paisa.
     *
     * The audit found `abs((cash + bank) - due) > 0.01` guards on four
     * documents (P0-4): a mismatch inside the tolerance was accepted and left
     * the customer's ledger one paisa out forever.
     *
     * @throws BillingException
     */
    public static function assertExactSplit(Money $due, Money $cash, Money $bank): void
    {
        if ($cash->isNegative() || $bank->isNegative() || ! $cash->plus($bank)->isEqualTo($due)) {
            throw BillingException::splitMismatch($cash->toString(), $bank->toString(), $due->toString());
        }
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private static function calculateLine(array $line, int $number, bool $forceNonTaxable): LineTotals
    {
        $quantity = self::quantity($line['quantity'] ?? null, sprintf('Line %d: the quantity', $number));
        $rate = self::quantity($line['rate'] ?? null, sprintf('Line %d: the rate', $number));

        if ($rate->isNegative()) {
            throw BillingException::negativeRate($number);
        }

        $factor = self::quantity(
            self::valueOrDefault($line, 'conversion_factor', '1'),
            sprintf('Line %d: the unit conversion factor', $number)
        );

        if (! $factor->isPositive()) {
            throw BillingException::invalidConversionFactor($number);
        }

        $baseQuantity = $quantity->multipliedBy($factor);
        $gross = Money::round($quantity->toBigDecimal()->multipliedBy($rate->toBigDecimal()));

        [$discountType, $discountValue, $discountAmount] = self::lineDiscount($line, $number, $gross);

        return new LineTotals(
            quantity: $quantity,
            rate: $rate,
            conversionFactor: $factor,
            baseQuantity: $baseQuantity,
            gross: $gross,
            discountAmount: $discountAmount,
            lineTotal: $gross->minus($discountAmount),
            discountType: $discountType,
            discountValue: $discountValue,
            vatable: $forceNonTaxable ? false : self::boolean($line['vatable'] ?? false),
        );
    }

    /**
     * A line discount always moves the line toward zero, so on a negative line
     * (a returned item inside a sale) the discount is negative too. That keeps
     * "10% off" and "20 off" meaning the same thing whichever sign the line has.
     *
     * @param  array<string, mixed>  $line
     * @return array{0: string, 1: string, 2: Money}
     */
    private static function lineDiscount(array $line, int $number, Money $gross): array
    {
        $type = self::discountType($line['discount_type'] ?? null, sprintf('Line %d', $number));
        $raw = self::valueOrDefault($line, 'discount', '0');
        $label = sprintf('Line %d: the discount', $number);
        $magnitude = $gross->abs();

        if ($type === 'percentage') {
            $percentage = self::percentageInRange($raw, $label);
            $amount = $magnitude->percent($percentage);
            $value = $percentage->toScale(self::PERCENTAGE_SCALE)->toString();
        } else {
            $flat = self::money($raw, $label, Money::zero());

            if ($flat->isNegative()) {
                throw BillingException::negativeDiscount($label);
            }

            if ($flat->isGreaterThan($magnitude)) {
                throw BillingException::lineDiscountExceedsLine($number, $flat->toString(), $magnitude->toString());
            }

            $amount = $flat;
            $value = $flat->toString();
        }

        return [$type, $value, $gross->isNegative() ? $amount->negated() : $amount];
    }

    /**
     * @param  array<string, mixed>  $header
     */
    private static function headerDiscount(array $header, Money $subtotal): Money
    {
        $type = self::discountType($header['discount_type'] ?? null, 'The document discount');
        $raw = self::valueOrDefault($header, 'discount', '0');
        $label = 'The document discount';

        if ($type === 'percentage') {
            return $subtotal->percent(self::percentageInRange($raw, $label));
        }

        $flat = self::money($raw, $label, Money::zero());

        if ($flat->isNegative()) {
            throw BillingException::negativeDiscount($label);
        }

        if ($flat->isGreaterThan($subtotal)) {
            throw BillingException::headerDiscountExceedsSubtotal($flat->toString(), $subtotal->toString());
        }

        return $flat;
    }

    /**
     * The browser sends the total it showed the user; if the server arrives at
     * anything else the bill on screen was not the bill being saved, so the
     * save is refused rather than quietly booking a different amount.
     *
     * @param  array<string, mixed>  $header
     */
    private static function assertExpectedTotal(array $header, Money $total): void
    {
        $expected = $header['expected_total'] ?? null;

        if ($expected === null || $expected === '') {
            return;
        }

        $expected = self::money($expected, 'The expected total', Money::zero());

        if (! $expected->isEqualTo($total)) {
            throw BillingException::totalMismatch($expected->toString(), $total->toString());
        }
    }

    private static function assertGroupNotNegative(Money $vatable, Money $nonVatable): void
    {
        if ($vatable->isNegative()) {
            throw BillingException::negativeGroupTotal('taxable', $vatable->toString());
        }

        if ($nonVatable->isNegative()) {
            throw BillingException::negativeGroupTotal('exempt', $nonVatable->toString());
        }
    }

    private static function discountType(mixed $type, string $label): string
    {
        if ($type === null || $type === '') {
            return 'flat';
        }

        if ($type !== 'flat' && $type !== 'percentage') {
            throw BillingException::invalidNumber(
                sprintf('%s must be a flat amount or a percentage.', $label)
            );
        }

        return $type;
    }

    private static function quantity(mixed $value, string $label): Quantity
    {
        if ($value === null || $value === '') {
            throw BillingException::invalidNumber(sprintf('%s is required.', $label));
        }

        try {
            return Quantity::of(self::scalar($value, $label));
        } catch (InvalidAmount $exception) {
            throw BillingException::fromInvalidAmount($exception, $label);
        }
    }

    private static function money(mixed $value, string $label, ?Money $default = null): Money
    {
        if ($value === null || $value === '') {
            if ($default === null) {
                throw BillingException::invalidNumber(sprintf('%s is required.', $label));
            }

            return $default;
        }

        try {
            return Money::of(self::scalar($value, $label));
        } catch (InvalidAmount $exception) {
            throw BillingException::fromInvalidAmount($exception, $label);
        }
    }

    /**
     * A percentage: at most 2 decimals, between 0 and 100.
     *
     * A negative discount percentage is reported as a negative discount, since
     * that names what the user actually typed; any other negative percentage
     * (the VAT rate) is simply out of range.
     */
    private static function percentageInRange(mixed $value, string $label, bool $isDiscount = true): BigDecimal
    {
        if ($value === null || $value === '') {
            throw BillingException::invalidNumber(sprintf('%s is required.', $label));
        }

        try {
            $decimal = DecimalValue::parse(self::scalar($value, $label));
        } catch (InvalidAmount $exception) {
            throw BillingException::fromInvalidAmount($exception, $label);
        }

        if ($decimal->strippedOfTrailingZeros()->getScale() > self::PERCENTAGE_SCALE) {
            throw BillingException::tooManyDecimals($label, self::PERCENTAGE_SCALE);
        }

        if ($decimal->isNegative() && $isDiscount) {
            throw BillingException::negativeDiscount($label);
        }

        if ($decimal->isNegative() || $decimal->isGreaterThan(100)) {
            throw BillingException::percentageOutOfRange($label);
        }

        return $decimal->toScale(self::PERCENTAGE_SCALE);
    }

    /**
     * Request payloads can hold anything; only the scalar types the value
     * objects accept are allowed through.
     */
    private static function scalar(mixed $value, string $label): DecimalValue|BigDecimal|string|int|float
    {
        if ($value instanceof DecimalValue || $value instanceof BigDecimal
            || is_string($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        throw BillingException::invalidNumber(sprintf('%s is not a valid number.', $label));
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private static function valueOrDefault(array $source, string $key, string $default): mixed
    {
        $value = $source[$key] ?? null;

        return ($value === null || $value === '') ? $default : $value;
    }

    /**
     * Form and JSON payloads send booleans as "0", "false", 0 or absent; only
     * a real truthy value marks a line as vatable.
     */
    private static function boolean(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true;
    }
}
