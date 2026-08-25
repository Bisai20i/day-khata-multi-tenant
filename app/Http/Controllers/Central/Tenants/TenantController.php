<?php

namespace App\Http\Controllers\Central\Tenants;

use App\Enums\TenantStatus;
use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;
use Stancl\Tenancy\Database\Models\Domain;
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
     * Provision a new tenant: central tenant + domain records, a fresh
     * tenant database (created/migrated/seeded via the TenantCreated job
     * pipeline), and the tenant's first admin user.
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
            // pipeline (which creates/migrates/seeds the tenant database)
            // throws partway through its own "created" event listeners.
            $tenant = new Tenant([
                'company_name' => $validated['company_name'],
                'status' => TenantStatus::Active,
                'contact_email' => $validated['contact_email'] ?? null,
            ]);
            $tenant->save();

            $tenant->domains()->create([
                'domain' => "{$validated['subdomain']}.localhost",
            ]);

            $tenant->run(function () use ($validated): void {
                $role = Role::where('slug', 'admin')->firstOrFail();

                User::create([
                    'name' => $validated['admin_name'],
                    'email' => $validated['admin_email'],
                    'password' => Hash::make($validated['admin_password']),
                    'role_id' => $role->id,
                ]);
            });

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

        return redirect()
            ->route('central.tenants.show', $tenant)
            ->with('status', 'Tenant provisioned successfully.');
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
     * Suspend a tenant, blocking further access to its subdomain.
     */
    public function suspend(Tenant $tenant): RedirectResponse
    {
        $tenant->update(['status' => TenantStatus::Suspended]);

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

        return redirect()
            ->route('central.tenants.show', $tenant)
            ->with('status', 'Tenant resumed.');
    }

    /**
     * Delete a tenant, cascading to its domains (DB FK) and its database
     * (via the TenantDeleted job pipeline).
     */
    public function destroy(Tenant $tenant): RedirectResponse
    {
        $tenant->delete();

        return redirect()
            ->route('central.tenants.index')
            ->with('status', 'Tenant deleted.');
    }
}
