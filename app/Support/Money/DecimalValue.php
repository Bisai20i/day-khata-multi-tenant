<?php

namespace App\Support\Money;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use JsonSerializable;
use Stringable;

/**
 * Shared machinery behind `Money` (scale 2) and `Quantity` (scale 4).
 *
 * Both are immutable wrappers around `Brick\Math\BigDecimal` pinned to a fixed
 * scale, so every amount in the application is an exact decimal instead of a
 * float. The 2026-09-11 audit measured 0.39% of 2dp x 2dp products rounding the
 * wrong way under PHP's `round()` (1.5 x 33.33 billed 49.99 instead of 50.00,
 * 28.5 x 14209.99 billed a paisa short); nothing in here uses float arithmetic
 * at any point, so that class of bug cannot come back.
 *
 * Rules that hold for every subclass:
 *
 * - Construction is strict. A value with more decimals than the scale throws
 *   rather than rounding, because a silently rounded input is a bill that does
 *   not match what the customer was quoted. `round()` is the single, explicit
 *   door for values that are allowed to lose precision.
 * - Arithmetic is exact except at the documented rounding points, where the
 *   product is computed in full and rounded exactly once, HalfUp (away from
 *   zero). HalfUp is what MySQL's DECIMAL insert rounding does, so a value
 *   computed here and a value rounded by the database always agree, and it is
 *   symmetric for the negative lines that credit notes and returns produce.
 * - Comparisons are exact. The audit found +-0.01 and +-0.0001 tolerances
 *   accepting real one-paisa errors (P0-4); there are no tolerances here.
 */
abstract class DecimalValue implements JsonSerializable, Stringable
{
    /**
     * Number of decimals every instance of the subclass is held at.
     * Overridden by `Money` (2) and `Quantity` (4).
     */
    protected const SCALE = 2;

    /**
     * A plain decimal literal: optional sign, digits, optional fractional
     * part. Deliberately narrower than brick/math's own parser, which also
     * accepts exponent notation ("1e3") and rationals ("1/3"): neither is a
     * thing a user or a column ever legitimately sends us, and accepting them
     * would hide a broken input path.
     */
    private const DECIMAL_PATTERN = '/^[+-]?[0-9]+(\.[0-9]+)?$/';

    final protected function __construct(protected readonly BigDecimal $value) {}

    /**
     * Strict conversion: the value must already fit the scale exactly.
     *
     * Floats are accepted because JSON request bodies decode numbers as
     * floats, but they are converted through PHP's shortest round-trip
     * literal, so 0.1 becomes "0.1" and never 0.1000000000000000055...;
     * a float that genuinely carries more decimals than the scale (the
     * 0.30000000000000004 of a broken client-side sum) still throws.
     */
    public static function of(self|BigDecimal|string|int|float $value): static
    {
        $decimal = static::parse($value);
        $stripped = $decimal->strippedOfTrailingZeros();

        if ($stripped->getScale() > static::SCALE) {
            throw InvalidAmount::tooManyDecimals($decimal->toString(), static::SCALE);
        }

        return new static($decimal->toScale(static::SCALE, RoundingMode::Unnecessary));
    }

    /**
     * `null` and the empty string (an untouched optional form field) mean
     * "no value"; everything else goes through `of()`.
     */
    public static function ofNullable(mixed $value): ?static
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! $value instanceof self && ! $value instanceof BigDecimal
            && ! is_string($value) && ! is_int($value) && ! is_float($value)) {
            throw InvalidAmount::notANumber($value);
        }

        return static::of($value);
    }

    /**
     * The only sanctioned way to round: HalfUp to the scale.
     *
     * Use it where a value legitimately arrives with more precision than we
     * store (a computed product, a percentage share), never to paper over an
     * over-precise user input.
     */
    public static function round(self|BigDecimal|string|int|float $value): static
    {
        return new static(static::parse($value)->toScale(static::SCALE, RoundingMode::HalfUp));
    }

    public static function zero(): static
    {
        return new static(BigDecimal::zero()->toScale(static::SCALE));
    }

    /**
     * Exact sum. An empty set sums to zero, which is what every "total of the
     * lines I have so far" caller wants.
     *
     * @param  iterable<self|BigDecimal|string|int|float>  $values
     */
    public static function sum(iterable $values): static
    {
        $total = static::zero();

        foreach ($values as $value) {
            $total = $total->plus($value);
        }

        return $total;
    }

    public static function max(self|BigDecimal|string|int|float ...$values): static
    {
        return static::extreme('max', $values, 1);
    }

    public static function min(self|BigDecimal|string|int|float ...$values): static
    {
        return static::extreme('min', $values, -1);
    }

    public function plus(self|BigDecimal|string|int|float $that): static
    {
        return new static($this->value->plus(static::of($that)->value));
    }

    public function minus(self|BigDecimal|string|int|float $that): static
    {
        return new static($this->value->minus(static::of($that)->value));
    }

    public function negated(): static
    {
        return new static($this->value->negated());
    }

    public function abs(): static
    {
        return new static($this->value->abs());
    }

    /**
     * Exact product, then exactly one HalfUp rounding back to this class's
     * scale. `Money x Quantity` gives Money (2dp), `Quantity x Quantity` gives
     * Quantity (4dp): the receiver decides the scale of the answer.
     *
     * Floats are not accepted here on purpose; wrap them in the matching value
     * object first so the strict decimal check runs.
     */
    public function multipliedBy(self|BigDecimal|string|int $factor): static
    {
        return new static(
            $this->value->multipliedBy(static::factor($factor))->toScale(static::SCALE, RoundingMode::HalfUp)
        );
    }

    /**
     * `round(this x numerator / denominator)` with a single rounding.
     *
     * This is the proportional-share primitive used by partial returns: the
     * audit found them re-deriving amounts as `total / qty * returnQty`, which
     * rounds twice and loses a paisa per return (P0-3).
     */
    public function multipliedByFraction(
        self|BigDecimal|string|int $numerator,
        self|BigDecimal|string|int $denominator
    ): static {
        $denominator = static::factor($denominator);

        if ($denominator->isZero()) {
            throw InvalidAmount::divisionByZero();
        }

        return new static(
            $this->value
                ->multipliedBy(static::factor($numerator))
                ->dividedBy($denominator, static::SCALE, RoundingMode::HalfUp)
        );
    }

    public function compareTo(self|BigDecimal|string|int|float $that): int
    {
        return $this->value->compareTo(static::of($that)->value);
    }

    public function isEqualTo(self|BigDecimal|string|int|float $that): bool
    {
        return $this->compareTo($that) === 0;
    }

    public function isGreaterThan(self|BigDecimal|string|int|float $that): bool
    {
        return $this->compareTo($that) > 0;
    }

    public function isGreaterThanOrEqualTo(self|BigDecimal|string|int|float $that): bool
    {
        return $this->compareTo($that) >= 0;
    }

    public function isLessThan(self|BigDecimal|string|int|float $that): bool
    {
        return $this->compareTo($that) < 0;
    }

    public function isLessThanOrEqualTo(self|BigDecimal|string|int|float $that): bool
    {
        return $this->compareTo($that) <= 0;
    }

    public function isZero(): bool
    {
        return $this->value->isZero();
    }

    public function isPositive(): bool
    {
        return $this->value->isPositive();
    }

    public function isNegative(): bool
    {
        return $this->value->isNegative();
    }

    public function toBigDecimal(): BigDecimal
    {
        return $this->value;
    }

    /**
     * Always exactly SCALE decimals, "." separator, no grouping: the shape
     * MySQL DECIMAL columns and JSON responses expect. A zero value prints
     * "0.00" / "0.0000" and never "-0.00", since BigDecimal has no signed zero.
     */
    public function toString(): string
    {
        return $this->value->toString();
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    public function jsonSerialize(): string
    {
        return $this->toString();
    }

    /**
     * ONLY for a chart series or a numeric Excel cell. Never for arithmetic or
     * comparison: that is exactly the precision loss these classes exist to
     * prevent.
     */
    public function toFloat(): float
    {
        return $this->value->toFloat();
    }

    /**
     * Converts any accepted input to a BigDecimal without touching its scale.
     * Shared with `App\Casts\Decimal`, which needs the same input rules but
     * reports over-precision against the attribute name instead.
     */
    public static function parse(self|BigDecimal|string|int|float $value): BigDecimal
    {
        if ($value instanceof self) {
            return $value->value;
        }

        if ($value instanceof BigDecimal) {
            return $value;
        }

        if (is_int($value)) {
            return BigDecimal::of($value);
        }

        if (is_float($value)) {
            return BigDecimal::of(static::floatLiteral($value));
        }

        if (preg_match(self::DECIMAL_PATTERN, $value) !== 1) {
            throw InvalidAmount::notANumber($value);
        }

        try {
            return BigDecimal::of($value);
        } catch (MathException) {
            throw InvalidAmount::notANumber($value);
        }
    }

    /**
     * PHP's shortest round-trip decimal literal for a float.
     *
     * `serialize_precision` is forced to -1 for the call because a php.ini that
     * sets it to 17 would turn 0.1 into "0.10000000000000001" and make every
     * float input throw. NAN and INF have no decimal literal at all.
     */
    private static function floatLiteral(float $value): string
    {
        if (is_nan($value) || is_infinite($value)) {
            throw InvalidAmount::notANumber($value);
        }

        $previous = ini_set('serialize_precision', '-1');

        try {
            return var_export($value, true);
        } finally {
            if ($previous !== false) {
                ini_set('serialize_precision', $previous);
            }
        }
    }

    /**
     * A multiplier or divisor. Unlike an amount it is not held at this class's
     * scale (a conversion factor of 0.0833 may multiply a 2dp Money), so only
     * the literal format is validated.
     */
    protected static function factor(self|BigDecimal|string|int $factor): BigDecimal
    {
        return static::parse($factor);
    }

    /**
     * @param  list<self|BigDecimal|string|int|float>  $values
     * @param  int  $keep  1 to keep the greater value, -1 to keep the lesser
     */
    private static function extreme(string $method, array $values, int $keep): static
    {
        if ($values === []) {
            throw InvalidAmount::emptySet($method);
        }

        $best = null;

        foreach ($values as $value) {
            $candidate = static::of($value);

            if ($best === null || $candidate->compareTo($best) === $keep) {
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * Indian digit grouping ("12,34,567.50"): the last three integer digits,
     * then pairs. Used for PDFs and exports, never for a value that is parsed
     * back.
     */
    protected function groupIndianStyle(string $formatted): string
    {
        $sign = '';

        if (str_starts_with($formatted, '-')) {
            $sign = '-';
            $formatted = substr($formatted, 1);
        }

        $parts = explode('.', $formatted, 2);
        $integer = $parts[0];
        $fraction = $parts[1] ?? null;

        if (strlen($integer) > 3) {
            $head = substr($integer, 0, -3);
            $tail = substr($integer, -3);
            $head = preg_replace('/\B(?=(\d{2})+$)/', ',', $head);
            $integer = $head.','.$tail;
        }

        return $sign.$integer.($fraction === null ? '' : '.'.$fraction);
    }
}
