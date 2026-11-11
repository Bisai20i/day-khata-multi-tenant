<?php

namespace App\Http\Controllers\Tenant\Hr;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\StaffAdvance;
use App\Models\StaffMember;
use App\Rules\AccountUnderHead;
use App\Support\Branches\ActiveBranchSelection;
use App\Support\Branches\BranchFormOptions;
use App\Support\Branches\BranchListFilter;
use App\Support\Hr\HrAccounts;
use App\Support\Hr\StaffAdvancePoster;
use App\Support\Hr\StaffBalances;
use App\Support\Money\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class StaffAdvanceController extends Controller
{
    /**
     * Branches (plan 12, BR12). HR is not branch-scoped (plan 5.7), so the
     * list only follows the switcher: an advance shows under a branch when
     * the money left from it or its staff member is based there (the
     * receivable posts in the staff member's home branch, which cannot
     * change while an advance is outstanding). The paying branch is named
     * only when several branches can appear, and the form offers a
     * paying-branch picker whose blank choice keeps the poster's default
     * (the staff member's branch, BR09). Not multi-branch: same query and
     * payload as before.
     */
    public function index(Request $request): Response
    {
        $balances = StaffBalances::advances();
        $isMultiBranch = ActiveBranchSelection::isMultiBranch($request);
        $narrowedBranchId = ActiveBranchSelection::narrowedBranchId($request);
        $branchNames = BranchListFilter::columnVisible($request) ? $this->branchNames() : null;

        return Inertia::render('Tenant/Hr/StaffAdvances/Index', [
            'advances' => StaffAdvance::query()
                ->with('staff:id,staff_code,name')
                ->when($narrowedBranchId, fn ($query, int $branchId) => $query->where(fn ($inBranch) => $inBranch
                    ->where('branch_id', $branchId)
                    ->orWhereHas('staff', fn ($staff) => $staff->where('branch_id', $branchId))))
                ->orderByDesc('date')
                ->orderByDesc('id')
                ->paginate(25)
                ->withQueryString()
                ->through(fn (StaffAdvance $advance): array => [
                    'id' => $advance->id,
                    'advance_number' => $advance->advance_number,
                    'date' => $advance->date->toDateString(),
                    'staff_id' => $advance->staff_id,
                    'staff_name' => $advance->staff->name,
                    'amount' => $advance->amount,
                    'payment_mode' => $advance->payment_mode,
                    'narration' => $advance->narration,
                    'status' => $advance->status,
                    'cancel_reason' => $advance->cancel_reason,
                ] + ($branchNames === null ? [] : [
                    'branch_id' => (int) $advance->branch_id,
                    'branch' => $branchNames[(int) $advance->branch_id] ?? null,
                ])),
            'staff' => StaffMember::query()->active()->orderBy('name')
                ->get($isMultiBranch ? ['id', 'staff_code', 'name', 'branch_id'] : ['id', 'staff_code', 'name'])
                ->map(fn (StaffMember $member): array => [
                    'id' => $member->id,
                    'staff_code' => $member->staff_code,
                    'name' => $member->name,
                    'advance' => ($balances[$member->id] ?? Money::zero())->toString(),
                    ...($isMultiBranch ? ['branch_id' => (int) $member->branch_id] : []),
                ])->values(),
            ...BranchFormOptions::props($request),
            'bankAccounts' => HrAccounts::paymentSourceAccounts(),
            'today' => now()->setTimezone('Asia/Kathmandu')->toDateString(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'staff_id' => ['required', 'exists:hr_staff,id'],
            'date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0.01', 'decimal:0,2', 'max:999999999999999999.99'],
            'payment_mode' => ['required', 'in:cash,bank'],
            'bank_account_id' => ['nullable', 'required_if:payment_mode,bank', 'exists:accounts,id', new AccountUnderHead('Assets')],
            'reference_number' => ['nullable', 'string', 'max:255'],
            'narration' => ['nullable', 'string', 'max:255'],
            // Paying branch (plan 12, BR09); StaffAdvancePoster checks it
            // against the actor and defaults it.
            'branch_id' => ['nullable', 'integer'],
        ]);

        try {
            StaffAdvancePoster::post($data, $request->user());
        } catch (InvalidArgumentException|AuthorizationException $e) {
            // A refused paying branch belongs on the branch picker (plan 12,
            // BR12); everything else stays under `amount`.
            return back()->withErrors([BranchFormOptions::errorField($e, 'amount') => $e->getMessage()])->withInput();
        }

        return redirect()->route('tenant.hr.staff-advances.index')->with('status', 'Advance recorded.');
    }

    public function cancel(Request $request, StaffAdvance $advance): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        try {
            StaffAdvancePoster::cancel($advance, $request->user(), $data['reason']);
        } catch (InvalidArgumentException|AuthorizationException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return redirect()->route('tenant.hr.staff-advances.index')->with('status', 'Advance cancelled.');
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
