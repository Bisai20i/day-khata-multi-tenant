<?php

namespace App\Support\Branches;

use App\Enums\FiscalYearStatus;
use App\Enums\VoucherType;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\FiscalYear;
use App\Models\JournalVoucher;
use App\Models\User;
use App\Models\VoucherSequence;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Changes a fiscal year's numbering scope (plan 12, BR20, section 5.8):
 * `shared` (one company-wide series per voucher type) or `per_branch` (one
 * series per voucher type and header branch, printed
 * `{prefix}-{series_code}-{n}`).
 *
 * Every rule is checked again inside one transaction with the fiscal year
 * row locked. JournalVoucher::post() takes the same lock before it numbers
 * anything, and setStartingNumber() takes it too, so a posting or a starting
 * number either commits first (and the voucher check below sees it) or waits
 * until the scope is settled.
 *
 * The rules:
 * - Owner only (the person legally answerable for the books), and only while
 *   the tenant has the `branches` module.
 * - The year has no journal voucher at all, of any type, in any branch (read
 *   outside the branch access scope). Once one document is numbered, a
 *   switch would leave two numbering schemes inside one year.
 * - The year is the open year, or a year that has never been opened yet (a
 *   created next year, status Closed with no closed_at and never reopened).
 *   The second case is needed in practice: closing a year posts the opening
 *   balances into the next one the moment it opens, so a scope for any year
 *   but a company's first can only be chosen before it is opened.
 * - Going per_branch: every active branch needs a series code, and no
 *   starting number may be set in the shared series of that year (a
 *   per-branch year would silently ignore it).
 */
final class NumberingScope
{
    /**
     * @return bool Whether anything changed (false when $scope is already the year's scope).
     *
     * @throws AuthorizationException When $actor is not the owner.
     * @throws InvalidArgumentException When a rule above refuses the change.
     */
    public static function change(FiscalYear $fiscalYear, string $scope, User $actor): bool
    {
        if (! in_array($scope, [FiscalYear::NUMBERING_SHARED, FiscalYear::NUMBERING_PER_BRANCH], true)) {
            throw new InvalidArgumentException('Unknown numbering scope.');
        }

        if (! $actor->isOwner()) {
            throw new AuthorizationException('Only the owner can change how a fiscal year is numbered.');
        }

        if (! ActiveBranchSelection::moduleEnabled()) {
            throw new InvalidArgumentException('Numbering per branch needs the branches module.');
        }

        $label = strtolower(ActiveBranchSelection::label());

        return BranchContext::unrestricted(fn (): bool => DB::transaction(function () use ($fiscalYear, $scope, $actor, $label): bool {
            /** @var FiscalYear $year */
            $year = FiscalYear::query()->whereKey($fiscalYear->getKey())->lockForUpdate()->firstOrFail();
            $from = $year->numberingScope();

            if ($from === $scope) {
                return false;
            }

            static::assertYearMayChange($year);

            // A plain read: every poster takes the fiscal-year lock held above
            // before it numbers anything, and locking voucher rows here too
            // would invert the voucher -> fiscal year order reverse() uses.
            if (JournalVoucher::query()->where('fiscal_year_id', $year->id)->exists()) {
                throw new InvalidArgumentException(
                    "\"{$year->name}\" already has numbered vouchers, so its numbering can no longer be changed."
                );
            }

            if ($scope === FiscalYear::NUMBERING_PER_BRANCH) {
                static::assertReadyForPerBranch($year, $label);
            }

            $year->forceFill(['numbering_scope' => $scope]);
            // Quietly: the generic observer would log a bare "updated" row;
            // the explicit entry below says what changed and who did it.
            $year->saveQuietly();

            ActivityLog::create([
                'user_id' => $actor->id,
                'action' => 'numbering_scope_changed',
                'subject_type' => $year->getMorphClass(),
                'subject_id' => $year->getKey(),
                'description' => Str::limit($scope === FiscalYear::NUMBERING_PER_BRANCH
                    ? "Fiscal year {$year->name} now numbers documents separately per {$label}"
                    : "Fiscal year {$year->name} now numbers documents in one shared series", 250, ''),
                'changes' => ['numbering_scope' => ['from' => $from, 'to' => $scope]],
            ]);

            $fiscalYear->forceFill(['numbering_scope' => $scope])->syncOriginal();

            return true;
        }));
    }

    /**
     * Whether $fiscalYear's scope may still be changed, judged on the row
     * alone (the voucher check needs the lock, see change()). Used by the
     * fiscal-years page to show or hide the control; change() decides.
     */
    public static function yearMayChange(FiscalYear $fiscalYear): bool
    {
        return $fiscalYear->status === FiscalYearStatus::Open
            || ($fiscalYear->closed_at === null && $fiscalYear->reopened_at === null);
    }

    private static function assertYearMayChange(FiscalYear $year): void
    {
        if (! self::yearMayChange($year)) {
            throw new InvalidArgumentException(
                "\"{$year->name}\" has been closed, so its numbering can no longer be changed."
            );
        }
    }

    private static function assertReadyForPerBranch(FiscalYear $year, string $label): void
    {
        $missing = Branch::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('series_code')->orWhere('series_code', ''))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->pluck('name')
            ->all();

        if ($missing !== []) {
            throw new InvalidArgumentException(
                "Give every active {$label} a series code first. Missing: ".implode(', ', $missing).'.'
            );
        }

        $startingNumberSet = VoucherSequence::query()
            ->where('fiscal_year_id', $year->id)
            ->where('series_branch_id', VoucherSequence::SHARED_SERIES)
            ->where('voucher_type', '<>', VoucherType::ProductionEntry->value)
            ->where('last_number', '>', 0)
            ->exists();

        if ($startingNumberSet) {
            throw new InvalidArgumentException(
                "A starting number is set for \"{$year->name}\". Separate series per {$label} cannot continue it, so the year stays on one shared series."
            );
        }
    }
}
