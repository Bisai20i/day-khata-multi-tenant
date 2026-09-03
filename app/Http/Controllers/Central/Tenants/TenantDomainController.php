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
