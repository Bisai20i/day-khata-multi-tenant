<?php

namespace App\Http\Controllers\Central\Tenants;

use App\Enums\TenantStatus;
use App\Http\Controllers\Controller;
use App\Jobs\CreateTenantFirstAdmin;
use App\Mail\TenantSuspensionMail;
use App\Models\PlatformAdminActivityLog;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\User;
use App\Support\PlatformMailer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;
use Stancl\JobPipeline\JobPipeline;
use Stancl\Tenancy\Database\Models\Domain;
use Stancl\Tenancy\Events\TenantCreated;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Jobs\MigrateDatabase;
use Stancl\Tenancy\Jobs\SeedDatabase;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

class TenantController extends Controller
{
    /**
     * Display a listing of the tenants, each with its domain. Searchable by
     * company name, contact email, or domain, and filterable by status;
     * paginated - fine to load everything unpaginated at today's tenant
     * count, but that breaks down as it grows.
     */
    public function index(Request $request): Response
    {
        $search = $request->filled('search') ? trim($request->string('search')->toString()) : null;
        $status = $request->filled('status') ? TenantStatus::tryFrom($request->string('status')->toString()) : null;

        $tenants = Tenant::query()
            ->with('domains')
            ->when($search, function ($query, string $search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('company_name', 'like', "%{$search}%")
                        ->orWhere('contact_email', 'like', "%{$search}%")
                        ->orWhereHas('domains', fn ($query) => $query->where('domain', 'like', "%{$search}%"));
                });
            })
            ->when($status, fn ($query, TenantStatus $status) => $query->where('status', $status))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        $gracePeriodDays = PlatformSetting::current()->default_grace_period_days;

        $tenants->through(fn (Tenant $tenant): array => [
            'id' => $tenant->id,
            'company_name' => $tenant->company_name,
            'domain' => $tenant->domains->pluck('domain')->join(', '),
            'status' => $tenant->status->value,
            'created_at' => $tenant->created_at?->toDateString(),
            'past_grace_period' => $tenant->isPastGracePeriod($gracePeriodDays),
            'trial_expired' => $tenant->isTrialExpired(),
        ]);

        return Inertia::render('Central/Tenants/Index', [
            'tenants' => $tenants,
            'filters' => [
                'search' => $search,
                'status' => $status?->value,
            ],
            'statusOptions' => collect(TenantStatus::cases())
                ->map(fn (TenantStatus $status): array => ['value' => $status->value, 'label' => ucfirst($status->value)])
                ->values(),
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
                'trial_ends_at' => now()->addDays(PlatformSetting::current()->default_trial_days),
            ]);

            // Not a real column (see Tenant::getCustomColumns()) — swept into
            // the `data` JSON column and read back by App\Jobs\CreateTenantFirstAdmin
            // once the tenant database exists. Hashed here, never stored in
            // plaintext even transiently. `domain` is stashed here (rather
            // than CreateTenantFirstAdmin querying $tenant->domains()->first())
            // because $tenant->save() below fires the TenantCreated pipeline
            // synchronously on the `sync` queue connection (tests) — that
            // pipeline, including CreateTenantFirstAdmin, runs and completes
            // BEFORE the domains()->create() call further down even executes,
            // so a domains() lookup from inside the job would always find
            // nothing at that point.
            $tenant->pending_admin = [
                'name' => $validated['admin_name'],
                'email' => $validated['admin_email'],
                'password' => Hash::make($validated['admin_password']),
                'domain' => "{$validated['subdomain']}.localhost",
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
        $gracePeriodDays = PlatformSetting::current()->default_grace_period_days;

        // Tie-broken by id, not just created_at: platform_admin_activity_logs.
        // created_at has no fractional-second precision, so two entries
        // recorded within the same second (e.g. a fast retry-then-fail loop)
        // would otherwise sort arbitrarily against each other.
        $lastFailure = $tenant->status === TenantStatus::Provisioning
            ? PlatformAdminActivityLog::where('tenant_id', $tenant->id)
                ->where('action', 'provisioning.failed')
                ->latest('created_at')
                ->latest('id')
                ->first()
            : null;

        return Inertia::render('Central/Tenants/Show', [
            'tenant' => [
                'id' => $tenant->id,
                'company_name' => $tenant->company_name,
                'status' => $tenant->status->value,
                'domains' => $tenant->domains->map(fn (Domain $domain): array => [
                    'id' => $domain->id,
                    'domain' => $domain->domain,
                ]),
                'domain' => $tenant->domains->pluck('domain')->join(', '),
                'contact_email' => $tenant->contact_email,
                'created_at' => $tenant->created_at?->toDateString(),
                'suspended_at' => $tenant->suspended_at?->toDateString(),
                'past_grace_period' => $tenant->isPastGracePeriod($gracePeriodDays),
                'trial_ends_at' => $tenant->trial_ends_at?->toDateString(),
                'trial_expired' => $tenant->isTrialExpired(),
                'provisioning_error' => $lastFailure !== null ? ($lastFailure->metadata['error'] ?? null) : null,
                // Independent of `status` - a tenant can be flagged Active
                // with no database behind it if an earlier provisioning run
                // was interrupted before this check existed (see
                // Tenant::databaseExists()). Not computed while genuinely
                // still Provisioning - that state already has its own
                // error banner/retry flow, and a database not existing yet
                // is completely expected mid-pipeline, not a fault.
                'database_missing' => $tenant->status !== TenantStatus::Provisioning && ! $tenant->databaseExists(),
            ],
        ]);
    }

    /**
     * Show the form for editing a tenant's company name and contact email.
     * Domain, status, and trial expiry are managed through their own
     * dedicated actions, not this form.
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
        $tenant->update(['status' => TenantStatus::Suspended, 'suspended_at' => now()]);

        if ($tenant->contact_email !== null) {
            // Best-effort: a mail failure (e.g. misconfigured SMTP settings)
            // must never block the suspension itself.
            try {
                PlatformMailer::apply();

                Mail::to($tenant->contact_email)->send(new TenantSuspensionMail(
                    companyName: $tenant->company_name,
                    supportEmail: PlatformSetting::current()->support_email,
                ));
            } catch (Throwable) {
                // Intentionally swallowed - see comment above.
            }
        }

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
        $tenant->update(['status' => TenantStatus::Active, 'suspended_at' => null]);

        PlatformAdminActivityLog::record('tenant.resume', $tenant);

        return redirect()
            ->route('central.tenants.show', $tenant)
            ->with('status', 'Tenant resumed.');
    }

    /**
     * Re-run the CreateDatabase/MigrateDatabase/SeedDatabase/
     * CreateTenantFirstAdmin pipeline for a tenant stuck in Provisioning
     * after a failed run (see App\Listeners\RecordProvisioningFailure), OR
     * for a tenant whose `status` says Active/Suspended but whose database
     * genuinely doesn't exist (see Tenant::databaseExists()) - the same
     * broken state, just with a stale status flag from before that check
     * existed. Safe to re-run in both cases: pending_admin is only ever
     * cleared once CreateTenantFirstAdmin actually succeeds, so it's still
     * present for a retry to pick up regardless of which pipeline step
     * failed or how long ago. Refuses on a tenant whose database genuinely
     * already exists, since CreateDatabase would truncate a real, working
     * database back to empty.
     *
     * Still-Provisioning tenants keep firing the TenantCreated event, whose
     * listener is registered ->shouldBeQueued(true) (see
     * TenancyServiceProvider) - fine for that case, since it's the same
     * fire-and-forget path a fresh signup already goes through. But for an
     * Active/Suspended tenant with a missing database, an admin has already
     * landed on this page specifically to fix a broken tenant right now;
     * silently re-queuing the same pipeline is indistinguishable from doing
     * nothing unless a queue worker happens to be running to drain it (this
     * was confirmed live: jobs from earlier provisioning attempts were
     * sitting unprocessed in the `jobs` table). So that case runs the
     * pipeline inline instead, and reports a real failure immediately rather
     * than leaving the admin to guess.
     */
    public function retryProvisioning(Tenant $tenant): RedirectResponse
    {
        $eligible = $tenant->status === TenantStatus::Provisioning || ! $tenant->databaseExists();

        if (! $eligible) {
            return redirect()
                ->route('central.tenants.show', $tenant)
                ->with('status', 'This tenant already has a database - nothing to retry.');
        }

        if ($tenant->status === TenantStatus::Provisioning) {
            event(new TenantCreated($tenant));
        } else {
            try {
                dispatch_sync(
                    JobPipeline::make([
                        CreateDatabase::class,
                        MigrateDatabase::class,
                        SeedDatabase::class,
                        CreateTenantFirstAdmin::class,
                    ])->send(fn (): Tenant => $tenant)->executable([$tenant])
                );
            } catch (Throwable $e) {
                PlatformAdminActivityLog::record('provisioning.failed', $tenant, ['error' => $e->getMessage()]);

                return redirect()
                    ->route('central.tenants.show', $tenant)
                    ->with('status', "Provisioning failed: {$e->getMessage()}");
            }
        }

        PlatformAdminActivityLog::record('tenant.retry_provisioning', $tenant);

        return redirect()
            ->route('central.tenants.show', $tenant)
            ->with('status', 'Provisioning retried.');
    }

    /**
     * Manual escape hatch for a tenant stuck at Provisioning that an admin
     * has independently confirmed is actually fine (database/first admin
     * user genuinely exist) - just flips the status flag, nothing else. Not
     * a substitute for retryProvisioning(): using this on a tenant whose
     * database or admin user were never actually created leaves an Active
     * tenant nobody can log into, which is why it's gated platform-owner
     * (see routes/central-tenants.php) rather than available to support.
     */
    public function forceActive(Tenant $tenant): RedirectResponse
    {
        if ($tenant->status !== TenantStatus::Provisioning) {
            return redirect()
                ->route('central.tenants.show', $tenant)
                ->with('status', 'Only a still-provisioning tenant can be forced active.');
        }

        $tenant->update(['status' => TenantStatus::Active]);

        PlatformAdminActivityLog::record('tenant.force_active', $tenant);

        return redirect()
            ->route('central.tenants.show', $tenant)
            ->with('status', 'Tenant marked active.');
    }

    /**
     * Update a tenant's trial expiry. Deliberately its own action/route
     * (gated platform-owner, see routes/central-tenants.php) rather than a
     * field bundled into update() - company_name/contact_email edits are
     * available to any platform admin, but the user asked specifically for
     * trial-expiry management to be owner-only, and splitting the action was
     * the only way to give that its own gate without also restricting the
     * unrelated fields support already edits today.
     */
    public function updateTrial(Request $request, Tenant $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'trial_ends_at' => ['nullable', 'date'],
        ]);

        $tenant->update(['trial_ends_at' => $validated['trial_ends_at']]);

        PlatformAdminActivityLog::record('tenant.update_trial', $tenant, [
            'trial_ends_at' => $validated['trial_ends_at'],
        ]);

        return redirect()
            ->route('central.tenants.show', $tenant)
            ->with('status', 'Trial expiry updated.');
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

        // Checked explicitly (rather than catching the exception the
        // underlying connection attempt would throw) since
        // DatabaseTenancyBootstrapper only actually throws
        // TenantDatabaseDoesNotExistException in local environments - this
        // check is what makes the same guard work in production too, where
        // a missing SQLite file would otherwise be silently auto-created
        // empty by PDO and fail confusingly later instead.
        if (! $tenant->databaseExists()) {
            return redirect()
                ->route('central.tenants.show', $tenant)
                ->with('status', "This tenant's database doesn't exist yet - use \"Re-provision database\" first.");
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
