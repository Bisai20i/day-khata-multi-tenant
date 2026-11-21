<?php

namespace App\Http\Controllers\Tenant\Hr;

use App\Enums\FiscalYearStatus;
use App\Exports\HrTableExport;
use App\Http\Controllers\Controller;
use App\Models\FiscalYear;
use App\Models\PayComponent;
use App\Models\PayrollLine;
use App\Models\StaffMember;
use App\Support\AsOfDate;
use App\Support\Hr\StaffBalances;
use App\Support\Hr\StaffStatementData;
use App\Support\Money\Money;
use Carbon\Carbon;
use Illuminate\Http\Request;
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

        $headings = ['Run', 'Period', 'Date', 'Staff', ...array_column($components, 'name'), 'Gross', 'Advance recovered', 'Salary TDS', 'Employee SSF', 'Employer SSF', 'Employee CIT', 'Employer CIT', 'Net', 'Paid', 'Outstanding'];

        $rows = array_map(fn (array $row): array => [
            $row['run_number'],
            $row['period_label'],
            $row['date'],
            $row['staff_name'],
            ...array_map(fn (array $component): string => $row['amounts'][$component['id']] ?? '0.00', $components),
            $row['gross'], $row['advance_recovery'], $row['tds'],
            $row['ssf_employee'], $row['ssf_employer'], $row['cit_employee'], $row['cit_employer'],
            $row['net'], $row['paid'], $row['outstanding'],
        ], $data['rows']);

        $rows[] = [
            '', '', '', 'Total',
            ...array_map(fn (array $component): string => $data['totals']['components'][$component['id']] ?? '0.00', $components),
            $data['totals']['gross'], $data['totals']['advance_recovery'], $data['totals']['tds'],
            $data['totals']['ssf_employee'], $data['totals']['ssf_employer'], $data['totals']['cit_employee'], $data['totals']['cit_employer'],
            $data['totals']['net'], $data['totals']['paid'], $data['totals']['outstanding'],
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

        $from = ($rawFrom = $request->string('from')->toString()) !== '' ? Carbon::parse($rawFrom)->toDateString() : null;
        $to = ($rawTo = $request->string('to')->toString()) !== '' ? Carbon::parse($rawTo)->toDateString() : null;

        $data = StaffStatementData::build($staff, $from, $to);

        return Inertia::render('Tenant/Hr/Reports/StaffStatement', [
            'staff' => $data['staff'],
            'filters' => ['from' => $from, 'to' => $to],
            'openingSalary' => $data['openingSalary'],
            'openingAdvance' => $data['openingAdvance'],
            'closingSalary' => $data['closingSalary'],
            'closingAdvance' => $data['closingAdvance'],
            'salaryEntries' => $data['salaryEntries'],
            'advanceEntries' => $data['advanceEntries'],
            'advanceSummary' => $data['advanceSummary'],
            'staffOptions' => StaffMember::query()
                ->orderBy('name')
                ->get(['id', 'staff_code', 'name'])
                ->map(fn (StaffMember $s) => [
                    'value' => $s->id,
                    'label' => $s->staff_code ? "{$s->name} ({$s->staff_code})" : $s->name,
                    'searchValue' => "{$s->name} {$s->staff_code}",
                ])
                ->values(),
        ]);
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
                'tds' => $line->tds,
                'ssf_employee' => $line->ssf_employee,
                'ssf_employer' => $line->ssf_employer,
                'cit_employee' => $line->cit_employee,
                'cit_employer' => $line->cit_employer,
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
                'tds' => $column('tds'),
                'ssf_employee' => $column('ssf_employee'),
                'ssf_employer' => $column('ssf_employer'),
                'cit_employee' => $column('cit_employee'),
                'cit_employer' => $column('cit_employer'),
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
