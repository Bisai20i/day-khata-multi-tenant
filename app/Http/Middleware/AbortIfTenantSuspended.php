<?php

namespace App\Http\Middleware;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;

/**
 * Blocks a real inbound request with a 403 if the tenant that was just
 * resolved for this request has been suspended by a platform admin, or isn't
 * ready yet (its database is created/migrated/seeded asynchronously via the
 * queued TenantCreated job pipeline — see App\Jobs\CreateTenantFirstAdmin and
 * TenancyServiceProvider).
 *
 * This is route middleware (registered in routes/tenant.php, right after
 * InitializeTenancyByDomain), not a TenancyInitialized event listener. It
 * used to be the latter, but that event also fires for every *internal*
 * tenancy()->initialize()/$tenant->run() call the provisioning pipeline
 * itself makes while status is still Provisioning (MigrateDatabase,
 * SeedDatabase, CreateTenantFirstAdmin) — so the event-listener version
 * aborted the pipeline on its own first step, every time, and the tenant
 * (and its rolled-back-then-deleted row) never actually finished
 * provisioning. Scoping the check to route middleware means it only ever
 * sees genuine end-user requests into a tenant's domain.
 */
class AbortIfTenantSuspended
{
    public function handle(Request $request, Closure $next)
    {
        /** @var Tenant $tenant */
        $tenant = tenant();

        if ($tenant->status === TenantStatus::Suspended) {
            abort(403, 'This tenant account has been suspended.');
        }

        if ($tenant->status === TenantStatus::Provisioning) {
            abort(403, 'This tenant is still being set up. Please try again in a moment.');
        }

        return $next($request);
    }
}
