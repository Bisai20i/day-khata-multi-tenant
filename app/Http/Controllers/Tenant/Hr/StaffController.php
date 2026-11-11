<?php

namespace App\Http\Controllers\Tenant\Hr;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\Hr\SaveStaffRequest;
use App\Models\PayComponent;
use App\Models\StaffMember;
use App\Models\StaffPayItem;
use App\Models\User;
use App\Support\Branches\BranchContext;
use App\Support\Branches\DocumentBranch;
use App\Support\Hr\StaffBalances;
use App\Support\Hr\StaffPayItemSync;
use App\Support\Money\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class StaffController extends Controller
{
    private const FIELDS = [
        'staff_code', 'name', 'mobile_no', 'address', 'designation', 'department', 'join_date', 'end_date',
        'pan_no', 'bank_name', 'bank_account_no', 'user_id',
    ];

    public function index(Request $request): Response
    {
        $search = $request->string('q')->toString();
        $status = $request->string('status')->toString();

        $staff = StaffMember::query()
            ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('staff_code', 'like', "%{$search}%")
                ->orWhere('designation', 'like', "%{$search}%")))
            ->when($status === 'active', fn ($query) => $query->where('is_active', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_active', false))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        $payables = StaffBalances::payables();
        $advances = StaffBalances::advances();
        $today = now()->setTimezone('Asia/Kathmandu')->toDateString();

        $monthlyPay = StaffPayItem::query()
            ->whereIn('staff_id', $staff->getCollection()->pluck('id'))
            ->inForceOn($today)
            ->get(['staff_id', 'amount'])
            ->groupBy('staff_id')
            ->map(fn ($items) => Money::sum($items->pluck('amount'))->toString());

        $staff->through(fn (StaffMember $member): array => [
            'id' => $member->id,
            'staff_code' => $member->staff_code,
            'name' => $member->name,
            'designation' => $member->designation,
            'department' => $member->department,
            'mobile_no' => $member->mobile_no,
            'is_active' => $member->is_active,
            'monthly_pay' => $monthlyPay[$member->id] ?? '0.00',
            'payable' => ($payables[$member->id] ?? Money::zero())->toString(),
            'advance' => ($advances[$member->id] ?? Money::zero())->toString(),
        ]);

        return Inertia::render('Tenant/Hr/Staff/Index', [
            'staff' => $staff,
            'filters' => ['q' => $search ?: null, 'status' => $status ?: null],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Tenant/Hr/Staff/Form', [
            'staff' => null,
            'payItems' => [],
            'components' => $this->components(),
            'users' => $this->linkableUsers(null),
            'balances' => null,
            'today' => $this->today(),
        ]);
    }

    public function store(SaveStaffRequest $request): RedirectResponse
    {
        $data = $request->validated();

        // Every staff member has a home branch (plan 12, BR09): the chosen
        // one, else the actor's posting branch (Main for a single-branch
        // company). It must be active and one the actor may use; written
        // explicitly rather than left to the column default.
        try {
            $branchId = DocumentBranch::forDocument($request->user(), $data['branch_id'] ?? null)->branchId;
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['branch_id' => $e->getMessage()])->withInput();
        }

        $staff = DB::transaction(function () use ($data, $branchId): StaffMember {
            $staff = StaffMember::create([
                ...Arr::only($data, self::FIELDS),
                'is_active' => $data['is_active'] ?? true,
                'branch_id' => $branchId,
            ]);

            StaffPayItemSync::sync($staff, $data['pay_items'] ?? [], $data['pay_effective_from'] ?? $this->today());

            return $staff;
        });

        return redirect()->route('tenant.hr.staff.edit', $staff)->with('status', 'Staff member added.');
    }

    public function edit(StaffMember $staff): Response
    {
        return Inertia::render('Tenant/Hr/Staff/Form', [
            'staff' => $staff,
            'payItems' => $staff->payItems()
                ->whereNull('effective_to')
                ->orderBy('pay_component_id')
                ->get()
                ->map(fn (StaffPayItem $item): array => [
                    'pay_component_id' => $item->pay_component_id,
                    'amount' => $item->amount,
                    'effective_from' => $item->effective_from->toDateString(),
                ])
                ->values(),
            'components' => $this->components($staff->payItems()->whereNull('effective_to')->pluck('pay_component_id')->all()),
            'users' => $this->linkableUsers($staff),
            'balances' => [
                'payable' => StaffBalances::payable($staff->id)->toString(),
                'advance' => StaffBalances::advance($staff->id)->toString(),
            ],
            'today' => $this->today(),
        ]);
    }

    public function update(SaveStaffRequest $request, StaffMember $staff): RedirectResponse
    {
        $data = $request->validated();
        $actor = $request->user();

        // Which form field a refusal belongs to: the branch move is checked
        // first, the pay change after it.
        $errorField = 'branch_id';

        try {
            DB::transaction(function () use ($data, $staff, $actor, &$errorField): void {
                $branchChange = $this->homeBranchChange($staff, $data, $actor);
                $errorField = 'pay_effective_from';

                $staff->update([
                    ...Arr::only($data, array_diff(self::FIELDS, ['staff_code'])),
                    ...(filled($data['staff_code'] ?? null) ? ['staff_code' => $data['staff_code']] : []),
                    'is_active' => $data['is_active'] ?? $staff->is_active,
                    ...$branchChange,
                ]);

                if (array_key_exists('pay_items', $data)) {
                    StaffPayItemSync::sync($staff, $data['pay_items'] ?? [], $data['pay_effective_from'] ?? $this->today());
                }
            });
        } catch (InvalidArgumentException $e) {
            return back()->withErrors([$errorField => $e->getMessage()])->withInput();
        }

        return back()->with('status', 'Staff member updated.');
    }

    /**
     * The home-branch move the form asks for, as attributes to save: empty
     * when no branch was sent or it is the current one.
     *
     * Runs inside the update's transaction with the staff row locked, the
     * same row StaffAdvancePoster and PayrollPoster lock first, so no advance
     * or payroll can post between the check and the move. Refused while the
     * staff member still owes an advance: the receivable sits in their
     * current branch and payroll recovers it in their home branch, so moving
     * first would leave one branch's receivable never cleared and the other's
     * negative. Unpaid payslips need no check: they keep their own branch,
     * and a draft saved before the move is refused at posting until re-saved.
     *
     * @param  array<string, mixed>  $data
     * @return array{branch_id?: int}
     *
     * @throws InvalidArgumentException
     */
    private function homeBranchChange(StaffMember $staff, array $data, User $actor): array
    {
        if (! filled($data['branch_id'] ?? null)) {
            return [];
        }

        /** @var StaffMember $locked */
        $locked = StaffMember::query()->whereKey($staff->getKey())->lockForUpdate()->firstOrFail();
        $currentBranchId = (int) $locked->branch_id;

        // Unchanged branch first: re-saving a staff member whose home branch
        // has since been deactivated (for example to end their employment)
        // must not be refused by the "active branch you can use" check below.
        if (is_numeric($data['branch_id']) && (int) $data['branch_id'] === $currentBranchId) {
            return [];
        }

        $branchId = DocumentBranch::forDocument($actor, $data['branch_id'])->branchId;

        if ($branchId === $currentBranchId) {
            return [];
        }

        // A restricted actor may not move staff out of a branch they cannot
        // use. Not canAccessBranch(): that also demands an active branch,
        // and staff must still be movable out of a deactivated one.
        $allowed = BranchContext::accessIsLifted() ? null : BranchContext::allowedBranchIds($actor);

        if ($allowed !== null && ! in_array($currentBranchId, $allowed, true)) {
            throw new InvalidArgumentException("You do not have access to {$locked->name}'s current branch, so you cannot move them to another branch.");
        }

        $advance = StaffBalances::advance($locked->id);

        if (! $advance->isZero()) {
            throw new InvalidArgumentException(
                "{$locked->name} still has an outstanding advance of {$advance->toString()} in their current branch. "
                .'Recover it through payroll, or cancel it, before moving them to another branch.'
            );
        }

        return ['branch_id' => $branchId];
    }

    /**
     * Active components, plus any the staff member already holds (a component
     * deactivated afterwards must still show a label on their form).
     *
     * @param  array<int, int>  $heldIds
     * @return Collection<int, PayComponent>
     */
    private function components(array $heldIds = []): Collection
    {
        return PayComponent::query()
            ->where(fn ($query) => $query->where('is_active', true)->orWhereIn('id', $heldIds))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'code', 'name']);
    }

    /**
     * Login users not already linked to another staff member (plus the one
     * this staff member is linked to, so the form can show it).
     *
     * @return Collection<int, User>
     */
    private function linkableUsers(?StaffMember $staff): Collection
    {
        return User::query()
            ->where(fn ($query) => $query
                ->whereNotIn('id', StaffMember::query()->whereNotNull('user_id')->select('user_id'))
                ->when($staff?->user_id, fn ($q, $linked) => $q->orWhere('id', $linked)))
            ->orderBy('name')
            ->get(['id', 'name', 'email']);
    }

    private function today(): string
    {
        return now()->setTimezone('Asia/Kathmandu')->toDateString();
    }
}
