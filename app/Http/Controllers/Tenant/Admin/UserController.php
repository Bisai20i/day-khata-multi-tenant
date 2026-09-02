<?php

namespace App\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Tenant/Admin/Users', [
            'users' => User::query()->with('role:id,name,slug')->orderBy('name')->get(),
            'roles' => Role::query()->orderBy('name')->get(['id', 'name', 'slug']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'role_id' => ['required', 'exists:roles,id'],
        ]);

        User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'role_id' => $data['role_id'],
            'is_active' => true,
        ]);

        return redirect()->route('tenant.admin.users')->with('status', 'Employee added.');
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user)],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
            'role_id' => ['required', 'exists:roles,id'],
            'is_active' => ['required', 'boolean'],
        ]);

        $this->guardLastActiveAdmin($user, (int) $data['role_id'], (bool) $data['is_active']);

        $user->name = $data['name'];
        $user->email = $data['email'];
        $user->role_id = $data['role_id'];
        $user->is_active = $data['is_active'];

        if (! empty($data['password'])) {
            $user->password = $data['password'];
        }

        $user->save();

        return redirect()->route('tenant.admin.users')->with('status', 'Employee updated.');
    }

    /**
     * There is no destroy() here on purpose: every created_by FK
     * (journal_vouchers, sales, purchases, ...) is restrictOnDelete(), so a
     * user who has ever posted anything can never be hard-deleted anyway.
     * Deactivation (is_active=false via update()) is the only lifecycle
     * action offered.
     *
     * This tenant has no platform-admin impersonation and no password-reset
     * flow, so demoting or deactivating the last active admin would leave it
     * permanently locked out of its own admin tooling - block it outright.
     */
    private function guardLastActiveAdmin(User $user, int $newRoleId, bool $newIsActive): void
    {
        $wasActiveAdmin = $user->is_active && $user->role?->slug === 'admin';

        if (! $wasActiveAdmin) {
            return;
        }

        $newRole = Role::find($newRoleId);
        $staysActiveAdmin = $newIsActive && $newRole?->slug === 'admin';

        if ($staysActiveAdmin) {
            return;
        }

        $otherActiveAdmins = User::query()
            ->whereKeyNot($user->id)
            ->where('is_active', true)
            ->whereHas('role', fn ($query) => $query->where('slug', 'admin'))
            ->exists();

        if (! $otherActiveAdmins) {
            throw ValidationException::withMessages([
                'role_id' => 'This is the last active admin - deactivate or reassign someone else to admin first.',
            ]);
        }
    }
}
