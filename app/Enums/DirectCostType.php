<?php

namespace App\Enums;

/**
 * Category of a direct production cost (labour, power, ...) charged to a
 * production entry's work in process. Purely a reporting label on the cost
 * sheet; every type posts the same Dr WIP / Cr payment-or-payable entry.
 */
enum DirectCostType: string
{
    case Labor = 'labor';
    case Utilities = 'utilities';
    case Machine = 'machine';
    case Handling = 'handling';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Labor => 'Labor',
            self::Utilities => 'Utilities',
            self::Machine => 'Machine',
            self::Handling => 'Handling',
            self::Other => 'Other',
        };
    }
}
