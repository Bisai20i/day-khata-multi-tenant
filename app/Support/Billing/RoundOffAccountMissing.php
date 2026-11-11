<?php

namespace App\Support\Billing;

use InvalidArgumentException;

/**
 * Raised when a bill carries a Round Off but this tenant's chart of accounts
 * has no INI31 "Round Off" account (a chart customised away from the seeded
 * "Indirect Income" group never received it). A plain, fixable message the
 * create screens show on the Round Off field, instead of a 500.
 */
class RoundOffAccountMissing extends InvalidArgumentException
{
    public static function forCode(string $code): self
    {
        return new self(sprintf(
            'Round off cannot be posted: this chart of accounts has no "%s" Round Off account. '
            .'Add an account with that code under Indirect Income, or leave Round off empty.',
            $code
        ));
    }
}
