<?php

namespace App\Http\Controllers\Central\Tenants;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

class TenantUserController extends Controller
{
    /**
     * Display a read-only list of the users that exist inside a tenant's own
     * database, without impersonating any of them. Runs the query against
     * the tenant's own connection via Tenant::run() (see
     * TenantController::impersonate() for the same pattern), which switches
     * the database connection for the duration of the closure and switches
     * it back once it returns.
     */
    public function index(Tenant $tenant): Response
    {
        $users = $tenant->run(fn () => User::with('role')->orderBy('name')->get());

        return Inertia::render('Central/Tenants/Users', [
            'tenant' => [
                'id' => $tenant->id,
                'company_name' => $tenant->company_name,
            ],
            'users' => $users->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role?->name,
                'is_active' => $user->is_active,
                'created_at' => $user->created_at?->toDateString(),
            ]),
        ]);
    }
}
