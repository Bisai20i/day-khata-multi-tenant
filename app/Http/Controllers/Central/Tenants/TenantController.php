<?php

namespace App\Http\Controllers\Central\Tenants;

use App\Enums\TenantStatus;
use App\Http\Controllers\Controller;
use App\Models\PlatformAdminActivityLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;
use Stancl\Tenancy\Database\Models\Domain;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

class TenantController extends Controller
{
    /**
     * Display a listing of the tenants, each with its domain.
     */
    public function index(): Response
    {
        $tenants = Tenant::with('domains')->latest()->get();

        return Inertia::render('Central/Tenants/Index', [
            'tenants' => $tenants->map(fn (Tenant $tenant): array => [
                'id' => $tenant->id,
                'company_name' => $tenant->company_name,
                'domain' => $tenant->domains->pluck('domain')->join(', '),
                'status' => $tenant->status->value,
                'created_at' => $tenant->created_at?->toDateString(),
            ]),
        ]);
    }

    /**
     * Show the form for creating a new tenant.
     */
    public function create(): Response
    {
        return Inertia::render('Central/Tenants/Create');
    }

    /**
     * Provision a new tenant: central tenant + domain records, then a fresh
     * tenant database (created/migrated/seeded, and its first admin user
     * created) asynchronously via the queued TenantCreated job pipeline
     * (see App\Jobs\CreateTenantFirstAdmin and TenancyServiceProvider). The
     * tenant starts out `Provisioning` and only flips to `Active` once that
     * pipeline finishes — see AbortIfTenantSuspended for how requests into a
     * still-provisioning tenant are handled in the meantime.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'subdomain' => [
                'required', 'string', 'max:63', 'regex:/^[a-z0-9-]+$/i',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (Domain::where('domain', "{$value}.localhost")->exists()) {
                        $fail('This subdomain is already taken.');
                    }
                },
            ],
            'contact_email' => ['nullable', 'string', 'email', 'max:255'],
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'string', 'email', 'max:255'],
            'admin_password' => ['required', 'string', 'min:8'],
        ]);

        $connection = DB::connection(config('tenancy.database.central_connection'));
        $connection->beginTransaction();

        $tenant = null;

        try {
            // Instantiated and saved separately (rather than Tenant::create())
            // so $tenant still references the row if the TenantCreated job
            // pipeline (which creates/migrates/seeds the tenant database and
            // creates its first admin) throws partway through its own
            // "created" event listeners.
            $tenant = new Tenant([
                'company_name' => $validated['company_name'],
                'status' => TenantStatus::Provisioning,
                'contact_email' => $validated['contact_email'] ?? null,
            ]);

            // Not a real column (see Tenant::getCustomColumns()) — swept into
            // the `data` JSON column and read back by App\Jobs\CreateTenantFirstAdmin
            // once the tenant database exists. Hashed here, never stored in
            // plaintext even transiently.
            $tenant->pending_admin = [
                'name' => $validated['admin_name'],
                'email' => $validated['admin_email'],
                'password' => Hash::make($validated['admin_password']),
            ];

            $tenant->save();

            $tenant->domains()->create([
                'domain' => "{$validated['subdomain']}.localhost",
            ]);

            $connection->commit();
        } catch (Throwable $e) {
            $connection->rollBack();

            // Best-effort cleanup: a tenant database may already have been
            // created/migrated/seeded synchronously before the failure, so
            // delete the (rolled-back) tenant record to fire TenantDeleted
            // and let the existing job pipeline drop the orphaned database.
            if ($tenant?->exists) {
                $tenant->delete();
            }

            throw $e;
        }

        PlatformAdminActivityLog::record('tenant.create', $tenant, [
            'company_name' => $tenant->company_name,
            'subdomain' => $validated['subdomain'],
        ]);

        return redirect()
            ->route('central.tenants.show', $tenant)
            ->with('status', 'Tenant provisioning started — it will be ready shortly.');
    }

    /**
     * Display a single tenant along with its domains.
     */
    public function show(Tenant $tenant): Response
    {
        $tenant->load('domains');

        return Inertia::render('Central/Tenants/Show', [
            'tenant' => [
                'id' => $tenant->id,
                'company_name' => $tenant->company_name,
                'status' => $tenant->status->value,
                'domain' => $tenant->domains->pluck('domain')->join(', '),
                'contact_email' => $tenant->contact_email,
                'created_at' => $tenant->created_at?->toDateString(),
            ],
        ]);
    }

    /**
     * Show the form for editing a tenant's company name and contact email.
     * Domain and status are managed through their own dedicated actions, not
     * this form.
     */
    public function edit(Tenant $tenant): Response
    {
        return Inertia::render('Central/Tenants/Edit', [
            'tenant' => [
                'id' => $tenant->id,
                'company_name' => $tenant->company_name,
                'contact_email' => $tenant->contact_email,
            ],
        ]);
    }

    /**
     * Update a tenant's company name and/or contact email.
     */
    public function update(Request $request, Tenant $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'contact_email' => ['nullable', 'string', 'email', 'max:255'],
        ]);

        $tenant->fill($validated);
        $changed = $tenant->getDirty();

        if ($changed !== []) {
            $tenant->save();

            PlatformAdminActivityLog::record('tenant.update', $tenant, ['changed' => $changed]);
        }

        return redirect()
            ->route('central.tenants.show', $tenant)
            ->with('status', 'Tenant updated.');
    }

    /**
     * Suspend a tenant, blocking further access to its subdomain.
     */
    public function suspend(Tenant $tenant): RedirectResponse
    {
        $tenant->update(['status' => TenantStatus::Suspended]);

        PlatformAdminActivityLog::record('tenant.suspend', $tenant);

        return redirect()
            ->route('central.tenants.show', $tenant)
            ->with('status', 'Tenant suspended.');
    }

    /**
     * Resume a previously suspended tenant.
     */
    public function resume(Tenant $tenant): RedirectResponse
    {
        $tenant->update(['status' => TenantStatus::Active]);

        PlatformAdminActivityLog::record('tenant.resume', $tenant);

        return redirect()
            ->route('central.tenants.show', $tenant)
            ->with('status', 'Tenant resumed.');
    }

    /**
     * Generate a short-lived (5 minute), single-purpose signed URL that logs
     * this platform admin in as the tenant's own admin user, on the
     * tenant's own domain, and send the browser there.
     *
     * The signed URL is validated by the `signed` route middleware on
     * routes/tenant-impersonation.php - it can only ever be used once
     * within its 5-minute window and only for this exact user/tenant, since
     * the signature covers the full URL (including host and the target
     * user's id). Returns Inertia::location() rather than a normal redirect
     * because the target is a different domain: a same-origin Inertia visit
     * can't follow it, so the client is told to perform a full-page,
     * non-XHR navigation instead (Inertia's documented mechanism for
     * external redirects).
     */
    public function impersonate(Tenant $tenant): SymfonyResponse
    {
        $tenant->loadMissing('domains');

        $domain = $tenant->domains->first()?->domain;

        if ($domain === null) {
            return redirect()
                ->route('central.tenants.show', $tenant)
                ->with('status', 'This tenant has no domain configured.');
        }

        $adminUserId = $tenant->run(
            fn (): ?int => User::whereHas('role', fn ($query) => $query->where('slug', 'admin'))->value('id')
        );

        if ($adminUserId === null) {
            return redirect()
                ->route('central.tenants.show', $tenant)
                ->with('status', 'This tenant has no admin user to impersonate.');
        }

        $appUrl = (string) config('app.url');
        $scheme = parse_url($appUrl, PHP_URL_SCHEME) ?: 'http';
        $port = parse_url($appUrl, PHP_URL_PORT);
        $portSuffix = $port !== null ? ":{$port}" : '';

        // Tenant routes aren't registered per-domain (see routes/tenant.php),
        // so route/URL generation has no way to know which tenant's domain
        // to target on its own - forceRootUrl() is how a signed URL destined
        // for a specific tenant's own subdomain is generated from here, on
        // the central domain. The port must be carried over explicitly (e.g.
        // local dev's APP_URL=http://localhost:8000) - parse_url() only
        // returns the scheme, so a bare "{scheme}://{domain}" silently drops
        // it and the signed URL 404s against whatever (if anything) is
        // listening on the default port instead. Reset immediately after so
        // nothing else generated during this request is accidentally scoped
        // to it.
        URL::forceRootUrl("{$scheme}://{$domain}{$portSuffix}");

        $signedUrl = URL::temporarySignedRoute(
            'tenant.impersonate',
            now()->addMinutes(5),
            ['user' => $adminUserId],
        );

        URL::forceRootUrl(null);

        PlatformAdminActivityLog::record('tenant.impersonate', $tenant, [
            'impersonated_user_id' => $adminUserId,
        ]);

        return Inertia::location($signedUrl);
    }

    /**
     * Delete a tenant, cascading to its domains (DB FK) and its database
     * (via the TenantDeleted job pipeline).
     */
    public function destroy(Tenant $tenant): RedirectResponse
    {
        // Recorded before delete(), not after: platform_admin_activity_logs.
        // tenant_id is a real FK (nullOnDelete), and delete() kicks off the
        // async TenantDeleted job pipeline that drops the tenant's database -
        // logging afterwards would race that pipeline and risks losing who
        // deleted it if the FK insert fails against an already-gone row.
        PlatformAdminActivityLog::record('tenant.delete', $tenant, [
            'company_name' => $tenant->company_name,
        ]);

        $tenant->delete();

        return redirect()
            ->route('central.tenants.index')
            ->with('status', 'Tenant deleted.');
    }
}
