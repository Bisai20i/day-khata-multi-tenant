<?php

namespace App\Enums;

/**
 * The five statutory Nepali income-tax depreciation pools. The rate is
 * only a convenience pre-fill on the asset entry form - it stays editable
 * per asset. Pool E has no fixed statutory rate (it is amortised over the
 * asset's useful life instead), so it pre-fills nothing.
 */
enum DepreciationPool: string
{
    case PoolA = 'Pool A';
    case PoolB = 'Pool B';
    case PoolC = 'Pool C';
    case PoolD = 'Pool D';
    case PoolE = 'Pool E';

    public function defaultRate(): ?float
    {
        return match ($this) {
            self::PoolA => 5.0,
            self::PoolB => 25.0,
            self::PoolC => 20.0,
            self::PoolD => 15.0,
            self::PoolE => null,
        };
    }
}
