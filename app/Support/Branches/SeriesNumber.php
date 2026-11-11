<?php

namespace App\Support\Branches;

use App\Models\Branch;
use App\Models\FiscalYear;
use App\Models\VoucherSequence;
use InvalidArgumentException;

/**
 * Per-fiscal-year numbering scope (plan 12, BR20, section 5.8), in one place.
 *
 * - Which series a new voucher numbers from: series_branch_id 0 (the shared
 *   series) in a shared-scope year, the voucher's header branch in a
 *   per_branch year (seriesBranchFor()). The header branch is the key for
 *   every voucher alike, system ones included: a reversal, a roll-forward
 *   adjustment and a year-end closing or opening entry take their own
 *   header branch's series (Main for the year-end vouchers).
 * - How a number prints: `{n}` / `{prefix}-{n}` in the shared series,
 *   `{series_code}-{n}` / `{prefix}-{series_code}-{n}` in a branch series
 *   (format()). JournalVoucher::seriesNumber() and printedNumber() call
 *   this; every document that prints a number built from its voucher's
 *   number goes through them, so a shared year prints byte-identical
 *   numbers and a per_branch year can never print two documents of the same
 *   series with the same number.
 *
 * The exact printed format is still subject to the CA/IRD question in plan
 * section 13; changing it means changing format() only.
 */
final class SeriesNumber
{
    /**
     * The branch whose series a voucher with header branch $headerBranchId
     * numbers from in $fiscalYear, or null for the shared series. A shared
     * year returns null without touching the database (the scope is read
     * from the fiscal year row the caller already holds).
     *
     * @throws InvalidArgumentException When the year is per_branch and the branch is unknown or has no series code.
     */
    public static function seriesBranchFor(FiscalYear $fiscalYear, int $headerBranchId): ?Branch
    {
        if (! $fiscalYear->usesPerBranchNumbering()) {
            return null;
        }

        // Share-locked so a concurrent edit of the series code (which locks the
        // branch row first) waits until this posting commits; the code a
        // document stores is then the code the series keeps.
        $branch = Branch::query()->whereKey($headerBranchId)->lockForShare()->first(['id', 'name', 'series_code']);

        if ($branch === null) {
            throw new InvalidArgumentException('The journal voucher names an invalid branch.');
        }

        if (trim((string) $branch->series_code) === '') {
            $label = strtolower(ActiveBranchSelection::label());

            throw new InvalidArgumentException(
                "Set a series code for {$label} \"{$branch->name}\" before posting in \"{$fiscalYear->name}\", which numbers documents separately per {$label}."
            );
        }

        return $branch;
    }

    /**
     * The series id stored on the voucher and its sequence row.
     */
    public static function seriesBranchId(?Branch $seriesBranch): int
    {
        return $seriesBranch === null ? VoucherSequence::SHARED_SERIES : (int) $seriesBranch->getKey();
    }

    /**
     * `{n}` or `{series_code}-{n}`, then `{prefix}-` in front when a prefix
     * is given. A null or empty $seriesCode is the shared series, whose
     * output is exactly what every document printed before BR20.
     */
    public static function format(int|string $number, ?string $seriesCode, ?string $prefix = null): string
    {
        $seriesCode = trim((string) $seriesCode);
        $body = $seriesCode === '' ? (string) $number : "{$seriesCode}-{$number}";

        return $prefix === null ? $body : "{$prefix}-{$body}";
    }
}
