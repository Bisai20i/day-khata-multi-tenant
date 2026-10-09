<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central\Tenants;

use App\Http\Controllers\Controller;
use App\Models\PlatformAdminActivityLog;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Stancl\Tenancy\Database\Models\Domain;

/**
 * Additional domains beyond the one created at provisioning time.
 * stancl/tenancy already supports a tenant having several - this just adds
 * the UI-facing routes.
 */
class TenantDomainController extends Controller
{
    public function store(Request $request, Tenant $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'domain' => [
                'required', 'string', 'max:255', 'regex:/^[a-z0-9.-]+$/i',
                Rule::unique('domains', 'domain'),
            ],
        ]);

        $tenant->domains()->create(['domain' => $validated['domain']]);

        PlatformAdminActivityLog::record('tenant.domain.add', $tenant, ['domain' => $validated['domain']]);

        return redirect()
            ->route('central.tenants.show', $tenant)
            ->with('status', 'Domain added.');
    }

    /**
     * Change an existing domain in place, e.g. one mistyped at provisioning.
     * The tenant stops answering on the old address immediately, so the
     * caller must type the current domain back (`current_domain`) - enforced
     * here, not just in the UI.
     */
    public function update(Request $request, Tenant $tenant, Domain $domain): RedirectResponse
    {
        if ($domain->tenant_id !== $tenant->id) {
            abort(404);
        }

        $request->merge(['domain' => strtolower(trim((string) $request->input('domain')))]);

        $previousDomain = $domain->domain;

        $validated = $request->validate([
            'domain' => [
                'required', 'string', 'max:255', 'regex:/^[a-z0-9.-]+$/',
                Rule::notIn([$previousDomain, ...config('tenancy.central_domains', [])]),
                Rule::unique('domains', 'domain')->ignore($domain->id),
            ],
            'current_domain' => ['required', 'string', Rule::in([$previousDomain])],
        ], [
            'domain.not_in' => 'Enter a different domain that is not a central domain.',
            'current_domain.in' => 'This does not match the current domain.',
        ]);

        $domain->update(['domain' => $validated['domain']]);

        // A still-provisioning tenant mails its first admin a login link
        // built from this stashed domain (see CreateTenantFirstAdmin).
        $pendingAdmin = $tenant->pending_admin;

        if (is_array($pendingAdmin) && ($pendingAdmin['domain'] ?? null) === $previousDomain) {
            $tenant->pending_admin = [...$pendingAdmin, 'domain' => $validated['domain']];
            $tenant->save();
        }

        PlatformAdminActivityLog::record('tenant.domain.update', $tenant, [
            'from' => $previousDomain,
            'to' => $validated['domain'],
        ]);

        return redirect()
            ->route('central.tenants.show', $tenant)
            ->with('status', 'Domain updated.');
    }

    /**
     * A tenant must always keep at least one domain - it's how its
     * subdomain routing resolves at all - so removing the last one is
     * blocked rather than left to silently break the tenant.
     */
    public function destroy(Tenant $tenant, Domain $domain): RedirectResponse
    {
        if ($domain->tenant_id !== $tenant->id) {
            abort(404);
        }

        if ($tenant->domains()->count() <= 1) {
            return redirect()
                ->route('central.tenants.show', $tenant)
                ->with('status', 'A tenant must have at least one domain.');
        }

        $domain->delete();

        PlatformAdminActivityLog::record('tenant.domain.remove', $tenant, ['domain' => $domain->domain]);

        return redirect()
            ->route('central.tenants.show', $tenant)
            ->with('status', 'Domain removed.');
    }
}
