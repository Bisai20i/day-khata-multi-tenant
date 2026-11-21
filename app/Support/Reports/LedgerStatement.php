<?php

namespace App\Support\Reports;

use App\Models\Account;
use App\Models\FiscalYear;
use App\Support\Money\Money;
use Illuminate\Support\Str;

/**
 * The ledger statement's arithmetic and naming: running balance with a
 * Dr/Cr side, period totals, the fiscal-year date window and the export
 * file name.
 *
 * Pure and DB-free: LedgerBook does the querying and hands the mapped rows
 * in; both ledger pages, the PDF and the Excel export read what comes out.
 * Every amount is computed with Money and leaves as a Money string, so a
 * balance that lands on zero prints "0.00" and never "-0.00".
 *
 * @phpstan-type Entry array<string, mixed>
 * @phpstan-type Statement array{entries: list<Entry>, openingBalance: string, openingSide: string, closingBalance: string, closingSide: string, periodDebit: string, periodCredit: string}
 */
final class LedgerStatement
{
    /**
     * The side a signed (debit-positive) balance sits on: "Dr" above zero,
     * "Cr" below, and "" for exactly zero - a zero balance has no side.
     *
     * @return 'Dr'|'Cr'|''
     */
    public static function side(Money $balance): string
    {
        if ($balance->isZero()) {
            return '';
        }

        return $balance->isNegative() ? 'Cr' : 'Dr';
    }

    /**
     * Runs the balance down $rows from $opening.
     *
     * Each row keeps every key it arrived with; `debit` and `credit` are
     * normalised to Money strings and `balance` (signed Money string) and
     * `side` are added.
     *
     * @param  string  $opening  Signed Money string (debit minus credit).
     * @param  list<array<string, mixed>>  $rows  In display order; each has date, dateBs, voucherType, voucherNumber, narration, mainNarration, debit, credit (exact strings) plus any extra keys, passed through untouched.
     * @return Statement
     */
    public static function build(string $opening, array $rows): array
    {
        $openingBalance = Money::of($opening);
        $runningBalance = $openingBalance;
        $debits = [];
        $credits = [];
        $entries = [];

        foreach (array_values($rows) as $row) {
            $debit = Money::of($row['debit'] ?? '0');
            $credit = Money::of($row['credit'] ?? '0');

            $runningBalance = $runningBalance->plus($debit)->minus($credit);
            $debits[] = $debit;
            $credits[] = $credit;

            $row['debit'] = $debit->toString();
            $row['credit'] = $credit->toString();
            $row['balance'] = $runningBalance->toString();
            $row['side'] = self::side($runningBalance);

            $entries[] = $row;
        }

        return [
            'entries' => $entries,
            'openingBalance' => $openingBalance->toString(),
            'openingSide' => self::side($openingBalance),
            'closingBalance' => $runningBalance->toString(),
            'closingSide' => self::side($runningBalance),
            'periodDebit' => Money::sum($debits)->toString(),
            'periodCredit' => Money::sum($credits)->toString(),
        ];
    }

    /**
     * The requested window clamped inside the fiscal year - the same rules as
     * AccountingReportController::resolveWindow(): an empty bound falls back
     * to the year's own bound, each bound is pulled inside the year (Y-m-d
     * strings compare correctly as strings), and a backwards window becomes
     * the whole year rather than an empty page.
     *
     * @return array{0: string, 1: string}
     */
    public static function window(FiscalYear $fiscalYear, ?string $requestedFrom, ?string $requestedTo): array
    {
        $start = $fiscalYear->start_date->toDateString();
        $end = $fiscalYear->end_date->toDateString();

        $requestedFrom ??= '';
        $requestedTo ??= '';

        $from = $requestedFrom !== '' ? max($requestedFrom, $start) : $start;
        $to = $requestedTo !== '' ? min($requestedTo, $end) : $end;

        return $from > $to ? [$start, $end] : [$from, $to];
    }

    /**
     * "ledger-{slug(code or name)}-{slug(fy name) or fy id}.{ext}".
     */
    public static function fileName(Account $account, FiscalYear $fiscalYear, string $extension): string
    {
        $accountCode = (string) ($account->code ?? '');
        $accountSlug = Str::slug($accountCode !== '' ? $accountCode : (string) ($account->name ?? ''));

        if ($accountSlug === '') {
            $accountSlug = (string) $account->id;
        }

        $yearSlug = Str::slug((string) ($fiscalYear->name ?? ''));

        if ($yearSlug === '') {
            $yearSlug = (string) $fiscalYear->id;
        }

        return 'ledger-'.$accountSlug.'-'.$yearSlug.'.'.ltrim($extension, '.');
    }
}
