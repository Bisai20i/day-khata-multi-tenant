<?php

namespace App\Http\Middleware;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Stancl\Tenancy\Contracts\TenantCouldNotBeIdentifiedException;
use Stancl\Tenancy\Resolvers\DomainTenantResolver;

/**
 * Blocks a real inbound request with a 403 if the tenant that owns this
 * domain has been suspended by a platform admin, or isn't ready yet (its
 * database is created/migrated/seeded asynchronously via the queued
 * TenantCreated job pipeline — see App\Jobs\CreateTenantFirstAdmin and
 * TenancyServiceProvider).
 *
 * Runs BEFORE InitializeTenancyByDomain (see TenancyServiceProvider's
 * middleware-priority list and routes/tenant.php), resolving the tenant
 * itself via DomainTenantResolver instead of reading tenant() after tenancy
 * is initialized. This matters specifically for the Provisioning case:
 * DatabaseTenancyBootstrapper only checks "does the tenant database exist"
 * outside the `local` environment when app()->environment('local') is true,
 * so under `testing`/`production` a request into a tenant whose database
 * hasn't been created yet made tenancy()->initialize() throw a raw, uncaught
 * SQLite "database file does not exist" exception instead of a clean 403.
 * Checking status before tenancy is ever initialized avoids that database
 * connection attempt entirely.
 *
 * This is route middleware, not a TenancyInitialized event listener, for a
 * second, independent reason: that event also fires for every *internal*
 * tenancy()->initialize()/$tenant->run() call the provisioning pipeline
 * itself makes while status is still Provisioning (MigrateDatabase,
 * SeedDatabase, CreateTenantFirstAdmin) — an event listener aborts the
 * pipeline on its own first step, every time. Route middleware only ever
 * sees genuine end-user requests into a tenant's domain.
 */
class AbortIfTenantSuspended
{
    public function __construct(private DomainTenantResolver $resolver) {}

    public function handle(Request $request, Closure $next)
    {
        try {
            /** @var Tenant $tenant */
            $tenant = $this->resolver->resolve($request->getHost());
        } catch (TenantCouldNotBeIdentifiedException) {
            // Not a recognized tenant domain — let InitializeTenancyByDomain
            // (which runs right after this middleware) handle that failure
            // the normal way.
            return $next($request);
        }

        if ($tenant->status === TenantStatus::Suspended) {
            abort(403, 'This tenant account has been suspended.');
        }

        if ($tenant->status === TenantStatus::Provisioning) {
            abort(403, 'This tenant is still being set up. Please try again in a moment.');
        }

        return $next($request);
    }
}
