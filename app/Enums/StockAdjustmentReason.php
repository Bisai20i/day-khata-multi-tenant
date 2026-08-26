<?php

namespace App\Enums;

/**
 * Damage/Lost lines are always zero-valued (a written-off item has no cost
 * impact) regardless of what unit_cost_rate a client supplies - enforced in
 * StockAdjustment::post(), not just at the form layer. Opening forces
 * direction to 'in' regardless of what was passed, since opening stock is
 * always an addition.
 */
enum StockAdjustmentReason: string
{
    case Damage = 'damage';
    case Lost = 'lost';
    case Correction = 'correction';
    case Found = 'found';
    case Opening = 'opening';
    case Other = 'other';

    public function isZeroValue(): bool
    {
        return match ($this) {
            self::Damage, self::Lost => true,
            default => false,
        };
    }
}
