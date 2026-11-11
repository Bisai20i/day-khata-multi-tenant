<?php

namespace App\Support\Hr;

use App\Enums\VoucherType;
use App\Models\JournalVoucher;
use App\Models\PayrollLine;
use App\Models\PayrollPaymentLine;
use App\Models\PayrollRun;
use App\Models\StaffMember;
use App\Models\User;
use App\Support\Branches\BranchContext;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Posts and cancels payroll runs - the only code that puts payroll expense in
 * the ledger.
 *
 * A run posts ONE voucher (VoucherType::Payroll):
 *
 *   Dr Salaries & Wages / Staff Bonus / Staff Allowances   (one line per expense account)
 *       Cr Salary Payable          (total net: what is owed to staff)
 *       Cr Staff Advances Receivable (total advance recovered; only when > 0)
 *
 * so the unpaid part shows as a Payable on the Balance Sheet and a recovered
 * advance reduces the Receivable.
 *
 * Branches (plan 12, section 5.4, BR09): the voucher's header branch is the
 * run's branch, and every payslip's expense, Salary Payable and advance
 * recovery lines post in that payslip's branch (its staff member's home
 * branch). The lines above are therefore built once per branch, run branch
 * first and then by id, and each branch's group balances on its own because
 * every payslip's gross equals its net plus its recovery. No Inter-Branch
 * Clearing is ever needed, so the voucher is posted without autoBalance: a
 * branch that did not balance would be a bug and must throw (I1), not be
 * papered over with clearing lines. With one branch the lines, their order
 * and amounts are exactly what a single-branch company posted before.
 */
class PayrollPoster
{
    /**
     * Lock order (shared with SalaryPaymentPoster and PayrollDraft): the run,
     * then its lines, then the staff rows in id order - all LOCKING reads,
     * taken before the first plain SELECT. On MySQL (REPEATABLE READ) the
     * first plain read fixes the transaction's snapshot, so a plain read
     * before the staff locks would check the advance balance against data
     * older than the lock, and two runs recovering the same advance could
     * both pass.
     *
     * @throws InvalidArgumentException when the run is not a valid draft
     */
    public static function post(PayrollRun $run, User $actor): PayrollRun
    {
        return DB::transaction(function () use ($run, $actor): PayrollRun {
            /** @var PayrollRun $locked */
            $locked = PayrollRun::query()->whereKey($run->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isDraft()) {
                throw new InvalidArgumentException('Only a draft payroll run can be posted.');
            }

            // A locking read, so it does not open the snapshot either.
            $staffIds = $locked->lines()->orderBy('id')->lockForUpdate()->pluck('staff_id')
                ->map(fn ($id) => (int) $id)->unique()->sort()->values();

            if ($staffIds->isEmpty()) {
                throw new InvalidArgumentException('A payroll run needs at least one staff member.');
            }

            // Staff rows in id order, so two runs recovering the same advance
            // serialise instead of both passing the balance check.
            $staff = StaffMember::query()->whereIn('id', $staffIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            // Every lock is held: plain reads from here on see current data.
            $lines = $locked->lines()->with('items.component')->orderBy('staff_id')->get();

            static::assertNoOverlappingRun($locked, $staffIds->all(), $staff);

            /** @var array<int, array{debit: array<int, Money>, payable: Money, recovery: Money}> $byBranch */
            $byBranch = [];
            $totalGross = Money::zero();
            $totalRecovery = Money::zero();

            $runBranchId = (int) $locked->branch_id;

            foreach ($lines as $line) {
                $member = $staff[$line->staff_id];

                if (! $member->is_active) {
                    throw new InvalidArgumentException("{$member->name} is inactive; remove them from the run before posting.");
                }

                // The payslip was stamped with the staff member's branch when
                // the draft was saved; a move since then must not post the
                // expense in a branch the staff member has left.
                $lineBranchId = (int) $line->branch_id;

                if ((int) $member->branch_id !== $lineBranchId) {
                    throw new InvalidArgumentException("{$member->name} has moved to a different branch since this run was saved; open the run and save it again before posting.");
                }

                $byBranch[$lineBranchId] ??= ['debit' => [], 'payable' => Money::zero(), 'recovery' => Money::zero()];
                $gross = Money::zero();

                foreach ($line->items as $item) {
                    if (! $item->component->is_active) {
                        throw new InvalidArgumentException("The pay component \"{$item->component->name}\" is inactive; remove it from {$member->name}'s pay before posting.");
                    }

                    $gross = $gross->plus($item->amount);
                    $accountId = $item->component->expense_account_id;
                    $byBranch[$lineBranchId]['debit'][$accountId] = ($byBranch[$lineBranchId]['debit'][$accountId] ?? Money::zero())->plus($item->amount);
                }

                if (! $gross->isEqualTo($line->gross)) {
                    throw new InvalidArgumentException("{$member->name}'s payslip no longer adds up; open the run and save it again.");
                }

                // The draft editor enforces these too; re-checked here so a
                // hand-edited or half-written line can never reach the ledger.
                $recovery = Money::of($line->advance_recovery);

                if ($recovery->isNegative() || $recovery->isGreaterThan($gross)) {
                    throw new InvalidArgumentException("The advance recovery for {$member->name} must be between zero and their gross pay; open the run and save it again.");
                }

                $net = Money::of($line->net);

                if ($net->isNegative() || ! $net->isEqualTo($gross->minus($recovery))) {
                    throw new InvalidArgumentException("{$member->name}'s net pay is not gross minus advance recovery; open the run and save it again.");
                }

                // Only a real recovery is checked against the advance balance:
                // a zero recovery needs no balance (and saves a query per staff).
                if ($recovery->isPositive() && $recovery->isGreaterThan(StaffBalances::advance($line->staff_id))) {
                    throw new InvalidArgumentException("The advance recovery for {$member->name} is more than their outstanding advance.");
                }

                $byBranch[$lineBranchId]['payable'] = $byBranch[$lineBranchId]['payable']->plus($net);
                $byBranch[$lineBranchId]['recovery'] = $byBranch[$lineBranchId]['recovery']->plus($recovery);

                $totalGross = $totalGross->plus($gross);
                $totalRecovery = $totalRecovery->plus($recovery);
            }

            if (! $totalGross->isPositive()) {
                throw new InvalidArgumentException('A payroll run must pay something.');
            }

            $totalNet = $totalGross->minus($totalRecovery);

            $voucherLines = static::voucherLines($byBranch, $runBranchId);

            $narration = mb_substr('Payroll '.$locked->period_label.($locked->narration ? ' - '.$locked->narration : ''), 0, 255);

            $voucher = JournalVoucher::post(
                [
                    'voucher_type' => VoucherType::Payroll->value,
                    'date' => $locked->date->toDateString(),
                    'narration' => $narration,
                    'branch_id' => $runBranchId,
                ],
                $voucherLines,
                $actor,
                lineNarration: fn (JournalVoucher $voucher): string => mb_substr("{$voucher->printedNumber('PAY')} - {$locked->period_label}", 0, 255),
            );

            $locked->update([
                'status' => PayrollRun::STATUS_POSTED,
                'journal_voucher_id' => $voucher->id,
                'fiscal_year_id' => $voucher->fiscal_year_id,
                'run_number' => $voucher->voucher_number,
                // Part of the run's unique key with run_number (BR20): in a
                // per_branch year every branch has its own PAY series.
                'series_branch_id' => $voucher->series_branch_id,
                'total_gross' => $totalGross->toString(),
                'total_recovery' => $totalRecovery->toString(),
                'total_net' => $totalNet->toString(),
                'posted_by' => $actor->id,
                'posted_at' => now(),
            ]);

            return $locked;
        });
    }

    /**
     * The voucher lines, one group per branch (the run's branch first, then
     * ascending id): that branch's expense debits by account id, then its
     * Salary Payable credit, then its advance recovery credit, each only when
     * positive. Every line names its branch.
     *
     * @param  array<int, array{debit: array<int, Money>, payable: Money, recovery: Money}>  $byBranch
     * @return list<array{account_id: int, debit: string, credit: string, narration: string, branch_id: int}>
     */
    private static function voucherLines(array $byBranch, int $runBranchId): array
    {
        uksort($byBranch, fn (int $a, int $b): int => [$a !== $runBranchId, $a] <=> [$b !== $runBranchId, $b]);

        $voucherLines = [];

        foreach ($byBranch as $branchId => $group) {
            $debits = $group['debit'];
            ksort($debits);

            foreach ($debits as $accountId => $amount) {
                $voucherLines[] = ['account_id' => $accountId, 'debit' => $amount->toString(), 'credit' => '0', 'narration' => 'Payroll expense', 'branch_id' => $branchId];
            }

            if ($group['payable']->isPositive()) {
                $voucherLines[] = ['account_id' => HrAccounts::id(HrAccounts::SALARY_PAYABLE), 'debit' => '0', 'credit' => $group['payable']->toString(), 'narration' => 'Salary payable', 'branch_id' => $branchId];
            }

            if ($group['recovery']->isPositive()) {
                $voucherLines[] = ['account_id' => HrAccounts::id(HrAccounts::STAFF_ADVANCES_RECEIVABLE), 'debit' => '0', 'credit' => $group['recovery']->toString(), 'narration' => 'Advance recovered', 'branch_id' => $branchId];
            }
        }

        return $voucherLines;
    }

    /**
     * Refuses the post when any of the run's staff also sits in another live
     * (draft or posted) run whose period overlaps this one. PayrollDraft
     * checks this when the draft is written, but two drafts generated at the
     * same moment can both pass that check; this one runs under the staff
     * locks, so of two overlapping posts the second always sees the first.
     *
     * @param  list<int>  $staffIds
     * @param  Collection<int, StaffMember>  $staff
     *
     * @throws InvalidArgumentException
     */
    private static function assertNoOverlappingRun(PayrollRun $run, array $staffIds, Collection $staff): void
    {
        $clash = PayrollLine::query()
            ->whereIn('staff_id', $staffIds)
            ->whereHas('run', fn ($other) => $other
                ->whereIn('status', [PayrollRun::STATUS_DRAFT, PayrollRun::STATUS_POSTED])
                ->whereKeyNot($run->getKey())
                ->whereDate('period_start', '<=', $run->period_end->toDateString())
                ->whereDate('period_end', '>=', $run->period_start->toDateString()))
            ->with('run:id,period_label,period_start,period_end,status')
            ->orderBy('staff_id')
            ->first();

        if ($clash === null) {
            return;
        }

        $other = $clash->run;

        throw new InvalidArgumentException(
            "{$staff[$clash->staff_id]->name} is also in the {$other->status} payroll run \"{$other->period_label}\" "
            ."({$other->period_start->toDateString()} to {$other->period_end->toDateString()}), which overlaps this period. "
            .'Remove them from one of the runs first.'
        );
    }

    /**
     * Reverses a posted run. Refused while any live salary payment is
     * allocated to it (cancel those first), and - like every module - only
     * while its fiscal year is still open (JournalVoucher::reverse()).
     *
     * @throws InvalidArgumentException
     */
    public static function cancel(PayrollRun $run, User $actor, string $reason): PayrollRun
    {
        // The reason column and the reversal narration are both varchar(255).
        $reason = mb_substr(trim($reason), 0, 255);

        if ($reason === '') {
            throw new InvalidArgumentException('A reason is required to cancel a payroll run.');
        }

        return DB::transaction(function () use ($run, $actor, $reason): PayrollRun {
            /** @var PayrollRun $locked */
            $locked = PayrollRun::query()->whereKey($run->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === PayrollRun::STATUS_CANCELLED) {
                throw new InvalidArgumentException('This payroll run has already been cancelled.');
            }

            if (! $locked->isPosted()) {
                throw new InvalidArgumentException('Only a posted payroll run can be cancelled; discard a draft instead.');
            }

            static::assertRecoveriesStillInHomeBranch($locked);

            $hasLivePayments = PayrollPaymentLine::query()
                ->whereIn('payroll_line_id', $locked->lines()->select('id'))
                ->whereHas('payment', fn ($payment) => $payment->where('status', 'posted'))
                ->exists();

            if ($hasLivePayments) {
                throw new InvalidArgumentException('This run has salary payments against it. Cancel those payments first.');
            }

            $reversal = JournalVoucher::reverse(
                BranchContext::unrestricted(fn () => $locked->journalVoucher()->firstOrFail()),
                $actor,
                mb_substr("Cancellation of payroll {$locked->displayNumber()}: {$reason}", 0, 255),
            );

            $locked->update([
                'status' => PayrollRun::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
                'cancel_reason' => $reason,
                'reversal_journal_voucher_id' => $reversal->id,
            ]);

            return $locked;
        });
    }

    /**
     * Refuses the cancel when a payslip that recovered an advance belongs to
     * a branch its staff member has since left. The reversal would re-open
     * that advance in the old branch (the payslip's), while any later
     * recovery posts in the new home branch, so the old branch's receivable
     * would never clear and the new one's would go negative. StaffController
     * allows a move once the advance balance is zero, which is exactly the
     * state a fully recovering run leaves behind, hence this check here.
     *
     * Same lock order as post(): the run (held by the caller), then its lines,
     * then the staff rows in id order, all LOCKING reads taken before the
     * first plain SELECT, so a concurrent branch move or advance posting
     * (both lock the staff row first) serialises with this cancel.
     *
     * @throws InvalidArgumentException
     */
    private static function assertRecoveriesStillInHomeBranch(PayrollRun $run): void
    {
        $lines = $run->lines()->orderBy('id')->lockForUpdate()->get(['id', 'staff_id', 'branch_id', 'advance_recovery']);

        $staffIds = $lines->pluck('staff_id')->map(fn ($id): int => (int) $id)->unique()->sort()->values();

        if ($staffIds->isEmpty()) {
            return;
        }

        $staff = StaffMember::query()->whereIn('id', $staffIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        foreach ($lines->sortBy('staff_id') as $line) {
            $member = $staff[$line->staff_id];

            if (Money::of($line->advance_recovery)->isPositive() && (int) $member->branch_id !== (int) $line->branch_id) {
                throw new InvalidArgumentException(
                    "{$member->name}'s payslip in this run recovered an advance in a branch they have since moved away from. "
                    .'Move them back to that branch first, then cancel the run, so the advance re-opens in the branch '
                    .'where it will be recovered.'
                );
            }
        }
    }
}
