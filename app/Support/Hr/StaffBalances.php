<?php

namespace App\Support\Hr;

use App\Models\FiscalYear;
use App\Models\JournalVoucherLine;
use App\Models\PayrollLine;
use App\Models\PayrollPaymentLine;
use App\Models\StaffAdvance;
use App\Support\Branches\BranchContext;
use App\Support\Money\Money;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * The HR sub-ledger: what each staff member is owed (payable) and owes
 * (advance), computed from the HR documents on demand - never stored, so it
 * cannot drift from them. The ledger side lives in the two control accounts
 * (HrAccounts); reconciliation() compares the two and is what catches a hand
 * edit or a bug.
 *
 * Every figure honours an optional "as at" date (Y-m-d, inclusive) and then
 * mirrors exactly what the ledger held on that day: a run, payment or advance
 * counts from its DOCUMENT date, and a cancelled one is taken back out only
 * from the date of its REVERSAL voucher (dated the day it was cancelled). So
 * a run posted in June and cancelled in September still counts as at July,
 * as its voucher does on a July Balance Sheet. (A future-dated document
 * cancelled before its own date shows negative between the two dates - which
 * is also what the ledger shows.) Without a date - the "current" figure -
 * only posted documents count: a cancelled one and its reversal net to nothing.
 */
class StaffBalances
{
    /**
     * Unpaid net salary per staff id (only staff with a non-zero balance are
     * omitted; a zero balance is simply absent from the array).
     *
     * @return array<int, Money>
     */
    public static function payables(?string $asOf = null, ?int $staffId = null): array
    {
        $owed = [];

        foreach (static::passes($asOf) as $reversals => $sign) {
            PayrollLine::query()
                ->whereHas('run', static::documentsAt($asOf, $reversals))
                ->when($staffId, fn ($q) => $q->where('staff_id', $staffId))
                ->get(['staff_id', 'net'])
                ->each(function (PayrollLine $line) use (&$owed, $sign): void {
                    $owed[$line->staff_id] = static::apply($owed[$line->staff_id] ?? Money::zero(), $line->net, $sign);
                });

            PayrollPaymentLine::query()
                ->whereHas('payment', static::documentsAt($asOf, $reversals))
                ->whereHas('payrollLine', fn ($line) => $line->when($staffId, fn ($q) => $q->where('staff_id', $staffId)))
                ->with('payrollLine:id,staff_id')
                ->get(['payroll_line_id', 'amount'])
                ->each(function (PayrollPaymentLine $row) use (&$owed, $sign): void {
                    $id = $row->payrollLine->staff_id;
                    $owed[$id] = static::apply($owed[$id] ?? Money::zero(), $row->amount, -$sign);
                });
        }

        return array_filter($owed, fn (Money $amount): bool => ! $amount->isZero());
    }

    public static function payable(int $staffId, ?string $asOf = null): Money
    {
        return static::payables($asOf, $staffId)[$staffId] ?? Money::zero();
    }

    /**
     * Outstanding (given, not yet recovered) advance per staff id.
     *
     * @return array<int, Money>
     */
    public static function advances(?string $asOf = null, ?int $staffId = null): array
    {
        $balance = [];

        foreach (static::passes($asOf) as $reversals => $sign) {
            StaffAdvance::query()
                ->where(static::documentsAt($asOf, $reversals))
                ->when($staffId, fn ($q) => $q->where('staff_id', $staffId))
                ->get(['staff_id', 'amount'])
                ->each(function (StaffAdvance $advance) use (&$balance, $sign): void {
                    $balance[$advance->staff_id] = static::apply($balance[$advance->staff_id] ?? Money::zero(), $advance->amount, $sign);
                });

            PayrollLine::query()
                ->where('advance_recovery', '>', 0)
                ->whereHas('run', static::documentsAt($asOf, $reversals))
                ->when($staffId, fn ($q) => $q->where('staff_id', $staffId))
                ->get(['staff_id', 'advance_recovery'])
                ->each(function (PayrollLine $line) use (&$balance, $sign): void {
                    $balance[$line->staff_id] = static::apply($balance[$line->staff_id] ?? Money::zero(), $line->advance_recovery, -$sign);
                });
        }

        return array_filter($balance, fn (Money $amount): bool => ! $amount->isZero());
    }

    public static function advance(int $staffId, ?string $asOf = null): Money
    {
        return static::advances($asOf, $staffId)[$staffId] ?? Money::zero();
    }

    /**
     * HR sub-ledger totals against the two control accounts for one fiscal
     * year, as at a date. `difference` (ledger minus staff ledger) is zero when
     * the books agree. The ledger side counts every voucher line of that year
     * up to the date, so a cancelled document counts until its reversal's date
     * and the two then net to nothing, exactly as on the Balance Sheet; the
     * staff side follows the same dates (see the class docblock).
     *
     * @return array{payable: array{ledger: string, staff: string, difference: string}, advance: array{ledger: string, staff: string, difference: string}}
     */
    public static function reconciliation(FiscalYear $fiscalYear, string $asOf): array
    {
        $payableLedger = static::ledgerBalance(HrAccounts::SALARY_PAYABLE, $fiscalYear, $asOf, creditNormal: true);
        $advanceLedger = static::ledgerBalance(HrAccounts::STAFF_ADVANCES_RECEIVABLE, $fiscalYear, $asOf, creditNormal: false);

        $payableStaff = Money::sum(static::payables($asOf));
        $advanceStaff = Money::sum(static::advances($asOf));

        return [
            'payable' => [
                'ledger' => $payableLedger->toString(),
                'staff' => $payableStaff->toString(),
                'difference' => $payableLedger->minus($payableStaff)->toString(),
            ],
            'advance' => [
                'ledger' => $advanceLedger->toString(),
                'staff' => $advanceStaff->toString(),
                'difference' => $advanceLedger->minus($advanceStaff)->toString(),
            ],
        ];
    }

    /**
     * The passes a balance makes, as `pass => sign`: pass 0 adds the documents
     * themselves; with an "as at" date pass 1 takes back out the cancelled
     * ones whose reversal had been posted by then. Without a date only posted
     * documents count, so there is no reversal pass.
     *
     * @return array<int, int>
     */
    private static function passes(?string $asOf): array
    {
        return $asOf === null ? [0 => 1] : [0 => 1, 1 => -1];
    }

    /**
     * Constrains a run / payment / advance query to the documents one pass
     * counts. Pass 0: posted (no date), or posted-or-cancelled dated on or
     * before the date - a cancelled document stayed in the ledger until its
     * reversal. Pass 1: cancelled documents whose reversal voucher is dated on
     * or before the date. A subquery per pass, never a query per row.
     *
     * @return Closure(Builder): void
     */
    private static function documentsAt(?string $asOf, int $pass): Closure
    {
        return function (Builder $document) use ($asOf, $pass): void {
            if ($asOf === null) {
                $document->where('status', 'posted');

                return;
            }

            if ($pass === 1) {
                // The HR sub-ledger is company-wide, so the reversal voucher
                // is matched outside the branch access scope (applied when
                // whereHas() builds its subquery, i.e. inside this frame).
                $document->where('status', 'cancelled');
                BranchContext::unrestricted(fn () => $document
                    ->whereHas('reversalJournalVoucher', fn (Builder $voucher) => $voucher->whereDate('date', '<=', $asOf)));

                return;
            }

            $document->whereIn('status', ['posted', 'cancelled'])->whereDate('date', '<=', $asOf);
        };
    }

    private static function apply(Money $balance, string $amount, int $sign): Money
    {
        return $sign > 0 ? $balance->plus($amount) : $balance->minus($amount);
    }

    /**
     * Net balance of a control account within a fiscal year up to a date,
     * signed so the account's normal side is positive.
     */
    private static function ledgerBalance(string $code, FiscalYear $fiscalYear, string $asOf, bool $creditNormal): Money
    {
        $debit = Money::zero();
        $credit = Money::zero();

        // Unrestricted: the control account is reconciled against the whole
        // company-wide sub-ledger, so every branch's lines count.
        BranchContext::unrestricted(fn () => JournalVoucherLine::query()
            ->where('account_id', HrAccounts::id($code))
            ->whereHas('journalVoucher', fn ($voucher) => $voucher
                ->where('fiscal_year_id', $fiscalYear->id)
                ->whereDate('date', '<=', $asOf))
            ->get(['debit', 'credit']))
            ->each(function (JournalVoucherLine $line) use (&$debit, &$credit): void {
                $debit = $debit->plus($line->debit);
                $credit = $credit->plus($line->credit);
            });

        return $creditNormal ? $credit->minus($debit) : $debit->minus($credit);
    }
}
