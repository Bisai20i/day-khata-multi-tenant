<?php

namespace App\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\Admin\StoreBranchRequest;
use App\Http\Requests\Tenant\Admin\UpdateBranchLabelRequest;
use App\Http\Requests\Tenant\Admin\UpdateBranchRequest;
use App\Models\Branch;
use App\Models\CompanySetting;
use App\Models\Store;
use App\Models\User;
use App\Support\Branches\ActiveBranchSelection;
use App\Support\Branches\BranchContext;
use App\Support\Branches\BranchDeactivationGuard;
use App\Support\Branches\BranchRuleViolation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Branches admin page (plan 12, sections 5.1 and 5.10, BR10): list,
 * create and edit branches, deactivate and reactivate them, and choose what
 * the company calls a branch (branch, site, outlet, project).
 *
 * Rules that live elsewhere and are only surfaced here:
 * - Branches are never deleted and Main can never be deactivated or stop
 *   being Main (Branch::booted(), BranchRuleViolation).
 * - A store's branch is changed on the Stores page and freezes once the
 *   store is used (Store::updating, invariant I5).
 * - Deactivation is refused while anything open would dead-end in the branch
 *   (BranchDeactivationGuard), with the list of what to clear.
 *
 * Writing requires access to every branch, on top of can:branches.manage: a
 * user limited to some branches must not reshape (or switch off) branches
 * outside their reach. Every write calls BranchContext::flush() so the same
 * request never answers from a stale active flag.
 */
class BranchController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $actor */
        $actor = $request->user();

        // A branch-restricted viewer sees only the branches they reach, only
        // those branches' stores, and no user-assignment counts.
        $reach = BranchContext::allowedBranchIds($actor);

        $stores = Store::query()
            ->when($reach !== null, fn ($query) => $query->whereIn('branch_id', $reach))
            ->orderBy('name')
            ->get(['id', 'name', 'branch_id', 'is_active'])
            ->groupBy('branch_id');
        $assignedUsers = $reach !== null ? collect() : DB::table('branch_user')
            ->join('users', 'users.id', '=', 'branch_user.user_id')
            ->where('users.all_branches', false)
            ->groupBy('branch_user.branch_id')
            ->selectRaw('branch_user.branch_id, COUNT(*) as aggregate')
            ->pluck('aggregate', 'branch_user.branch_id');

        $branches = Branch::query()
            ->when($reach !== null, fn ($query) => $query->whereIn('id', $reach))
            ->orderByDesc('is_main')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (Branch $branch): array => [
                'id' => $branch->id,
                'name' => $branch->name,
                'code' => $branch->code,
                'series_code' => $branch->series_code,
                'address' => $branch->address,
                'phone' => $branch->phone,
                'email' => $branch->email,
                'pan_number' => $branch->pan_number,
                'default_store_id' => $branch->default_store_id,
                'sort_order' => (int) $branch->sort_order,
                'is_main' => (bool) $branch->is_main,
                'is_active' => (bool) $branch->is_active,
                'stores' => ($stores->get($branch->id) ?? collect())
                    ->map(fn (Store $store): array => ['id' => $store->id, 'name' => $store->name, 'is_active' => (bool) $store->is_active])
                    ->values()
                    ->all(),
                'restricted_users_count' => $reach !== null ? null : (int) ($assignedUsers[$branch->id] ?? 0),
            ])
            ->values();

        return Inertia::render('Tenant/Admin/Branches/Index', [
            'branches' => $branches,
            'branchLabel' => CompanySetting::current()->branch_label ?? 'branch',
            'branchLabels' => ActiveBranchSelection::LABELS,
            'canManage' => $actor->hasPermission('branches.manage') && $reach === null,
        ]);
    }

    public function store(StoreBranchRequest $request): RedirectResponse
    {
        $this->assertActorReachesEveryBranch($request);
        $data = $request->validated();

        Branch::create([
            ...$data,
            'sort_order' => $data['sort_order'] ?? 0,
            'is_main' => false,
            'is_active' => true,
        ]);

        BranchContext::flush();

        return redirect()->route('tenant.branches.index')->with('status', 'Branch added.');
    }

    public function update(UpdateBranchRequest $request, Branch $branch): RedirectResponse
    {
        $this->assertActorReachesEveryBranch($request);
        $data = $request->validated();

        try {
            DB::transaction(function () use ($branch, $data): void {
                /** @var Branch $locked */
                $locked = Branch::query()->lockForUpdate()->findOrFail($branch->getKey());
                $locked->fill([...$data, 'sort_order' => $data['sort_order'] ?? $locked->sort_order]);
                $locked->save();
            });
        } catch (BranchRuleViolation $exception) {
            throw ValidationException::withMessages([$exception->field ?? 'name' => $exception->getMessage()]);
        }

        BranchContext::flush();

        return redirect()->route('tenant.branches.index')->with('status', 'Branch updated.');
    }

    /**
     * Refused for Main, and while BranchDeactivationGuard finds anything that
     * would dead-end in the branch. The branch row is locked first, so the
     * check reads after any deactivation or reactivation that won the race.
     *
     * Posting paths share-lock the branch row only in a per-branch numbering
     * year (SeriesNumber::seriesBranchFor); in a shared-series year they do
     * not lock it, so a document filed in the same instant as this check can
     * still slip in. The engine then refuses every later posting in the
     * branch, and the BR23 audit lists it.
     */
    public function deactivate(Request $request, Branch $branch): RedirectResponse
    {
        $this->assertActorReachesEveryBranch($request);

        /** @var User $actor */
        $actor = $request->user();

        try {
            $changed = DB::transaction(function () use ($branch, $actor): bool {
                /** @var Branch $locked */
                $locked = Branch::query()->lockForUpdate()->findOrFail($branch->getKey());

                if ($locked->is_main) {
                    throw BranchRuleViolation::mainCannotBeDeactivated();
                }

                if (! $locked->is_active) {
                    return false;
                }

                $blockers = BranchDeactivationGuard::blockers($locked);

                if ($blockers !== []) {
                    throw ValidationException::withMessages([
                        'deactivate' => BranchDeactivationGuard::message($locked, $blockers, $actor),
                    ]);
                }

                $locked->is_active = false;
                $locked->save();

                return true;
            });
        } catch (BranchRuleViolation $exception) {
            throw ValidationException::withMessages(['deactivate' => $exception->getMessage()]);
        }

        BranchContext::flush();

        return redirect()->route('tenant.branches.index')
            ->with('status', $changed ? "{$branch->name} deactivated." : "{$branch->name} was already inactive.");
    }

    public function reactivate(Request $request, Branch $branch): RedirectResponse
    {
        $this->assertActorReachesEveryBranch($request);

        DB::transaction(function () use ($branch): void {
            /** @var Branch $locked */
            $locked = Branch::query()->lockForUpdate()->findOrFail($branch->getKey());

            if (! $locked->is_active) {
                $locked->is_active = true;
                $locked->save();
            }
        });

        BranchContext::flush();

        return redirect()->route('tenant.branches.index')->with('status', "{$branch->name} reactivated.");
    }

    public function updateLabel(UpdateBranchLabelRequest $request): RedirectResponse
    {
        $this->assertActorReachesEveryBranch($request);

        CompanySetting::current()->update(['branch_label' => $request->validated('branch_label')]);

        return redirect()->route('tenant.branches.index')->with('status', 'Label updated.');
    }

    /**
     * Branch administration is company-wide, so a user limited to some
     * branches gets 403 even when their role carries branches.manage.
     */
    private function assertActorReachesEveryBranch(Request $request): void
    {
        /** @var User $actor */
        $actor = $request->user();

        if (BranchContext::allowedBranchIds($actor) !== null) {
            abort(403, 'Only a user with access to every branch can manage branches.');
        }
    }
}
