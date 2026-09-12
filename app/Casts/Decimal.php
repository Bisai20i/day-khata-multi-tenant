<?php

namespace App\Casts;

use App\Support\Money\DecimalValue;
use App\Support\Money\InvalidAmount;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * The replacement for Laravel's `decimal:N` cast on every money, quantity,
 * rate and percentage column.
 *
 * Usage: `'total' => Decimal::class.':2'`, `'quantity' => Decimal::class.':4'`.
 *
 * Reading behaves exactly like `decimal:N` (a string at the scale), so nothing
 * that already reads these attributes has to change. Writing is what differs:
 * `decimal:N` happily accepted a value with more decimals than the column and
 * let MySQL round it on insert, which is how a quantity of 0.00004 could be
 * charged for and then stored as 0.0000 with no stock movement (audit P0-5).
 * Here that write throws instead, naming the attribute, so the bug surfaces at
 * the line that created it rather than as a silent rupee difference weeks later.
 */
class Decimal implements CastsAttributes
{
    private readonly int $scale;

    /**
     * Laravel hands cast parameters over as strings (`Decimal::class.':2'`),
     * so the scale is normalised here rather than type-hinted as an int.
     */
    public function __construct(int|string $scale = 2)
    {
        $this->scale = (int) $scale;
    }

    /**
     * @return string|null A decimal string at exactly the configured scale.
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        /** @var DecimalValue|BigDecimal|string|int|float $value */
        return DecimalValue::parse($value)->toScale($this->scale, RoundingMode::HalfUp)->toString();
    }

    /**
     * @throws InvalidAmount When the value carries more decimals than the column can hold.
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! $value instanceof DecimalValue && ! $value instanceof BigDecimal
            && ! is_string($value) && ! is_int($value) && ! is_float($value)) {
            throw InvalidAmount::notANumber($value);
        }

        $decimal = DecimalValue::parse($value);
        $stored = $decimal->toScale($this->scale, RoundingMode::HalfUp);

        if (! $stored->isEqualTo($decimal)) {
            throw InvalidAmount::forAttribute($decimal->toString(), $this->scale, $key);
        }

        return $stored->toString();
    }
}
