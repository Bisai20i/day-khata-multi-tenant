<?php

namespace App\Support\Money;

use InvalidArgumentException;

/**
 * Thrown whenever a value cannot become an exact decimal at the scale the
 * caller asked for.
 *
 * Money is never silently rounded in this application: a rate with five
 * decimals, a hand-typed "1,200.00" or a float that only looks like 0.1 all
 * stop here instead of reaching MySQL, which would round the value on insert
 * and leave the stored bill different from the printed one (audit P0-5).
 *
 * `$reason` carries the shared error code from CONTRACTS C3 so that
 * `App\Support\Billing\DocumentCalculator` can re-throw the failure as a
 * `BillingException` with the same code the frontend money module uses.
 */
class InvalidAmount extends InvalidArgumentException
{
    public const REASON_INVALID_NUMBER = 'invalid_number';

    public const REASON_TOO_MANY_DECIMALS = 'too_many_decimals';

    public function __construct(string $message, public readonly string $reason = self::REASON_INVALID_NUMBER)
    {
        parent::__construct($message);
    }

    /**
     * The value is not a decimal number this application accepts: a stray
     * comma, an exponent, an empty string, NAN/INF or a non-numeric type.
     */
    public static function notANumber(mixed $value): self
    {
        return new self(
            sprintf('The value %s is not a valid decimal number.', self::describe($value)),
            self::REASON_INVALID_NUMBER
        );
    }

    /**
     * The value is a number, but carries more decimals than the column or
     * value object can hold, so accepting it would lose money.
     */
    public static function tooManyDecimals(string $value, int $scale): self
    {
        return new self(
            sprintf('The value %s has more than %d decimal places.', $value, $scale),
            self::REASON_TOO_MANY_DECIMALS
        );
    }

    /**
     * The same failure, raised by the Eloquent cast, where naming the
     * attribute is what makes the error actionable.
     */
    public static function forAttribute(string $value, int $scale, string $key): self
    {
        return new self(
            sprintf('Refusing to silently round %s to %d decimals for attribute %s', $value, $scale, $key),
            self::REASON_TOO_MANY_DECIMALS
        );
    }

    public static function divisionByZero(): self
    {
        return new self('Cannot divide a decimal value by zero.');
    }

    public static function emptySet(string $method): self
    {
        return new self(sprintf('%s() needs at least one value.', $method));
    }

    public static function invalidWeights(string $message): self
    {
        return new self($message);
    }

    /**
     * A short, safe rendering of any input for the exception message; objects
     * and arrays must never be string-cast blindly.
     */
    private static function describe(mixed $value): string
    {
        return match (true) {
            is_string($value) => '"'.$value.'"',
            is_int($value) => (string) $value,
            is_float($value) => is_nan($value) ? 'NAN' : (is_infinite($value) ? 'INF' : var_export($value, true)),
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            is_object($value) => 'an instance of '.$value::class,
            default => get_debug_type($value),
        };
    }
}
