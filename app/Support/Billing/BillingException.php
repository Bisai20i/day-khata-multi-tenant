<?php

namespace App\Support\Billing;

use App\Support\Money\InvalidAmount;
use InvalidArgumentException;

/**
 * Every way a document can fail to add up.
 *
 * `$reason` is a fixed machine code shared with the frontend money module
 * (`resources/js/lib/money.js`) and with the golden-vector fixture, so the same
 * input fails the same way on both sides of the wire. The message is plain
 * English for the person filling in the bill.
 */
class BillingException extends InvalidArgumentException
{
    public const REASON_TOO_MANY_DECIMALS = 'too_many_decimals';

    public const REASON_INVALID_NUMBER = 'invalid_number';

    public const REASON_NEGATIVE_RATE = 'negative_rate';

    public const REASON_INVALID_CONVERSION_FACTOR = 'invalid_conversion_factor';

    public const REASON_PERCENTAGE_OUT_OF_RANGE = 'percentage_out_of_range';

    public const REASON_NEGATIVE_DISCOUNT = 'negative_discount';

    public const REASON_LINE_DISCOUNT_EXCEEDS_LINE = 'line_discount_exceeds_line';

    public const REASON_SUBTOTAL_NOT_POSITIVE = 'subtotal_not_positive';

    public const REASON_HEADER_DISCOUNT_EXCEEDS_SUBTOTAL = 'header_discount_exceeds_subtotal';

    public const REASON_NEGATIVE_GROUP_TOTAL = 'negative_group_total';

    public const REASON_TOTAL_NOT_POSITIVE = 'total_not_positive';

    public const REASON_TDS_EXCEEDS_BASE = 'tds_exceeds_base';

    public const REASON_TOTAL_MISMATCH = 'total_mismatch';

    public const REASON_SPLIT_MISMATCH = 'split_mismatch';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    /**
     * Re-throws a value-object parsing failure with the billing code that
     * matches it, so callers only ever have to handle BillingException.
     */
    public static function fromInvalidAmount(InvalidAmount $exception, string $label): self
    {
        $reason = $exception->reason === InvalidAmount::REASON_TOO_MANY_DECIMALS
            ? self::REASON_TOO_MANY_DECIMALS
            : self::REASON_INVALID_NUMBER;

        return new self($reason, sprintf('%s: %s', $label, $exception->getMessage()));
    }

    public static function invalidNumber(string $message): self
    {
        return new self(self::REASON_INVALID_NUMBER, $message);
    }

    public static function tooManyDecimals(string $label, int $scale): self
    {
        return new self(
            self::REASON_TOO_MANY_DECIMALS,
            sprintf('%s can have at most %d decimal places.', $label, $scale)
        );
    }

    public static function negativeRate(int $line): self
    {
        return new self(self::REASON_NEGATIVE_RATE, sprintf('Line %d: the rate cannot be negative.', $line));
    }

    public static function invalidConversionFactor(int $line): self
    {
        return new self(
            self::REASON_INVALID_CONVERSION_FACTOR,
            sprintf('Line %d: the unit conversion factor must be greater than zero.', $line)
        );
    }

    public static function percentageOutOfRange(string $label): self
    {
        return new self(
            self::REASON_PERCENTAGE_OUT_OF_RANGE,
            sprintf('%s must be between 0 and 100 percent.', $label)
        );
    }

    public static function negativeDiscount(string $label): self
    {
        return new self(self::REASON_NEGATIVE_DISCOUNT, sprintf('%s cannot be negative.', $label));
    }

    public static function lineDiscountExceedsLine(int $line, string $discount, string $lineValue): self
    {
        return new self(
            self::REASON_LINE_DISCOUNT_EXCEEDS_LINE,
            sprintf('Line %d: the discount %s is more than the line value %s.', $line, $discount, $lineValue)
        );
    }

    public static function subtotalNotPositive(string $subtotal): self
    {
        return new self(
            self::REASON_SUBTOTAL_NOT_POSITIVE,
            sprintf('The subtotal of this document is %s. It must be greater than zero.', $subtotal)
        );
    }

    public static function headerDiscountExceedsSubtotal(string $discount, string $subtotal): self
    {
        return new self(
            self::REASON_HEADER_DISCOUNT_EXCEEDS_SUBTOTAL,
            sprintf('The discount %s is more than the subtotal %s.', $discount, $subtotal)
        );
    }

    public static function negativeGroupTotal(string $label, string $amount): self
    {
        return new self(
            self::REASON_NEGATIVE_GROUP_TOTAL,
            sprintf('The %s total is %s. A document cannot have a negative taxable or exempt total.', $label, $amount)
        );
    }

    public static function totalNotPositive(string $total): self
    {
        return new self(
            self::REASON_TOTAL_NOT_POSITIVE,
            sprintf('The document total is %s. It must be greater than zero.', $total)
        );
    }

    public static function tdsExceedsBase(string $tds, string $base): self
    {
        return new self(
            self::REASON_TDS_EXCEEDS_BASE,
            sprintf('TDS of %s must be between 0 and the bill value of %s.', $tds, $base)
        );
    }

    public static function totalMismatch(string $expected, string $actual): self
    {
        return new self(
            self::REASON_TOTAL_MISMATCH,
            sprintf('The bill total changed from %s to %s. Please review it before saving.', $expected, $actual)
        );
    }

    public static function splitMismatch(string $cash, string $bank, string $due): self
    {
        return new self(
            self::REASON_SPLIT_MISMATCH,
            sprintf('Cash %s and bank %s must add up to exactly %s.', $cash, $bank, $due)
        );
    }
}
