<?php

namespace App\Support;

use App\Enums\FiscalYearStatus;
use App\Models\ActivityLog;
use App\Models\FiscalYear;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Gate for posting a Purchase, Journal Voucher, or Stock Adjustment against
 * a specific fiscal year that isn't the currently open one. Sale is
 * deliberately never routed through this guard - it stays permanently
 * locked to the open fiscal year, no exception, per the locked design
 * decision in plans/invoicing-settings-sale-purchase-ux.md ("Locked
 * decisions" #3).
 */
class ClosedFiscalYearGuard
{
    /**
     * A no-op when $fiscalYear is the open year. When it's closed,
     * postable only while it has been reopened for correction (App\Models\
     * FiscalYear::isOpenForCorrection()) and not yet relocked - and only
     * with a real, non-blank reason. A plain closed year that has never
     * been reopened is never postable here, reason or not - that's the
     * whole point of the reopen step.
     */
    public static function ensurePostable(FiscalYear $fiscalYear, ?string $reason): void
    {
        if ($fiscalYear->status === FiscalYearStatus::Open) {
            return;
        }

        if (! $fiscalYear->isOpenForCorrection()) {
            throw new InvalidArgumentException(
                "\"{$fiscalYear->name}\" is closed and has not been reopened for correction."
            );
        }

        if (trim((string) $reason) === '') {
            throw new InvalidArgumentException('A reason is required to post a correction into a reopened fiscal year.');
        }
    }

    /**
     * Resolves the fiscal year a dated document actually belongs in - the one
     * whose range contains $date, not "whichever year happens to be open" -
     * and returns it only when that year is postable.
     *
     * This is the entry point for every dated posting that does NOT go through
     * JournalVoucher::post() (stock adjustments, transfers, conversions, the
     * opening-stock import): those wrote into the open year no matter what
     * date the user typed, so a bill dated Asar 30 landed in the new year's
     * ledger while the date-filtered VAT book and Day Book still placed it in
     * the already-filed period (audit P0-11). JournalVoucher::post() resolves
     * its own year (it also honours an explicit fiscal_year_id for the
     * reopened-correction flow) and then enforces the same date-inside-year
     * rule in write(), so it does not call this.
     *
     * Posting into a year that is closed but reopened for correction needs the
     * same admin + reason pair post() requires, hence the optional $actor: the
     * check is skipped only when the resolved year is the open one.
     *
     * @throws InvalidArgumentException When no fiscal year covers $date, or the one that does is closed.
     * @throws AuthorizationException When a non-admin targets a reopened closed year.
     */
    public static function assertDateInOpenYear(string $date, ?User $actor = null, ?string $reason = null): FiscalYear
    {
        $fiscalYear = FiscalYear::query()
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->first();

        if (! $fiscalYear) {
            throw new InvalidArgumentException("The date {$date} does not fall inside any fiscal year.");
        }

        static::ensurePostable($fiscalYear, $reason);

        if ($fiscalYear->status !== FiscalYearStatus::Open && $actor?->role?->slug !== 'admin') {
            throw new AuthorizationException('Only an admin may post into a reopened fiscal year.');
        }

        return $fiscalYear;
    }

    /**
     * Writes a correction posting to the tenant's existing activity-log
     * table (App\Models\ActivityLog, normally written exclusively by
     * ActivityLogObserver - see that class's docblock) rather than a
     * second logging mechanism. Called from each of the three posting call
     * sites (Purchase::post(), JournalVoucher::post(), StockAdjustment::
     * post()) only after posting into a non-current fiscal year has
     * actually succeeded - deliberately not called from ensurePostable()
     * itself, which is a pure check and shouldn't have the side effect of
     * writing a log row.
     */
    public static function logCorrection(FiscalYear $fiscalYear, string $reason, string $voucherDescription): void
    {
        $description = "Correction posted into reopened fiscal year \"{$fiscalYear->name}\": {$voucherDescription} — reason: {$reason}";

        ActivityLog::create([
            // Explicit 'web' guard, matching ActivityLogObserver::write()'s
            // convention (see AuthenticatedSessionController's docblock on
            // guard ambiguity).
            'user_id' => Auth::guard('web')->id(),
            'action' => 'correction',
            'subject_type' => FiscalYear::class,
            'subject_id' => $fiscalYear->id,
            // $voucherDescription/$reason are each already validated to a
            // sensible max length by their respective controllers, but the
            // concatenation could still exceed the `description` column's
            // plain string (255-char) width - truncate rather than risk a
            // "data too long" error on a strict SQL mode.
            'description' => Str::limit($description, 250),
            'changes' => null,
        ]);
    }
}
