<?php

namespace App\Support\Hr;

use App\Models\PayrollLine;
use App\Models\PayrollPaymentLine;
use App\Models\StaffAdvance;
use App\Models\StaffMember;
use App\Support\Branches\BranchContext;
use App\Support\Money\Money;
use App\Support\NepaliCalendar;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

class StaffStatementData
{
    /**
     * @return array{
     *     staff: array{id: int, staff_code: string, name: string, designation: ?string, department: ?string},
     *     filters: array{from: ?string, to: ?string},
     *     openingSalary: string,
     *     openingAdvance: string,
     *     closingSalary: string,
     *     closingAdvance: string,
     *     salaryEntries: list<array<string, mixed>>,
     *     advanceEntries: list<array<string, mixed>>,
     *     advanceSummary: array{given: string, recovered_in_payroll: string, adjusted_at_payment: string, adjusted: string, outstanding: string}
     * }
     */
    public static function build(StaffMember $staff, ?string $from = null, ?string $to = null): array
    {
        $statuses = ($from !== null || $to !== null) ? ['posted', 'cancelled'] : ['posted'];

        $salary = collect();
        $advance = collect();

        BranchContext::unrestricted(fn () => PayrollLine::query()
            ->where('staff_id', $staff->id)
            ->whereHas('run', fn ($run) => $run->whereIn('status', $statuses))
            ->with(['run:id,period_label,date,run_number,series_branch_id,status,reversal_journal_voucher_id', 'run.reversalJournalVoucher:id,date'])
            ->get())
            ->each(function (PayrollLine $line) use ($salary, $advance): void {
                // The payroll voucher credits Salary Payable with the net, TDS
                // Payable with the TDS and SSF / CIT Payable with the employee
                // shares; this payable-side history shows the salary credited
                // before those withholdings and each one as its own line, which
                // together add up to exactly that net credit. Employer SSF and
                // CIT are employer cost and never appear here.
                $withheld = [
                    'Salary TDS withheld' => Money::of($line->tds),
                    'SSF withheld' => Money::of($line->ssf_employee ?? '0'),
                    'CIT withheld' => Money::of($line->cit_employee ?? '0'),
                ];

                self::pushWithReversal($salary, $line->run, [
                    'date' => $line->run->date->toDateString(),
                    'date_bs' => NepaliCalendar::formatBs($line->run->date),
                    'order' => 1,
                    'reference' => $line->run->displayNumber(),
                    'description' => 'Payroll '.$line->run->period_label,
                    'debit' => '0.00',
                    'credit' => Money::of($line->net)->plus(Money::sum($withheld))->toString(),
                ]);

                foreach ($withheld as $label => $amount) {
                    if ($amount->isPositive()) {
                        self::pushWithReversal($salary, $line->run, [
                            'date' => $line->run->date->toDateString(),
                            'date_bs' => NepaliCalendar::formatBs($line->run->date),
                            'order' => 1,
                            'reference' => $line->run->displayNumber(),
                            'description' => $label.' - '.$line->run->period_label,
                            'debit' => $amount->toString(),
                            'credit' => '0.00',
                        ]);
                    }
                }

                if (Money::of($line->advance_recovery)->isPositive()) {
                    self::pushWithReversal($advance, $line->run, [
                        'date' => $line->run->date->toDateString(),
                        'date_bs' => NepaliCalendar::formatBs($line->run->date),
                        'order' => 2,
                        'reference' => $line->run->displayNumber(),
                        'description' => 'Recovered in payroll '.$line->run->period_label,
                        'debit' => '0.00',
                        'credit' => $line->advance_recovery,
                    ]);
                }
            });

        BranchContext::unrestricted(fn () => PayrollPaymentLine::query()
            ->whereHas('payrollLine', fn ($line) => $line->where('staff_id', $staff->id))
            ->whereHas('payment', fn ($payment) => $payment->whereIn('status', $statuses))
            ->with(['payment:id,date,payment_number,payment_mode,status,reversal_journal_voucher_id', 'payment.reversalJournalVoucher:id,date'])
            ->get())
            ->each(function (PayrollPaymentLine $row) use ($salary, $advance): void {
                $adjusted = Money::of($row->advance_adjusted);
                $paid = Money::of($row->amount)->minus($adjusted);
                $date = $row->payment->date;

                // Settled against Salary Payable = paid out + offset against the
                // advance; shown as two lines (only the one that exists).
                if ($paid->isPositive()) {
                    self::pushWithReversal($salary, $row->payment, [
                        'date' => $date->toDateString(),
                        'date_bs' => NepaliCalendar::formatBs($date),
                        'order' => 2,
                        'reference' => $row->payment->payment_number,
                        'description' => 'Salary payment ('.$row->payment->payment_mode.')',
                        'debit' => $paid->toString(),
                        'credit' => '0.00',
                    ]);
                }

                if ($adjusted->isPositive()) {
                    self::pushWithReversal($salary, $row->payment, [
                        'date' => $date->toDateString(),
                        'date_bs' => NepaliCalendar::formatBs($date),
                        'order' => 2,
                        'reference' => $row->payment->payment_number,
                        'description' => 'Advance adjusted against salary',
                        'debit' => $adjusted->toString(),
                        'credit' => '0.00',
                    ]);

                    self::pushWithReversal($advance, $row->payment, [
                        'date' => $date->toDateString(),
                        'date_bs' => NepaliCalendar::formatBs($date),
                        'order' => 2,
                        'reference' => $row->payment->payment_number,
                        'description' => 'Adjusted against salary payment',
                        'debit' => '0.00',
                        'credit' => $adjusted->toString(),
                    ]);
                }
            });

        BranchContext::unrestricted(fn () => StaffAdvance::query()
            ->where('staff_id', $staff->id)
            ->whereIn('status', $statuses)
            ->with('reversalJournalVoucher:id,date')
            ->get())
            ->each(function (StaffAdvance $row) use ($advance): void {
                self::pushWithReversal($advance, $row, [
                    'date' => $row->date->toDateString(),
                    'date_bs' => NepaliCalendar::formatBs($row->date),
                    'order' => 1,
                    'reference' => $row->advance_number,
                    'description' => 'Advance given ('.$row->payment_mode.')',
                    'debit' => $row->amount,
                    'credit' => '0.00',
                ]);
            });

        $dayBefore = $from !== null ? Carbon::parse($from)->subDay()->toDateString() : null;
        $openingSalary = $dayBefore !== null ? StaffBalances::payable($staff->id, $dayBefore) : Money::zero();
        $openingAdvance = $dayBefore !== null ? StaffBalances::advance($staff->id, $dayBefore) : Money::zero();

        $inRange = fn (Collection $entries): Collection => $entries->filter(
            fn (array $entry): bool => ($from === null || $entry['date'] >= $from) && ($to === null || $entry['date'] <= $to),
        );

        $salaryEntries = self::withRunningBalance($inRange($salary), creditNormal: true, opening: $openingSalary);
        $advanceEntries = self::withRunningBalance($inRange($advance), creditNormal: false, opening: $openingAdvance);

        $closingSalary = ! empty($salaryEntries) ? end($salaryEntries)['balance'] : $openingSalary->toString();
        $closingAdvance = ! empty($advanceEntries) ? end($advanceEntries)['balance'] : $openingAdvance->toString();

        return [
            'staff' => $staff->only(['id', 'staff_code', 'name', 'designation', 'department']),
            'filters' => ['from' => $from, 'to' => $to],
            'openingSalary' => $openingSalary->toString(),
            'openingAdvance' => $openingAdvance->toString(),
            'closingSalary' => $closingSalary,
            'closingAdvance' => $closingAdvance,
            'salaryEntries' => $salaryEntries,
            'advanceEntries' => $advanceEntries,
            'advanceSummary' => StaffBalances::advanceSummary($staff->id),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $entries
     * @param  array<string, mixed>  $entry
     */
    private static function pushWithReversal(Collection $entries, Model $document, array $entry): void
    {
        $entries->push($entry);

        if ($document->status !== 'cancelled' || $document->reversalJournalVoucher === null) {
            return;
        }

        $reversalDate = $document->reversalJournalVoucher->date->toDateString();

        $entries->push([
            'date' => $reversalDate,
            'date_bs' => NepaliCalendar::formatBs($reversalDate),
            'order' => 3,
            'reference' => $entry['reference'],
            'description' => 'Cancelled: '.$entry['description'],
            'debit' => $entry['credit'],
            'credit' => $entry['debit'],
        ]);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $entries
     * @return list<array<string, mixed>>
     */
    private static function withRunningBalance(Collection $entries, bool $creditNormal, Money $opening): array
    {
        $balance = $opening;

        return $entries
            ->sortBy([['date', 'asc'], ['order', 'asc']])
            ->values()
            ->map(function (array $entry) use (&$balance, $creditNormal): array {
                $movement = $creditNormal
                    ? Money::of($entry['credit'])->minus($entry['debit'])
                    : Money::of($entry['debit'])->minus($entry['credit']);
                $balance = $balance->plus($movement);

                return Arr::except($entry, 'order') + ['balance' => $balance->toString()];
            })
            ->all();
    }
}
