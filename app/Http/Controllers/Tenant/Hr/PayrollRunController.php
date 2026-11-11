<?php

namespace App\Http\Controllers\Tenant\Hr;

use App\Enums\FiscalYearStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\Hr\SavePayrollRunRequest;
use App\Models\Branch;
use App\Models\FiscalYear;
use App\Models\PayComponent;
use App\Models\PayrollLine;
use App\Models\PayrollPaymentLine;
use App\Models\PayrollRun;
use App\Models\StaffMember;
use App\Support\Branches\ActiveBranchSelection;
use App\Support\Branches\BranchFormOptions;
use App\Support\Branches\BranchListFilter;
use App\Support\Hr\PayrollDraft;
use App\Support\Hr\PayrollPoster;
use App\Support\Hr\StaffBalances;
use App\Support\Money\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class PayrollRunController extends Controller
{
    public function index(Request $request): Response
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $year = FiscalYear::query()->where('status', FiscalYearStatus::Open)->first();
        $requestedFrom = $request->string('from')->toString() ?: null;
        $requestedTo = $request->string('to')->toString() ?: null;
        $from = $requestedFrom ?? $year?->start_date?->toDateString();
        $to = $requestedTo ?? $year?->end_date?->toDateString();
        $status = $request->string('status')->toString();

        // Branches (plan 12, BR12). HR is not branch-scoped (plan 5.7), so
        // this is the switcher's narrowing only: a run shows under a branch
        // when it was generated there or holds a payslip of that branch (a
        // company-wide run files each payslip in its staff member's branch).
        // The run's own branch is named only when several branches can
        // appear. Not multi-branch: same query and payload as before.
        $narrowedBranchId = ActiveBranchSelection::narrowedBranchId($request);
        $branchNames = BranchListFilter::columnVisible($request) ? $this->branchNames() : null;

        $runs = PayrollRun::query()
            ->withCount('lines')
            ->when($narrowedBranchId, fn ($query, int $branchId) => $query->where(fn ($inBranch) => $inBranch
                ->where('branch_id', $branchId)
                ->orWhereHas('lines', fn ($lines) => $lines->where('branch_id', $branchId))))
            ->when($from, fn ($query) => $query->whereDate('date', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('date', '<=', $to))
            ->when(in_array($status, [PayrollRun::STATUS_DRAFT, PayrollRun::STATUS_POSTED, PayrollRun::STATUS_CANCELLED], true), fn ($query) => $query->where('status', $status))
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        // One query for the page's branch-series codes (none in shared years).
        PayrollRun::loadSeriesCodes($runs->getCollection());

        $runs->through(fn (PayrollRun $run): array => $this->summary($run) + (
            $branchNames === null ? [] : ['branch_id' => (int) $run->branch_id, 'branch' => $branchNames[(int) $run->branch_id] ?? null]
        ));

        return Inertia::render('Tenant/Hr/Payroll/Index', [
            'runs' => $runs,
            // `filters` is only what the user asked for (so the page can show and
            // clear chips); `window` is the range actually applied, which falls
            // back to the open fiscal year.
            'filters' => ['from' => $requestedFrom, 'to' => $requestedTo, 'status' => $status ?: null],
            'window' => ['from' => $from, 'to' => $to],
        ]);
    }

    /**
     * Branches (plan 12, BR12): a multi-branch company picks the run's branch
     * (its origin, PayrollDraft) and may include the staff of every branch
     * it can use (`all_branches`). Not multi-branch: same props as before.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('Tenant/Hr/Payroll/Create', [
            'today' => now()->setTimezone('Asia/Kathmandu')->toDateString(),
            ...BranchFormOptions::props($request),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'period_label' => ['required', 'string', 'max:100'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'date' => ['required', 'date'],
            'narration' => ['nullable', 'string', 'max:200'],
            // Plan 12, BR09: the run's branch (checked by PayrollDraft against
            // the actor), and whether to include staff of every branch.
            'branch_id' => ['nullable', 'integer'],
            'all_branches' => ['sometimes', 'boolean'],
        ]);

        try {
            $run = PayrollDraft::generate($data, $request->user());
        } catch (InvalidArgumentException $e) {
            // A refused branch belongs on the branch picker (plan 12, BR12).
            return back()->withErrors([BranchFormOptions::errorField($e, 'period_start') => $e->getMessage()])->withInput();
        }

        return redirect()->route('tenant.hr.payroll.show', $run)->with('status', 'Draft payroll run created. Review it, then post.');
    }

    public function show(PayrollRun $run): Response
    {
        $run->load([
            'lines.staff:id,staff_code,name',
            'lines.items.component:id,name',
            'fiscalYear:id,name',
            'creator:id,name',
            'poster:id,name',
            'canceller:id,name',
        ]);

        $lines = $run->lines->sortBy(fn (PayrollLine $line) => $line->staff->name)->values();
        $outstanding = $run->isPosted() ? PayrollLine::outstandingAmounts($lines) : [];
        $paid = $run->isPosted() ? PayrollLine::paidAmounts($lines->pluck('id')) : [];
        $isDraft = $run->isDraft();

        return Inertia::render('Tenant/Hr/Payroll/Show', [
            'run' => $this->summary($run) + [
                'narration' => $run->narration,
                'fiscal_year' => $run->fiscalYear?->name,
                'created_by' => $run->creator?->name,
                'posted_by' => $run->poster?->name,
                'posted_at' => $run->posted_at?->toDateTimeString(),
                'cancelled_by' => $run->canceller?->name,
                'cancelled_at' => $run->cancelled_at?->toDateTimeString(),
                'cancel_reason' => $run->cancel_reason,
                'journal_voucher_id' => $run->journal_voucher_id,
                'has_live_payments' => $run->isPosted() && PayrollPaymentLine::query()
                    ->whereIn('payroll_line_id', $lines->pluck('id'))
                    ->whereHas('payment', fn ($payment) => $payment->where('status', 'posted'))
                    ->exists(),
            ],
            'lines' => $lines->map(fn (PayrollLine $line): array => [
                'id' => $line->id,
                'staff_id' => $line->staff_id,
                'staff_code' => $line->staff->staff_code,
                'staff_name' => $line->staff->name,
                'gross' => $line->gross,
                'advance_recovery' => $line->advance_recovery,
                'net' => $line->net,
                'note' => $line->note,
                'paid' => ($paid[$line->id] ?? Money::zero())->toString(),
                'outstanding' => ($outstanding[$line->id] ?? Money::zero())->toString(),
                'items' => $line->items->map(fn ($item): array => [
                    'pay_component_id' => $item->pay_component_id,
                    'component_name' => $item->component->name,
                    'amount' => $item->amount,
                    'note' => $item->note,
                ])->values(),
            ])->values(),
            // Only a draft is editable, so only a draft needs the pickers.
            'components' => $isDraft ? PayComponent::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'code', 'name']) : [],
            'staffOptions' => $isDraft ? StaffMember::query()->active()->orderBy('name')->get(['id', 'staff_code', 'name']) : [],
            'advanceBalances' => $isDraft
                ? collect(StaffBalances::advances())->map(fn (Money $amount): string => $amount->toString())
                : (object) [],
        ]);
    }

    public function update(SavePayrollRunRequest $request, PayrollRun $run): RedirectResponse
    {
        try {
            PayrollDraft::replace($run, $request->validated(), $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['lines' => $e->getMessage()])->withInput();
        }

        return back()->with('status', 'Draft saved.');
    }

    public function destroy(PayrollRun $run): RedirectResponse
    {
        try {
            PayrollDraft::discard($run);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['run' => $e->getMessage()]);
        }

        return redirect()->route('tenant.hr.payroll.index')->with('status', 'Draft payroll run discarded.');
    }

    public function post(Request $request, PayrollRun $run): RedirectResponse
    {
        try {
            PayrollPoster::post($run, $request->user());
        } catch (InvalidArgumentException|AuthorizationException $e) {
            return back()->withErrors(['run' => $e->getMessage()]);
        }

        return back()->with('status', 'Payroll posted to the ledger.');
    }

    public function cancel(Request $request, PayrollRun $run): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        try {
            PayrollPoster::cancel($run, $request->user(), $data['reason']);
        } catch (InvalidArgumentException|AuthorizationException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return back()->with('status', 'Payroll run cancelled.');
    }

    /**
     * Every branch by id, inactive ones included (an old run may sit in a
     * branch that has since closed), for the list's branch badges. HR rows
     * are not branch-scoped, so a restricted viewer can see runs of
     * branches outside their own; the shared `branch` prop could not name
     * those.
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

    /**
     * @return array<string, mixed>
     */
    private function summary(PayrollRun $run): array
    {
        return [
            'id' => $run->id,
            'display_number' => $run->displayNumber(),
            'period_label' => $run->period_label,
            'period_start' => $run->period_start->toDateString(),
            'period_end' => $run->period_end->toDateString(),
            'date' => $run->date->toDateString(),
            'status' => $run->status,
            'lines_count' => $run->lines_count ?? $run->lines->count(),
            'total_gross' => $run->total_gross,
            'total_recovery' => $run->total_recovery,
            'total_net' => $run->total_net,
        ];
    }
}
