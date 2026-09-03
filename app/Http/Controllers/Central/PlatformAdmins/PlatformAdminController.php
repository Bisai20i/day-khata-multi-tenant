<?php

namespace App\Http\Controllers\Central\PlatformAdmins;

use App\Enums\PlatformAdminRole;
use App\Http\Controllers\Controller;
use App\Models\PlatformAdmin;
use App\Models\PlatformAdminActivityLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PlatformAdminController extends Controller
{
    /**
     * Viewable by any platform admin (owner or support); create/store/edit/
     * update are owner-only (see routes/central-platform-admins.php).
     */
    public function index(): Response
    {
        return Inertia::render('Central/PlatformAdmins/Index', [
            'admins' => PlatformAdmin::query()->orderBy('name')->get(['id', 'name', 'email', 'role', 'is_active']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Central/PlatformAdmins/Create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('platform_admins')],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'role' => ['required', Rule::enum(PlatformAdminRole::class)],
        ]);

        $admin = PlatformAdmin::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'role' => $data['role'],
            'is_active' => true,
        ]);

        PlatformAdminActivityLog::record('platform_admin.create', metadata: [
            'platform_admin_id' => $admin->id,
            'email' => $admin->email,
            'role' => $admin->role->value,
        ]);

        return redirect()->route('central.platform-admins.index')->with('status', 'Platform admin added.');
    }

    public function edit(PlatformAdmin $platformAdmin): Response
    {
        return Inertia::render('Central/PlatformAdmins/Edit', [
            'admin' => $platformAdmin->only(['id', 'name', 'email', 'role', 'is_active']),
        ]);
    }

    public function update(Request $request, PlatformAdmin $platformAdmin): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('platform_admins')->ignore($platformAdmin)],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
            'role' => ['required', Rule::enum(PlatformAdminRole::class)],
            'is_active' => ['required', 'boolean'],
        ]);

        $newRole = PlatformAdminRole::from($data['role']);

        $this->guardLastActiveOwner($platformAdmin, $newRole, (bool) $data['is_active']);

        $platformAdmin->name = $data['name'];
        $platformAdmin->email = $data['email'];
        $platformAdmin->role = $newRole;
        $platformAdmin->is_active = $data['is_active'];

        if (! empty($data['password'])) {
            $platformAdmin->password = $data['password'];
        }

        $changed = $platformAdmin->getDirty();
        $platformAdmin->save();

        if ($changed !== []) {
            PlatformAdminActivityLog::record('platform_admin.update', metadata: [
                'platform_admin_id' => $platformAdmin->id,
                'changed' => array_keys($changed),
            ]);
        }

        return redirect()->route('central.platform-admins.index')->with('status', 'Platform admin updated.');
    }

    /**
     * There is no destroy() here on purpose: platform_admin_activity_logs.
     * platform_admin_id is restrictOnDelete(), so an admin who has ever
     * taken a logged action can never be hard-deleted anyway. Deactivation
     * (is_active=false via update()) is the only lifecycle action offered -
     * same reasoning as Tenant\Admin\UserController on the tenant side.
     *
     * A deactivated or demoted-to-support owner who was the last active
     * owner would leave nobody able to manage platform admins, change
     * settings, or delete a tenant - block it outright.
     */
    private function guardLastActiveOwner(PlatformAdmin $admin, PlatformAdminRole $newRole, bool $newIsActive): void
    {
        $wasActiveOwner = $admin->is_active && $admin->isOwner();

        if (! $wasActiveOwner) {
            return;
        }

        $staysActiveOwner = $newIsActive && $newRole === PlatformAdminRole::Owner;

        if ($staysActiveOwner) {
            return;
        }

        $otherActiveOwners = PlatformAdmin::query()
            ->whereKeyNot($admin->id)
            ->where('is_active', true)
            ->where('role', PlatformAdminRole::Owner)
            ->exists();

        if (! $otherActiveOwners) {
            throw ValidationException::withMessages([
                'role' => 'This is the last active owner - deactivate or reassign someone else to owner first.',
            ]);
        }
    }
}
