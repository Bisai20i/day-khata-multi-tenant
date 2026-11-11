<?php

namespace App\Support\Hr;

use App\Enums\VoucherType;
use App\Models\JournalVoucher;
use App\Models\PayrollLine;
use App\Models\PayrollPayment;
use App\Models\PayrollRun;
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
 * allocated to specific payslips. Mirrors App\Models\Payment's discipline:
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
     * @param  array{date: string, payment_mode: string, bank_account_id?: int|string|null, reference_number?: string|null, narration?: string|null, branch_id?: int|string|null, allocations: array<int, array{payroll_line_id: int|string, amount: mixed}>}  $data
     *
     * @throws InvalidArgumentException
     */
    public static function post(array $data, User $actor): PayrollPayment
    {
        /** @var array<int, Money> $byLine */
        $byLine = [];

        foreach ($data['allocations'] ?? [] as $allocation) {
            $amount = Money::of($allocation['amount']);

            if (! $amount->isPositive()) {
                throw new InvalidArgumentException('Each allocation amount must be greater than zero.');
            }

            $lineId = (int) $allocation['payroll_line_id'];
            $byLine[$lineId] = ($byLine[$lineId] ?? Money::zero())->plus($amount);
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

        return DB::transaction(function () use ($data, $actor, $byLine, $runIds): PayrollPayment {
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
            }

            /** @var array<int, Money> $payableByBranch */
            $payableByBranch = [];

            foreach ($byLine as $lineId => $amount) {
                $lineBranchId = (int) $lines[$lineId]->branch_id;
                $payableByBranch[$lineBranchId] = ($payableByBranch[$lineBranchId] ?? Money::zero())->plus($amount);
            }

            ksort($payableByBranch);

            $payingBranchId = static::payingBranchId($data['branch_id'] ?? null, array_keys($payableByBranch), $actor);
            $total = Money::sum($byLine);

            $voucherLines = [];

            foreach ($payableByBranch as $lineBranchId => $amount) {
                $voucherLines[] = ['account_id' => HrAccounts::id(HrAccounts::SALARY_PAYABLE), 'debit' => $amount->toString(), 'credit' => '0', 'narration' => 'Settlement', 'branch_id' => $lineBranchId];
            }

            $voucherLines[] = ['account_id' => $settlementAccountId, 'debit' => '0', 'credit' => $total->toString(), 'narration' => 'Amount paid', 'branch_id' => $payingBranchId];

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
                    lineNarration: fn (JournalVoucher $voucher): string => SettlementNarration::line(static::numberFor($voucher), $data['payment_mode']),
                    autoBalance: true,
                );
            } catch (BranchRuleViolation $exception) {
                throw new InvalidArgumentException($exception->getMessage(), 0, $exception);
            }

            $payment = PayrollPayment::create([
                'date' => $data['date'],
                'amount' => $total->toString(),
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
