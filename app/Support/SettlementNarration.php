<?php

namespace App\Support;

/**
 * Ledger narration for a settlement leg, ported from legacy day_khata's
 * uncommitted `SettlementNarration` helper (audit section 3 "Sales": "ledger
 * narrations with document number and settlement mode").
 *
 * Every sale, sales return and receipt voucher line is given this narration
 * (see Sale::post(), SalesReturn::postCreditNote()/postRefund() and
 * Receipt::post()), so a customer's ledger reads "SL-42 - Cash Settlement"
 * instead of a bare "Sale total"/"Settlement", telling a sale from a receipt
 * and cash from bank apart at a glance. T13 uses these same two methods for
 * purchases in parallel, so the signatures here are shared and fixed -
 * do not change them without updating both modules.
 */
final class SettlementNarration
{
    /**
     * The human label for a settlement mode. `null` (a credit sale, or a
     * document - like a credit note - that has no settlement leg of its own)
     * and anything not recognised both read as "Credit".
     */
    public static function forMode(?string $mode): string
    {
        return match ($mode) {
            'cash' => 'Cash Settlement',
            'bank' => 'Bank Settlement',
            'partial' => 'Partial',
            default => 'Credit',
        };
    }

    /**
     * "{document number} - {forMode}", e.g. "SL-42 - Cash Settlement".
     */
    public static function line(string $documentNumber, ?string $mode): string
    {
        return "{$documentNumber} - ".self::forMode($mode);
    }
}
