<?php

namespace App\Support\Hr;

use App\Enums\VoucherType;
use App\Models\JournalVoucher;
use App\Models\StaffAdvance;
use App\Models\StaffMember;
use App\Models\User;
use App\Support\Branches\BranchContext;
use App\Support\Branches\BranchRuleViolation;
use App\Support\Branches\DocumentBranch;
use App\Support\Money\Money;
use App\Support\SettlementNarration;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Posts and cancels staff advances: `Dr Staff Advances Receivable / Cr
 * cash-or-bank`. The advance is recovered only through payroll lines
 * (PayrollDraft / PayrollPoster), never directly.
 *
 * Branches (plan 12, section 5.3, BR09): the receivable debit posts in the
 * staff member's home branch, where payroll later recovers it; the cash or
 * bank credit posts in the paying branch (StaffAdvance::branch_id, the
 * voucher's header branch). When they differ the voucher is posted with
 * autoBalance and BranchBalancer adds the Inter-Branch Clearing lines.
 */
class StaffAdvancePoster
{
    /**
     * Paying branch: `branch_id` when given, else the staff member's home
     * branch when the actor may use it (exactly where advances landed
     * before), else the actor's posting branch. Every branch refusal, and a
     * missing clearing account, is raised as InvalidArgumentException.
     *
     * @param  array{staff_id: int|string, date: string, amount: mixed, payment_mode: string, bank_account_id?: int|string|null, reference_number?: string|null, narration?: string|null, branch_id?: int|string|null}  $data
     *
     * @throws InvalidArgumentException
     */
    public static function post(array $data, User $actor): StaffAdvance
    {
        return DB::transaction(function () use ($data, $actor): StaffAdvance {
            /** @var StaffMember $staff */
            $staff = StaffMember::query()->whereKey($data['staff_id'])->lockForUpdate()->firstOrFail();

            if (! $staff->is_active) {
                throw new InvalidArgumentException("{$staff->name} is inactive and cannot be given an advance.");
            }

            $amount = Money::of($data['amount']);

            if (! $amount->isPositive()) {
                throw new InvalidArgumentException('The advance amount must be greater than zero.');
            }

            $settlementAccountId = HrAccounts::settlementAccountId($data);
            $staffBranchId = (int) $staff->branch_id;
            $payingBranchId = static::payingBranchId($data['branch_id'] ?? null, $staffBranchId, $actor);

            try {
                $voucher = JournalVoucher::post(
                    [
                        'voucher_type' => VoucherType::StaffAdvance->value,
                        'date' => $data['date'],
                        'narration' => $data['narration'] ?? "Advance to {$staff->name}",
                        'branch_id' => $payingBranchId,
                    ],
                    [
                        ['account_id' => HrAccounts::id(HrAccounts::STAFF_ADVANCES_RECEIVABLE), 'debit' => $amount->toString(), 'credit' => '0', 'narration' => 'Advance given', 'branch_id' => $staffBranchId],
                        ['account_id' => $settlementAccountId, 'debit' => '0', 'credit' => $amount->toString(), 'narration' => 'Amount paid', 'branch_id' => $payingBranchId],
                    ],
                    $actor,
                    lineNarration: fn (JournalVoucher $voucher): string => SettlementNarration::line(static::numberFor($voucher), $data['payment_mode']),
                    autoBalance: true,
                );
            } catch (BranchRuleViolation $exception) {
                throw new InvalidArgumentException($exception->getMessage(), 0, $exception);
            }

            return StaffAdvance::create([
                'staff_id' => $staff->id,
                'date' => $data['date'],
                'amount' => $amount->toString(),
                'payment_mode' => $data['payment_mode'],
                'bank_account_id' => $data['payment_mode'] === 'bank' ? ($data['bank_account_id'] ?? null) : null,
                'reference_number' => $data['reference_number'] ?? null,
                'narration' => $data['narration'] ?? null,
                'status' => 'posted',
                'advance_number' => static::numberFor($voucher),
                'fiscal_year_id' => $voucher->fiscal_year_id,
                'journal_voucher_id' => $voucher->id,
                'branch_id' => $payingBranchId,
                'created_by' => $actor->id,
            ]);
        });
    }

    /**
     * Refused when part of the advance has already been recovered through a
     * payroll run (the staff member's outstanding balance would go negative).
     *
     * @throws InvalidArgumentException
     */
    public static function cancel(StaffAdvance $advance, User $actor, string $reason): StaffAdvance
    {
        // The reason column and the reversal narration are both varchar(255).
        $reason = mb_substr(trim($reason), 0, 255);

        if ($reason === '') {
            throw new InvalidArgumentException('A reason is required to cancel an advance.');
        }

        return DB::transaction(function () use ($advance, $actor, $reason): StaffAdvance {
            // Same lock order as post() and PayrollPoster::post(): the staff
            // row first (staff_id never changes), then the advance.
            StaffMember::query()->whereKey($advance->staff_id)->lockForUpdate()->firstOrFail();

            /** @var StaffAdvance $locked */
            $locked = StaffAdvance::query()->whereKey($advance->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === 'cancelled') {
                throw new InvalidArgumentException('This advance has already been cancelled.');
            }

            if (StaffBalances::advance($locked->staff_id)->isLessThan($locked->amount)) {
                throw new InvalidArgumentException('Part of this advance has already been recovered through payroll, so it cannot be cancelled. Cancel the payroll run that recovered it first.');
            }

            $reversal = JournalVoucher::reverse(
                BranchContext::unrestricted(fn () => $locked->journalVoucher()->firstOrFail()),
                $actor,
                mb_substr("Cancellation of staff advance {$locked->advance_number}: {$reason}", 0, 255),
            );

            $locked->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
                'cancel_reason' => $reason,
                'reversal_journal_voucher_id' => $reversal->id,
            ]);

            return $locked;
        });
    }

    /**
     * The branch the money leaves from: the requested one, else the staff
     * member's home branch when the actor may use it, else the actor's
     * posting branch. Always checked active and usable (I6).
     *
     * @throws InvalidArgumentException
     */
    private static function payingBranchId(mixed $requested, int $staffBranchId, User $actor): int
    {
        if (($requested === null || $requested === '')
            && (BranchContext::accessIsLifted() || BranchContext::canAccessBranch($staffBranchId, $actor))) {
            $requested = $staffBranchId;
        }

        return DocumentBranch::forDocument($actor, $requested)->branchId;
    }

    private static function numberFor(JournalVoucher $voucher): string
    {
        return $voucher->printedNumber('ADV');
    }
}
