<?php

namespace App\Http\Controllers\Central\Tenants;

use App\Http\Controllers\Controller;
use App\Http\Requests\Central\UpdateTenantModulesRequest;
use App\Models\PlatformAdminActivityLog;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;

/**
 * Central control of which modules a tenant is entitled to (plan section
 * 3.2). The tenant side reads `enabled_modules` straight off the tenant row
 * that InitializeTenancyByDomain already loads, so a save here takes effect
 * on the tenant's very next request: menus disappear and the Gate returns 403
 * for every route of a removed module, even for the owner. Role grants inside
 * the tenant are left untouched, so switching a module back on restores them.
 */
class TenantModuleController extends Controller
{
    /**
     * Replace the tenant's module selection with the canonical, dependency
     * resolved form of the request (see
     * UpdateTenantModulesRequest::canonicalModules()), and audit the change.
     *
     * The whole list is replaced, so two admins saving at once is last write
     * wins; both saves are logged with what they replaced, which is enough to
     * reconstruct the sequence. An unchanged save writes nothing and logs
     * nothing, matching TenantController::update().
     */
    public function update(UpdateTenantModulesRequest $request, Tenant $tenant): RedirectResponse
    {
        $before = $tenant->enabled_modules;
        $beforeEntitled = $tenant->entitledModules();
        $after = $request->canonicalSelection();

        if ($before === $after) {
            return redirect()
                ->route('central.tenants.show', $tenant)
                ->with('status', 'Modules unchanged.');
        }

        $tenant->update(['enabled_modules' => $after]);
        $afterEntitled = $tenant->entitledModules();

        // `before` is the raw stored value (null for a row that was never
        // backfilled, which reads as core only), `after` the new stored value.
        // added/removed compare what the tenant could actually use, so the log
        // reads correctly even when `before` was not in canonical form.
        $added = array_values(array_diff($afterEntitled, $beforeEntitled));
        $removed = array_values(array_diff($beforeEntitled, $afterEntitled));

        PlatformAdminActivityLog::record('tenant.update_modules', $tenant, [
            'before' => $before,
            'after' => $after,
            'added' => $added,
            'removed' => $removed,
        ]);

        $this->recordBranchesToggle($tenant, $added, $removed);

        return redirect()
            ->route('central.tenants.show', $tenant)
            ->with('status', 'Modules updated.');
    }

    /**
     * Multiple branches is the one module whose switch changes how a
     * company's books are split, so its toggle also gets its own audit entry
     * (plan 12 BR24), searchable by action without unpacking the generic
     * update_modules diff. The switch-off was already cleared by
     * BranchModuleGuard in UpdateTenantModulesRequest. platform_admin_id on
     * the row records who did it.
     *
     * @param  list<string>  $added
     * @param  list<string>  $removed
     */
    private function recordBranchesToggle(Tenant $tenant, array $added, array $removed): void
    {
        $module = UpdateTenantModulesRequest::BRANCHES_MODULE;

        if (in_array($module, $added, true)) {
            PlatformAdminActivityLog::record('tenant.module_branches_enabled', $tenant, ['module' => $module]);
        } elseif (in_array($module, $removed, true)) {
            PlatformAdminActivityLog::record('tenant.module_branches_disabled', $tenant, ['module' => $module]);
        }
    }
}
