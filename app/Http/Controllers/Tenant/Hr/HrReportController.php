<?php

namespace App\Http\Controllers\Tenant\Hr;

use App\Enums\FiscalYearStatus;
use App\Exports\HrTableExport;
use App\Http\Controllers\Controller;
use App\Models\FiscalYear;
use App\Models\PayComponent;
use App\Models\PayrollLine;
use App\Models\PayrollPaymentLine;
use App\Models\StaffAdvance;
use App\Models\StaffMember;
use App\Support\AsOfDate;
use App\Support\Branches\BranchContext;
use App\Support\Hr\StaffBalances;
use App\Support\Money\Money;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * HR reports: payroll register (what was paid, per staff and component, for a
 * date window), staff balances (payable and advance per staff, reconciled to
 * the ledger's control accounts) and a per-staff statement. All are read-only
 * views over the HR tables; the ledger side is the existing Account Ledger /
 * Balance Sheet.
 */
class HrReportController extends Controller
{
    public function payrollRegister(Request $request): Response
    {
        return Inertia::render('Tenant/Hr/Reports/PayrollRegister', $this->registerData($request));
    }

    public function payrollRegisterExport(Request $request): BinaryFileResponse
    {
        $data = $this->registerData($request);
        $components = $data['components'];

        $headings = ['Run', 'Period', 'Date', 'Staff', ...array_column($components, 'name'), 'Gross', 'Advance recovered', 'Net', 'Paid', 'Outstanding'];

        $rows = array_map(fn (array $row): array => [
            $row['run_number'],
            $row['period_label'],
            $row['date'],
            $row['staff_name'],
            ...array_map(fn (array $component): string => $row['amounts'][$component['id']] ?? '0.00', $components),
            $row['gross'], $row['advance_recovery'], $row['net'], $row['paid'], $row['outstanding'],
        ], $data['rows']);

        $rows[] = [
            '', '', '', 'Total',
            ...array_map(fn (array $component): string => $data['totals']['components'][$component['id']] ?? '0.00', $components),
            $data['totals']['gross'], $data['totals']['advance_recovery'], $data['totals']['net'], $data['totals']['paid'], $data['totals']['outstanding'],
        ];

        $moneyColumns = range(4, count($headings) - 1);

        return Excel::download(new HrTableExport($headings, $rows, $moneyColumns), 'payroll-register.xlsx');
    }

    public function staffBalances(Request $request): Response
    {
        return Inertia::render('Tenant/Hr/Reports/StaffBalances', $this->balancesData($request));
    }

    public function staffBalancesExport(Request $request): BinaryFileResponse
    {
        $data = $this->balancesData($request);

        $rows = array_map(fn (array $row): array => [
            $row['staff_code'], $row['staff_name'], $row['payable'], $row['advance'],
        ], $data['rows']);

        $rows[] = ['', 'Total', $data['totals']['payable'], $data['totals']['advance']];

        return Excel::download(
            new HrTableExport(['Code', 'Staff', 'Salary payable', 'Advance receivable'], $rows, [2, 3]),
            'staff-balances.xlsx',
        );
    }

    /**
     * Per-staff statement. With no dates it lists every posted document (a
     * cancelled one and its reversal net to nothing, so neither is shown). With
     * `from` and/or `to` it follows StaffBalances' "as at" rules instead: a
     * cancelled document stays in as its own line until a separate reversal
     * line, dated the day it was cancelled, takes it back out, and the balance
     * before `from` is carried in as an opening balance so the running balance
     * stays correct.
     */
    public function statement(Request $request, StaffMember $staff): Response
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        // The `date` rule accepts anything strtotime() does ("2026-7-1", a
        // time suffix); normalise so the string comparisons below hold.
        $from = ($rawFrom = $request->string('from')->toString()) !== '' ? Carbon::parse($rawFrom)->toDateString() : null;
        $to = ($rawTo = $request->string('to')->toString()) !== '' ? Carbon::parse($rawTo)->toDateString() : null;
        $statuses = ($from !== null || $to !== null) ? ['posted', 'cancelled'] : ['posted'];

        $salary = collect();
        $advance = collect();

        // HR rows are company-wide; the reversal vouchers they eager-load are
        // read outside the branch access scope too (plan 12, BR09 carry-
        // forward c), so a cancelled document always gets its offset line.
        BranchContext::unrestricted(fn () => PayrollLine::query()
            ->where('staff_id', $staff->id)
            ->whereHas('run', fn ($run) => $run->whereIn('status', $statuses))
            ->with(['run:id,period_label,date,run_number,series_branch_id,status,reversal_journal_voucher_id', 'run.reversalJournalVoucher:id,date'])
            ->get())
            ->each(function (PayrollLine $line) use ($salary, $advance): void {
                $this->pushWithReversal($salary, $line->run, [
                    'date' => $line->run->date->toDateString(), 'order' => 1, 'reference' => $line->run->displayNumber(),
                    'description' => 'Payroll '.$line->run->period_label, 'debit' => '0.00', 'credit' => $line->net,
                ]);

                if (Money::of($line->advance_recovery)->isPositive()) {
                    $this->pushWithReversal($advance, $line->run, [
                        'date' => $line->run->date->toDateString(), 'order' => 2, 'reference' => $line->run->displayNumber(),
                        'description' => 'Recovered in payroll '.$line->run->period_label, 'debit' => '0.00', 'credit' => $line->advance_recovery,
                    ]);
                }
            });

        BranchContext::unrestricted(fn () => PayrollPaymentLine::query()
            ->whereHas('payrollLine', fn ($line) => $line->where('staff_id', $staff->id))
            ->whereHas('payment', fn ($payment) => $payment->whereIn('status', $statuses))
            ->with(['payment:id,date,payment_number,payment_mode,status,reversal_journal_voucher_id', 'payment.reversalJournalVoucher:id,date'])
            ->get())
            ->each(function (PayrollPaymentLine $row) use ($salary): void {
                $this->pushWithReversal($salary, $row->payment, [
                    'date' => $row->payment->date->toDateString(), 'order' => 2, 'reference' => $row->payment->payment_number,
                    'description' => 'Salary payment ('.$row->payment->payment_mode.')', 'debit' => $row->amount, 'credit' => '0.00',
                ]);
            });

        BranchContext::unrestricted(fn () => StaffAdvance::query()
            ->where('staff_id', $staff->id)
            ->whereIn('status', $statuses)
            ->with('reversalJournalVoucher:id,date')
            ->get())
            ->each(function (StaffAdvance $row) use ($advance): void {
                $this->pushWithReversal($advance, $row, [
                    'date' => $row->date->toDateString(), 'order' => 1, 'reference' => $row->advance_number,
                    'description' => 'Advance given ('.$row->payment_mode.')', 'debit' => $row->amount, 'credit' => '0.00',
                ]);
            });

        // Balances carried in from before `from`: the same as-at figures the
        // Staff Balances report shows for the day before.
        $dayBefore = $from !== null ? Carbon::parse($from)->subDay()->toDateString() : null;
        $openingSalary = $dayBefore !== null ? StaffBalances::payable($staff->id, $dayBefore) : Money::zero();
        $openingAdvance = $dayBefore !== null ? StaffBalances::advance($staff->id, $dayBefore) : Money::zero();

        $inRange = fn (Collection $entries): Collection => $entries->filter(
            fn (array $entry): bool => ($from === null || $entry['date'] >= $from) && ($to === null || $entry['date'] <= $to),
        );

        return Inertia::render('Tenant/Hr/Reports/StaffStatement', [
            'staff' => $staff->only(['id', 'staff_code', 'name', 'designation', 'department']),
            'filters' => ['from' => $from, 'to' => $to],
            'openingSalary' => $openingSalary->toString(),
            'openingAdvance' => $openingAdvance->toString(),
            // Salary: credit = owed to the staff member, so balance = credit - debit.
            'salaryEntries' => $this->withRunningBalance($inRange($salary), creditNormal: true, opening: $openingSalary),
            // Advance: debit = given, so balance = debit - credit.
            'advanceEntries' => $this->withRunningBalance($inRange($advance), creditNormal: false, opening: $openingAdvance),
        ]);
    }

    /**
     * Adds a document's line and, when it has been cancelled and its reversal
     * voucher exists, the offsetting line on the reversal's date (debit and
     * credit swapped). Only the date-filtered statement loads cancelled
     * documents, so the unfiltered one never gets a reversal line.
     *
     * @param  Collection<int, array<string, mixed>>  $entries
     * @param  array<string, mixed>  $entry
     */
    private function pushWithReversal(Collection $entries, Model $document, array $entry): void
    {
        $entries->push($entry);

        if ($document->status !== 'cancelled' || $document->reversalJournalVoucher === null) {
            return;
        }

        $entries->push([
            'date' => $document->reversalJournalVoucher->date->toDateString(),
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
    private function withRunningBalance(Collection $entries, bool $creditNormal, Money $opening): array
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

    /**
     * @return array<string, mixed>
     */
    private function registerData(Request $request): array
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'staff_id' => ['nullable', 'integer'],
        ]);

        $year = FiscalYear::query()->where('status', FiscalYearStatus::Open)->first();
        $from = $request->string('from')->toString() ?: $year?->start_date?->toDateString();
        $to = $request->string('to')->toString() ?: $year?->end_date?->toDateString();
        $staffId = $request->integer('staff_id') ?: null;

        $lines = PayrollLine::query()
            ->whereHas('run', fn ($run) => $run
                ->where('status', 'posted')
                ->when($from, fn ($q) => $q->whereDate('date', '>=', $from))
                ->when($to, fn ($q) => $q->whereDate('date', '<=', $to)))
            ->when($staffId, fn ($q) => $q->where('staff_id', $staffId))
            ->with(['run:id,period_label,date,run_number,series_branch_id', 'staff:id,staff_code,name', 'items'])
            ->get()
            ->sortBy([fn ($a, $b) => $a->run->date <=> $b->run->date, fn ($a, $b) => $a->staff->name <=> $b->staff->name])
            ->values();

        $paid = PayrollLine::paidAmounts($lines->pluck('id'));

        $components = PayComponent::query()
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (PayComponent $component): array => ['id' => $component->id, 'name' => $component->name])
            ->all();

        $componentTotals = [];

        $rows = $lines->map(function (PayrollLine $line) use ($paid, &$componentTotals): array {
            $amounts = [];

            foreach ($line->items as $item) {
                $amounts[$item->pay_component_id] = $item->amount;
                $componentTotals[$item->pay_component_id] = ($componentTotals[$item->pay_component_id] ?? Money::zero())->plus($item->amount);
            }

            return [
                'line_id' => $line->id,
                'run_id' => $line->payroll_run_id,
                'run_number' => $line->run->displayNumber(),
                'period_label' => $line->run->period_label,
                'date' => $line->run->date->toDateString(),
                'staff_id' => $line->staff_id,
                'staff_name' => $line->staff->name,
                'amounts' => $amounts,
                'gross' => $line->gross,
                'advance_recovery' => $line->advance_recovery,
                'net' => $line->net,
                'paid' => $paid[$line->id]->toString(),
                'outstanding' => Money::of($line->net)->minus($paid[$line->id])->toString(),
            ];
        })->all();

        $column = fn (string $key): string => Money::sum(array_column($rows, $key))->toString();

        return [
            'filters' => ['from' => $from, 'to' => $to, 'staff_id' => $staffId],
            'staffOptions' => StaffMember::query()->orderBy('name')->get(['id', 'staff_code', 'name']),
            'components' => $components,
            'rows' => $rows,
            'totals' => [
                'components' => array_map(fn (Money $amount): string => $amount->toString(), $componentTotals),
                'gross' => $column('gross'),
                'advance_recovery' => $column('advance_recovery'),
                'net' => $column('net'),
                'paid' => $column('paid'),
                'outstanding' => $column('outstanding'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function balancesData(Request $request): array
    {
        $asOf = AsOfDate::resolve($request->input('as_of'));

        $payables = StaffBalances::payables($asOf);
        $advances = StaffBalances::advances($asOf);

        $rows = StaffMember::query()
            ->whereIn('id', array_unique([...array_keys($payables), ...array_keys($advances)]))
            ->orderBy('name')
            ->get(['id', 'staff_code', 'name'])
            ->map(fn (StaffMember $member): array => [
                'staff_id' => $member->id,
                'staff_code' => $member->staff_code,
                'staff_name' => $member->name,
                'payable' => ($payables[$member->id] ?? Money::zero())->toString(),
                'advance' => ($advances[$member->id] ?? Money::zero())->toString(),
            ])
            ->all();

        // The fiscal year the date falls in; a date outside every year has no
        // ledger side to compare against.
        $fiscalYear = FiscalYear::query()
            ->whereDate('start_date', '<=', $asOf)
            ->whereDate('end_date', '>=', $asOf)
            ->first();

        return [
            'asOf' => $asOf,
            'rows' => $rows,
            'totals' => [
                'payable' => Money::sum($payables)->toString(),
                'advance' => Money::sum($advances)->toString(),
            ],
            'reconciliation' => $fiscalYear ? StaffBalances::reconciliation($fiscalYear, $asOf) + ['fiscal_year' => $fiscalYear->name] : null,
        ];
    }
}
