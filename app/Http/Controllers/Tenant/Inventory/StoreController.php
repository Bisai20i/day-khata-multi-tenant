<?php

namespace App\Http\Controllers\Tenant\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Store;
use App\Support\Branches\ActiveBranchSelection;
use App\Support\Branches\BranchContext;
use App\Support\Branches\BranchRuleViolation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Stores are stock locations and each belongs to exactly one branch (plan
 * 12, section 2). The branch is chosen here and can change only while the
 * store has no stock movement and no document (invariant I5, enforced under a
 * row lock in Store::updating, which this controller turns into a form
 * error). Moving a business between branches is done by transfer, never by
 * re-tagging a used store.
 */
class StoreController extends Controller
{
    public function index(Request $request): Response
    {
        $reach = $this->reachOf($request);

        $stores = Store::query()
            ->with('branch:id,name')
            ->when($reach !== null, fn ($query) => $query->whereIn('branch_id', $reach))
            ->orderBy('name')
            ->get();

        // The lock only matters to the branch field, which a single-branch
        // tenant never sees; skip the per-store usage probes there.
        $multiBranch = ActiveBranchSelection::isMultiBranch($request);

        return Inertia::render('Tenant/Inventory/Stores/Index', [
            'stores' => $stores->map(fn (Store $store): array => [
                'id' => $store->id,
                'name' => $store->name,
                'address' => $store->address,
                'phone' => $store->phone,
                'is_active' => (bool) $store->is_active,
                'branch_id' => (int) $store->branch_id,
                'branch_name' => $store->branch?->name,
                // Read-only once used (I5); the page disables the field.
                'branch_locked' => $multiBranch && $store->hasMovementsOrDocuments(),
            ])->values(),
            'branches' => Branch::query()
                ->when($reach !== null, fn ($query) => $query->whereIn('id', $reach))
                ->orderByDesc('is_main')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['id', 'name', 'is_active'])
                ->map(fn (Branch $branch): array => [
                    'id' => $branch->id,
                    'name' => $branch->name,
                    'is_active' => (bool) $branch->is_active,
                ])
                ->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        // Resolved here, not left to Store::booted()'s Main default, so the
        // access check below runs on the branch the store really lands in.
        if (! isset($data['branch_id'])) {
            try {
                $data['branch_id'] = BranchContext::postingBranchId($request->user());
            } catch (BranchRuleViolation $exception) {
                throw ValidationException::withMessages(['branch_id' => $exception->getMessage()]);
            }
        }

        $this->assertBranchUsable($request, (int) $data['branch_id']);

        Store::create($data);

        return redirect()->route('tenant.stores.index')->with('status', 'Store added.');
    }

    public function update(Request $request, Store $store): RedirectResponse
    {
        $this->assertReachesCurrentBranch($request, $store);
        $data = $this->validated($request);

        if (array_key_exists('branch_id', $data) && (int) $data['branch_id'] !== (int) $store->branch_id) {
            $this->assertBranchUsable($request, (int) $data['branch_id']);
        }

        try {
            // Store::save() wraps a branch change in its own transaction and
            // locks the row before checking usage; this outer transaction
            // also covers clearing the old branch's default store, so the two
            // writes land together.
            DB::transaction(function () use ($store, $data): void {
                $oldBranchId = (int) $store->branch_id;
                $store->fill($data);
                $movesBranch = $store->isDirty('branch_id');
                $deactivates = $store->isDirty('is_active') && ! $store->is_active;
                $store->save();

                // A branch must not keep as its default a store it no longer
                // owns, or one that is inactive (the branch form would then
                // refuse to save).
                if ($movesBranch || $deactivates) {
                    $this->clearStaleDefaultStore($oldBranchId, (int) $store->getKey());
                }
            });
        } catch (BranchRuleViolation $exception) {
            throw ValidationException::withMessages(['branch_id' => $exception->getMessage()]);
        }

        BranchContext::flush();

        return redirect()->route('tenant.stores.index')->with('status', 'Store updated.');
    }

    public function destroy(Request $request, Store $store): RedirectResponse
    {
        $this->assertReachesCurrentBranch($request, $store);

        $store->delete();

        return redirect()->route('tenant.stores.index')->with('status', 'Store deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
            // sometimes: a client that does not send it keeps the store's
            // branch (and a new store lands in Main, see Store::booted()).
            'branch_id' => ['sometimes', 'required', 'integer', 'exists:branches,id'],
        ]);
    }

    /**
     * The branch ids the actor reaches, or null for every branch.
     *
     * @return list<int>|null
     */
    private function reachOf(Request $request): ?array
    {
        return BranchContext::allowedBranchIds($request->user());
    }

    /**
     * Editing, moving or deleting a store needs reach over the branch it is
     * in now. Reach only, not "active" (the same rule as
     * StaffController::homeBranchChange()): an unused store in a deactivated
     * branch must stay movable by someone who reaches that branch.
     */
    private function assertReachesCurrentBranch(Request $request, Store $store): void
    {
        $reach = $this->reachOf($request);

        if ($reach !== null && ! in_array((int) $store->branch_id, $reach, true)) {
            abort(403, 'This store belongs to a branch you do not have access to.');
        }
    }

    /**
     * A store may only be placed in an active branch the actor can use
     * (invariant I6).
     *
     * @throws ValidationException
     */
    private function assertBranchUsable(Request $request, ?int $branchId): void
    {
        if ($branchId === null) {
            return;
        }

        if (! BranchContext::canAccessBranch($branchId, $request->user())) {
            throw ValidationException::withMessages([
                'branch_id' => 'That branch is inactive or you do not have access to it.',
            ]);
        }
    }

    /**
     * The branch the store left must not keep it as its default store.
     */
    private function clearStaleDefaultStore(int $oldBranchId, int $storeId): void
    {
        $oldBranch = Branch::query()->whereKey($oldBranchId)->lockForUpdate()->first();

        if ($oldBranch !== null && (int) $oldBranch->default_store_id === $storeId) {
            $oldBranch->default_store_id = null;
            $oldBranch->save();
        }
    }
}
