<?php

namespace App\Http\Controllers\Tenant\Hr;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\PayrollLine;
use App\Models\PayrollPayment;
use App\Rules\AccountUnderHead;
use App\Support\Branches\ActiveBranchSelection;
use App\Support\Branches\BranchFormOptions;
use App\Support\Branches\BranchListFilter;
use App\Support\Hr\HrAccounts;
use App\Support\Hr\SalaryPaymentPoster;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class PayrollPaymentController extends Controller
{
    /**
     * Branches (plan 12, BR12). HR is not branch-scoped (plan 5.7), so the
     * list only follows the switcher: a payment shows under a branch when
     * the money left from it or it settles a payslip of that branch. The
     * paying branch is named only when several branches can appear, and the
     * form offers a paying-branch picker whose blank choice keeps the
     * poster's default (the payslips' branch, BR09). Not multi-branch: same
     * query and payload as before.
     */
    public function index(Request $request): Response
    {
        $isMultiBranch = ActiveBranchSelection::isMultiBranch($request);
        $narrowedBranchId = ActiveBranchSelection::narrowedBranchId($request);
        $branchNames = BranchListFilter::columnVisible($request) ? $this->branchNames() : null;

        return Inertia::render('Tenant/Hr/SalaryPayments/Index', [
            'payments' => PayrollPayment::query()
                ->with(['lines.payrollLine.staff:id,name'])
                ->when($narrowedBranchId, fn ($query, int $branchId) => $query->where(fn ($inBranch) => $inBranch
                    ->where('branch_id', $branchId)
                    ->orWhereHas('lines.payrollLine', fn ($line) => $line->where('branch_id', $branchId))))
                ->orderByDesc('date')
                ->orderByDesc('id')
                ->paginate(25)
                ->withQueryString()
                ->through(fn (PayrollPayment $payment): array => [
                    'id' => $payment->id,
                    'payment_number' => $payment->payment_number,
                    'date' => $payment->date->toDateString(),
                    'amount' => $payment->amount,
                    'payment_mode' => $payment->payment_mode,
                    'reference_number' => $payment->reference_number,
                    'status' => $payment->status,
                    'cancel_reason' => $payment->cancel_reason,
                    'staff_names' => $payment->lines->map(fn ($row) => $row->payrollLine->staff->name)->unique()->values(),
                ] + ($branchNames === null ? [] : [
                    'branch_id' => (int) $payment->branch_id,
                    'branch' => $branchNames[(int) $payment->branch_id] ?? null,
                ])),
            'outstandingLines' => $this->outstandingLines($isMultiBranch),
            ...BranchFormOptions::props($request),
            // Money leaves through a non-party asset account, so only those are offered.
            'bankAccounts' => HrAccounts::paymentSourceAccounts(),
            'today' => now()->setTimezone('Asia/Kathmandu')->toDateString(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'payment_mode' => ['required', 'in:cash,bank'],
            'bank_account_id' => ['nullable', 'required_if:payment_mode,bank', 'exists:accounts,id', new AccountUnderHead('Assets')],
            'reference_number' => ['nullable', 'string', 'max:255'],
            'narration' => ['nullable', 'string', 'max:255'],
            // Paying branch (plan 12, BR09); SalaryPaymentPoster checks it
            // against the actor and defaults it.
            'branch_id' => ['nullable', 'integer'],
            'allocations' => ['required', 'array', 'min:1'],
            'allocations.*.payroll_line_id' => ['required', 'distinct', 'exists:hr_payroll_lines,id'],
            'allocations.*.amount' => ['required', 'numeric', 'min:0.01', 'decimal:0,2', 'max:999999999999999999.99'],
        ]);

        try {
            SalaryPaymentPoster::post($data, $request->user());
        } catch (InvalidArgumentException|AuthorizationException $e) {
            // A refused paying branch belongs on the branch picker (plan 12,
            // BR12); payslip and amount problems stay under `allocations`.
            return back()->withErrors([BranchFormOptions::errorField($e, 'allocations') => $e->getMessage()])->withInput();
        }

        return redirect()->route('tenant.hr.salary-payments.index')->with('status', 'Salary payment recorded.');
    }

    public function cancel(Request $request, PayrollPayment $payment): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        try {
            SalaryPaymentPoster::cancel($payment, $request->user(), $data['reason']);
        } catch (InvalidArgumentException|AuthorizationException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return redirect()->route('tenant.hr.salary-payments.index')->with('status', 'Salary payment cancelled.');
    }

    /**
     * Payslips of posted runs that still have something owing. A
     * multi-branch company also gets each payslip's branch, so the form can
     * show where the Salary Payable side of the payment lands.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function outstandingLines(bool $withBranch = false): Collection
    {
        $lines = PayrollLine::query()
            ->whereHas('run', fn ($run) => $run->where('status', 'posted'))
            ->with(['run:id,period_label,date,run_number,series_branch_id', 'staff:id,staff_code,name'])
            ->orderBy('id')
            ->get();

        $outstanding = PayrollLine::outstandingAmounts($lines);

        return $lines
            ->filter(fn (PayrollLine $line): bool => $outstanding[$line->id]->isPositive())
            ->map(fn (PayrollLine $line): array => [
                'id' => $line->id,
                'staff_id' => $line->staff_id,
                'staff_code' => $line->staff->staff_code,
                'staff_name' => $line->staff->name,
                'run_number' => $line->run->displayNumber(),
                'period_label' => $line->run->period_label,
                'run_date' => $line->run->date->toDateString(),
                'net' => $line->net,
                'outstanding' => $outstanding[$line->id]->toString(),
                ...($withBranch ? ['branch_id' => (int) $line->branch_id] : []),
            ])
            ->sortBy([['staff_name', 'asc'], ['run_date', 'asc']])
            ->values();
    }

    /**
     * Every branch by id, inactive ones included, for the list's branch
     * badges. HR rows are not branch-scoped (plan 5.7), so a restricted
     * viewer can see rows of branches outside their own, which the shared
     * `branch` prop could not name.
     *
     * @return array<int, array{id: int, name: string, code: string}>
     */
    private function branchNames(): array
    {
        return Branch::query()->get(['id', 'name', 'code'])
            ->mapWithKeys(fn (Branch $branch): array => [(int) $branch->id => [
                'id' => (int) $branch->id,
                'name' => (string) $branch->name,
                'code' => (string) $branch->code,
            ]])
            ->all();
    }
}
