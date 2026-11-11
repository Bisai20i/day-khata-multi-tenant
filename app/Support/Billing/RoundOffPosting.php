<?php

namespace App\Support\Billing;

use App\Models\Account;
use App\Support\Money\Money;

/**
 * The one voucher line a signed Round Off adds to a sale or a purchase.
 *
 * Round Off is part of the bill total (the party leg already posts the full
 * `total`), so the other half of that difference has to land somewhere or the
 * voucher would not balance. It lands on INI31 "Round Off", one system account
 * shared by both modules and both signs: a net credit is income, a net debit a
 * loss, and the fiscal-year close sweeps it into Profit & Loss with the rest
 * of the Income head.
 *
 * - Sale: + adds to what the customer owes, so INI31 is CREDITED; - debits it.
 * - Purchase: + adds to what we owe the supplier, so INI31 is DEBITED; - credits it.
 */
final class RoundOffPosting
{
    public const ACCOUNT_CODE = 'INI31';

    /**
     * Whether a raw request value is a negative Round Off, for choosing which
     * field a "total must be positive" failure is shown on.
     */
    public static function isNegativeInput(mixed $value): bool
    {
        return is_scalar($value) && str_starts_with(trim((string) $value), '-');
    }

    /**
     * @return array{account_id: int, debit: string, credit: string, narration: string}|null Null when there is no Round Off.
     */
    public static function line(Money $roundOff, bool $creditWhenPositive): ?array
    {
        if ($roundOff->isZero()) {
            return null;
        }

        $amount = $roundOff->abs()->toString();
        $zero = Money::zero()->toString();
        $credit = $roundOff->isPositive() === $creditWhenPositive;

        $accountId = Account::where('code', self::ACCOUNT_CODE)->value('id')
            ?? throw RoundOffAccountMissing::forCode(self::ACCOUNT_CODE);

        return [
            'account_id' => $accountId,
            'debit' => $credit ? $zero : $amount,
            'credit' => $credit ? $amount : $zero,
            'narration' => 'Round off',
        ];
    }
}
