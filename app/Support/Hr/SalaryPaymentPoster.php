<?php

namespace App\Support\Hr;

use App\Enums\VoucherType;
use App\Models\JournalVoucher;
use App\Models\PayrollLine;
use App\Models\PayrollPayment;
use App\Models\PayrollRun;
use App\Models\StaffMember;
use App\Models\User;
use App\Support\Branches\BranchContext;
use App\Support\Branches\BranchRuleViolation;
use App\Support\Branches\DocumentBranch;
use App\Support\Money\Money;
use App\Support\SettlementNarration;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Posts and cancels salary payments: `Dr Salary Payable / Cr cash-or-bank`,
 * allocated to specific payslips. Each allocation may also carry an optional
 * `advance_adjusted`: the part of it settled by offsetting the staff member's
 * outstanding advance instead of paying cash, which adds a
 * `Cr Staff Advances Receivable` line (`Dr Salary Payable` settled = `Cr cash`
 * + `Cr advances`). Nothing is ever adjusted unless the caller asks, and the
 * adjustment is capped at both the amount settled and the staff member's
 * outstanding advance. (Salary TDS and the employee's SSF and CIT shares are
 * withheld when the payroll run posts, so a payslip's net, and therefore every
 * payment against it, is already after TDS, SSF and CIT.)
 * Mirrors App\Models\Payment's discipline:
 * allocations are aggregated per payslip and compared exactly (no tolerance)
 * against what is still owed, with the payslips' runs and then the payslip
 * rows locked in ascending id first (see post()).
 *
 * Branches (plan 12, section 5.3 party-line rule, BR09): the payment has a
 * paying branch, where the money moved (PayrollPayment::branch_id, the
 * voucher's header branch); the cash or bank credit posts there, and each
 * Salary Payable debit posts in the branch of the payslips it settles. When
 * they differ the voucher is posted with autoBalance, so BranchBalancer adds
 * the Inter-Branch Clearing lines and every branch balances on its own (I1).
 * One payment may settle payslips of several branches.
 */
class SalaryPaymentPoster
{
    /**
     * Lock order: the payslips' runs (id order), then the payslips (id
     * order), all before the first plain SELECT. Run first because
     * PayrollPoster::cancel() locks the run before checking for live
     * payments, so a payment and a run cancel serialise on the run row and
     * the loser re-reads the winner's result. Locks before any plain read
     * because on MySQL (REPEATABLE READ) the first plain read fixes the
     * snapshot: a settlement-account lookup or outstanding-balance read taken
     * before the locks would miss a payment committed while we waited, and
     * two payments could together overpay one payslip.
     *
     * Which runs to lock is read BEFORE the transaction opens (a plain read
     * there does not fix the transaction's snapshot) and re-verified against
     * the locked payslips.
     *
     * Paying branch: `branch_id` when given, else the payslips' branch when
     * they share one the actor may use (so a single-branch payment lands
     * exactly where it did before), else the actor's posting branch. This
     * default deliberately differs from Receipt, Payment and
     * CapitalPurchaseSettlement, which default to the actor's posting branch
     * (BR07 decision): salary is usually paid where the staff work, so the
     * payslips' branch is the better guess here. Do not harmonise them. Every
     * branch refusal, and a missing clearing account, is raised as
     * InvalidArgumentException so the form shows it.
     *
     * @param  array{date: string, payment_mode: string, bank_account_id?: int|string|null, reference_number?: string|null, narration?: string|null, branch_id?: int|string|null, allocations: array<int, array{payroll_line_id: int|string, amount: mixed, advance_adjusted?: mixed}>}  $data
     *
     * @throws InvalidArgumentException
     */
    public static function post(array $data, User $actor): PayrollPayment
    {
        /** @var array<int, Money> $byLine */
        $byLine = [];
        /** @var array<int, Money> $adjustedByLine */
        $adjustedByLine = [];

        foreach ($data['allocations'] ?? [] as $allocation) {
            $amount = Money::of($allocation['amount']);

            if (! $amount->isPositive()) {
                throw new InvalidArgumentException('Each allocation amount must be greater than zero.');
            }

            $adjusted = Money::ofNullable($allocation['advance_adjusted'] ?? null) ?? Money::zero();

            if ($adjusted->isNegative()) {
                throw new InvalidArgumentException('An advance adjustment cannot be negative.');
            }

            $lineId = (int) $allocation['payroll_line_id'];
            $byLine[$lineId] = ($byLine[$lineId] ?? Money::zero())->plus($amount);
            $adjustedByLine[$lineId] = ($adjustedByLine[$lineId] ?? Money::zero())->plus($adjusted);
        }

        if ($byLine === []) {
            throw new InvalidArgumentException('Choose at least one payslip to pay.');
        }

        ksort($byLine);

        $runIds = PayrollLine::query()
            ->whereIn('id', array_keys($byLine))
            ->distinct()
            ->pluck('payroll_run_id')
            ->map(fn ($id) => (int) $id)
            ->sort()
            ->values()
            ->all();

        return DB::transaction(function () use ($data, $actor, $byLine, $adjustedByLine, $runIds): PayrollPayment {
            $runs = PayrollRun::query()->whereIn('id', $runIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            $lines = PayrollLine::query()
                ->whereIn('id', array_keys($byLine))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($lines->count() !== count($byLine)) {
                throw new InvalidArgumentException('One of the payslips no longer exists.');
            }

            foreach ($lines as $line) {
                if (! $runs->has($line->payroll_run_id)) {
                    throw new InvalidArgumentException('One of the payslips changed while this payment was being saved. Try again.');
                }

                $line->setRelation('run', $runs[$line->payroll_run_id]);
            }

            // An advance adjustment reads the staff member's advance balance,
            // so the staff rows are locked too (id order, after the runs and
            // payslips - the same order PayrollPoster::post() uses), which
            // makes two payments, or a payment and a payroll recovery, serialise
            // instead of both passing the balance check.
            $lockedStaff = collect();

            if (collect($adjustedByLine)->contains(fn (Money $adjusted): bool => $adjusted->isPositive())) {
                $lockedStaff = StaffMember::query()
                    ->whereIn('id', $lines->pluck('staff_id')->unique()->sort()->values())
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');
            }

            // Every lock is held: plain reads from here on see current data.
            $lines->load('staff:id,name');
            $settlementAccountId = HrAccounts::settlementAccountId($data);
            $outstanding = PayrollLine::outstandingAmounts($lines);

            foreach ($byLine as $lineId => $amount) {
                $line = $lines[$lineId];

                // Re-checked on the locked run row: a run cancelled while we
                // waited for its lock is no longer payable.
                if (! $line->run->isPosted()) {
                    throw new InvalidArgumentException("{$line->staff->name}'s payroll run is not posted, so it cannot be paid.");
                }

                $paymentDate = CarbonImmutable::parse($data['date'])->toDateString();

                if ($paymentDate < $line->run->date->toDateString()) {
                    throw new InvalidArgumentException("{$line->staff->name}'s payroll run is dated {$line->run->date->toDateString()}, after this payment ({$paymentDate}).");
                }

                if ($amount->isGreaterThan($outstanding[$lineId])) {
                    throw new InvalidArgumentException("The payment for {$line->staff->name} is more than the {$outstanding[$lineId]->toString()} still owed to them.");
                }

                // The advance offset is credited in the payslip's branch, which
                // must be the branch carrying the staff member's receivable (their
                // home branch, as PayrollPoster::post() requires for a recovery).
                if ($adjustedByLine[$lineId]->isPositive() && (int) $lockedStaff[$line->staff_id]->branch_id !== (int) $line->branch_id) {
                    throw new InvalidArgumentException("{$line->staff->name} has moved to a different branch since this payslip was posted, so their advance cannot be adjusted against it. Pay it without an advance adjustment.");
                }

                if ($adjustedByLine[$lineId]->isGreaterThan($amount)) {
                    throw new InvalidArgumentException("The advance adjustment for {$line->staff->name} cannot be more than the {$amount->toString()} being settled.");
                }
            }

            // The adjustments of one staff member, across all their payslips
            // in this payment, against their outstanding advance.
            /** @var array<int, Money> $adjustedByStaff */
            $adjustedByStaff = [];

            foreach ($adjustedByLine as $lineId => $adjusted) {
                if ($adjusted->isPositive()) {
                    $staffId = (int) $lines[$lineId]->staff_id;
                    $adjustedByStaff[$staffId] = ($adjustedByStaff[$staffId] ?? Money::zero())->plus($adjusted);
                }
            }

            foreach ($adjustedByStaff as $staffId => $adjusted) {
                // The lower of the advance outstanding now and as at the
                // payment date, so a back-dated payment cannot offset an
                // advance that was only given afterwards.
                $advance = Money::min(
                    StaffBalances::advance($staffId),
                    StaffBalances::advance($staffId, CarbonImmutable::parse($data['date'])->toDateString()),
                );

                if ($adjusted->isGreaterThan($advance)) {
                    $name = $lines->first(fn (PayrollLine $line): bool => (int) $line->staff_id === $staffId)->staff->name;

                    throw new InvalidArgumentException("The advance adjustment for {$name} ({$adjusted->toString()}) is more than their outstanding advance ({$advance->toString()}).");
                }
            }

            /** @var array<int, Money> $payableByBranch */
            $payableByBranch = [];
            /** @var array<int, Money> $advanceByBranch */
            $advanceByBranch = [];

            foreach ($byLine as $lineId => $amount) {
                $lineBranchId = (int) $lines[$lineId]->branch_id;
                $payableByBranch[$lineBranchId] = ($payableByBranch[$lineBranchId] ?? Money::zero())->plus($amount);

                if ($adjustedByLine[$lineId]->isPositive()) {
                    $advanceByBranch[$lineBranchId] = ($advanceByBranch[$lineBranchId] ?? Money::zero())->plus($adjustedByLine[$lineId]);
                }
            }

            ksort($payableByBranch);
            ksort($advanceByBranch);

            $payingBranchId = static::payingBranchId($data['branch_id'] ?? null, array_keys($payableByBranch), $actor);
            $total = Money::sum($byLine);
            $totalAdjusted = Money::sum($adjustedByLine);
            $cashPaid = $total->minus($totalAdjusted);

            $voucherLines = [];

            foreach ($payableByBranch as $lineBranchId => $amount) {
                $voucherLines[] = ['account_id' => HrAccounts::id(HrAccounts::SALARY_PAYABLE), 'debit' => $amount->toString(), 'credit' => '0', 'narration' => 'Settlement', 'branch_id' => $lineBranchId];
            }

            // The advance offset lands in the payslip's own (home) branch, the
            // branch that carries the receivable (see StaffAdvancePoster).
            foreach ($advanceByBranch as $lineBranchId => $amount) {
                $voucherLines[] = ['account_id' => HrAccounts::id(HrAccounts::STAFF_ADVANCES_RECEIVABLE), 'debit' => '0', 'credit' => $amount->toString(), 'narration' => 'Advance adjusted', 'branch_id' => $lineBranchId];
            }

            if ($cashPaid->isPositive()) {
                $voucherLines[] = ['account_id' => $settlementAccountId, 'debit' => '0', 'credit' => $cashPaid->toString(), 'narration' => 'Amount paid', 'branch_id' => $payingBranchId];
            }

            try {
                $voucher = JournalVoucher::post(
                    [
                        'voucher_type' => VoucherType::SalaryPayment->value,
                        'date' => $data['date'],
                        'narration' => $data['narration'] ?? 'Salary payment',
                        'branch_id' => $payingBranchId,
                    ],
                    $voucherLines,
                    $actor,
                    lineNarration: fn (JournalVoucher $voucher): string => $totalAdjusted->isPositive()
                        ? SettlementNarration::line(static::numberFor($voucher), $data['payment_mode']).' (advance adjusted)'
                        : SettlementNarration::line(static::numberFor($voucher), $data['payment_mode']),
                    autoBalance: true,
                );
            } catch (BranchRuleViolation $exception) {
                throw new InvalidArgumentException($exception->getMessage(), 0, $exception);
            }

            $payment = PayrollPayment::create([
                'date' => $data['date'],
                'amount' => $total->toString(),
                'advance_adjusted' => $totalAdjusted->toString(),
                'payment_mode' => $data['payment_mode'],
                // Only a bank payment names a bank account (flags G-14).
                'bank_account_id' => $data['payment_mode'] === 'bank' ? ($data['bank_account_id'] ?? null) : null,
                'reference_number' => $data['reference_number'] ?? null,
                'narration' => $data['narration'] ?? null,
                'status' => 'posted',
                'payment_number' => static::numberFor($voucher),
                'fiscal_year_id' => $voucher->fiscal_year_id,
                'journal_voucher_id' => $voucher->id,
                'branch_id' => $payingBranchId,
                'created_by' => $actor->id,
            ]);

            foreach ($byLine as $lineId => $amount) {
                $payment->lines()->create([
                    'payroll_line_id' => $lineId,
                    'amount' => $amount->toString(),
                    'advance_adjusted' => $adjustedByLine[$lineId]->toString(),
                ]);
            }

            return $payment;
        });
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function cancel(PayrollPayment $payment, User $actor, string $reason): PayrollPayment
    {
        // The reason column and the reversal narration are both varchar(255).
        $reason = mb_substr(trim($reason), 0, 255);

        if ($reason === '') {
            throw new InvalidArgumentException('A reason is required to cancel a salary payment.');
        }

        return DB::transaction(function () use ($payment, $actor, $reason): PayrollPayment {
            /** @var PayrollPayment $locked */
            $locked = PayrollPayment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === 'cancelled') {
                throw new InvalidArgumentException('This salary payment has already been cancelled.');
            }

            // A payment that offset an advance credited Staff Advances Receivable
            // in each payslip's branch. If the staff member has since moved
            // branch (allowed once their advance reached zero), reversing it would
            // reopen that receivable in a branch that no longer carries it.
            if (Money::of($locked->advance_adjusted)->isPositive()) {
                $adjustedLines = $locked->lines()->where('advance_adjusted', '>', 0)->with('payrollLine:id,staff_id,branch_id')->get();

                $staff = StaffMember::query()
                    ->whereIn('id', $adjustedLines->pluck('payrollLine.staff_id')->unique()->sort()->values())
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                foreach ($adjustedLines as $row) {
                    if ((int) $staff[$row->payrollLine->staff_id]->branch_id !== (int) $row->payrollLine->branch_id) {
                        throw new InvalidArgumentException("{$staff[$row->payrollLine->staff_id]->name} has moved to a different branch since this payment adjusted their advance, so it cannot be cancelled. Move them back to their previous branch first.");
                    }
                }
            }

            $reversal = JournalVoucher::reverse(
                BranchContext::unrestricted(fn () => $locked->journalVoucher()->firstOrFail()),
                $actor,
                mb_substr("Cancellation of salary payment {$locked->payment_number}: {$reason}", 0, 255),
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
     * The branch the money leaves from: the requested one, else the payslips'
     * single branch when the actor may use it, else the actor's posting
     * branch. Always checked active and usable (I6).
     *
     * @param  list<int>  $payslipBranchIds
     *
     * @throws InvalidArgumentException
     */
    private static function payingBranchId(mixed $requested, array $payslipBranchIds, User $actor): int
    {
        if (($requested === null || $requested === '') && count($payslipBranchIds) === 1) {
            $only = $payslipBranchIds[0];

            if (BranchContext::accessIsLifted() || BranchContext::canAccessBranch($only, $actor)) {
                $requested = $only;
            }
        }

        return DocumentBranch::forDocument($actor, $requested)->branchId;
    }

    private static function numberFor(JournalVoucher $voucher): string
    {
        return $voucher->printedNumber('SAL');
    }
}
