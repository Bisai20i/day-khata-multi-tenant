<?php

namespace App\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\Admin\StoreRoleRequest;
use App\Http\Requests\Tenant\Admin\UpdateRoleRequest;
use App\Models\ActivityLog;
use App\Models\Role;
use App\Support\Permissions\PermissionCatalog;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Owner-only role editor (plans/roles-permissions-entitlements.md 3.3, 5).
 * Every route is behind can:roles.manage, an owner-only key, so a role that
 * somehow carries roles.manage in its JSON still gets 403.
 *
 * Entitlements shape everything here: the editor only offers keys from the
 * modules this company is entitled to, validation rejects anything else
 * (StoreRoleRequest), and on update the grants a role holds in modules that
 * are currently switched off are kept as they are ("dormant"), so turning a
 * module back on in the central panel restores them.
 *
 * Role is not attached to ActivityLogObserver (AppServiceProvider is not
 * ours to edit, and the generic observer would log the JSON as an opaque
 * string pair), so create/update/delete write their own rows to the same
 * tenant activity_logs table, the way ClosedFiscalYearGuard::logCorrection()
 * does.
 */
class RoleController extends Controller
{
    public function index(): Response
    {
        $activeKeys = array_flip($this->activeKeys());

        $roles = Role::query()
            ->withCount('users')
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get()
            ->map(fn (Role $role): array => [
                'id' => $role->id,
                'name' => $role->name,
                'slug' => $role->slug,
                'is_system' => (bool) $role->is_system,
                'users_count' => (int) $role->users_count,
                'permissions_count' => count(array_filter(
                    $this->storedKeys($role),
                    fn (string $key): bool => isset($activeKeys[$key]),
                )),
            ])
            ->values();

        return Inertia::render('Tenant/Admin/Roles/Index', [
            'roles' => $roles,
            'activeKeyCount' => count($activeKeys),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Tenant/Admin/Roles/Edit', $this->editorProps(null));
    }

    public function store(StoreRoleRequest $request): RedirectResponse
    {
        $name = (string) $request->validated('name');
        $permissions = $request->permissionKeys();

        $role = DB::transaction(function () use ($name, $permissions): Role {
            $role = new Role;
            $role->forceFill([
                'name' => $name,
                'slug' => $this->uniqueSlug($name),
                'permissions' => $permissions,
                'is_system' => false,
            ])->save();

            $this->log($role, 'created', ['permissions' => ['after' => $permissions]]);

            return $role;
        });

        return redirect()->route('tenant.admin.roles.edit', $role)->with('status', 'Role created.');
    }

    public function edit(Role $role): Response
    {
        return Inertia::render('Tenant/Admin/Roles/Edit', $this->editorProps($role));
    }

    /**
     * Replaces the role's entitled grants with the submitted list and keeps
     * its dormant grants, inside a transaction that re-reads the row under
     * lockForUpdate and compares updated_at with what the form loaded. A
     * second owner session that saved in between makes this one stale:
     * refused, never merged, because the owner decided against a list they
     * can no longer see.
     *
     * The stale error is a ValidationException with status 409: a JSON
     * client gets a real 409, an Inertia visit gets the framework's normal
     * redirect back with errors.updated_at (an Inertia client treats a raw
     * 409 as its own "hard reload to X-Inertia-Location" signal, so sending
     * one to it would break the page instead of showing the message).
     */
    public function update(UpdateRoleRequest $request, Role $role): RedirectResponse
    {
        $name = (string) $request->validated('name');
        $submitted = $request->permissionKeys();
        $loadedAt = $request->validated('updated_at');
        $entitledModules = tenant()->entitledModules();

        DB::transaction(function () use ($role, $name, $submitted, $loadedAt, $entitledModules): void {
            /** @var Role $locked */
            $locked = Role::query()->lockForUpdate()->findOrFail($role->getKey());

            if ($this->isStale($locked->updated_at, $loadedAt)) {
                throw ValidationException::withMessages([
                    'updated_at' => 'Someone else saved this role after you opened it. Reload the page to see their changes, then make yours again.',
                ])->status(409);
            }

            $before = $this->storedKeys($locked);
            $dormant = array_values(array_filter(
                $before,
                fn (string $key): bool => $this->isDormant($key, $entitledModules),
            ));
            $after = $this->inCatalogOrder([...$dormant, ...$submitted]);

            $locked->name = $name;
            $locked->permissions = $after;

            if (! $locked->isDirty(['name', 'permissions'])) {
                return;
            }

            $this->bumpUpdatedAt($locked);
            $oldName = $locked->getOriginal('name');
            $locked->save();

            $changes = [];
            if ($oldName !== $name) {
                $changes['name'] = ['from' => $oldName, 'to' => $name];
            }
            $added = array_values(array_diff($after, $before));
            $removed = array_values(array_diff($before, $after));
            if ($added !== [] || $removed !== []) {
                $changes['permissions'] = ['added' => $added, 'removed' => $removed, 'before' => $before, 'after' => $after];
            }

            // A save that only normalised the stored key order is not worth
            // an audit row.
            if ($changes !== []) {
                $this->log($locked, 'updated', $changes);
            }
        });

        // The acting user is the owner (roles.manage), whose permissions do
        // not come from a role, but drop the memo anyway so nothing later in
        // this request can read a grant set computed before the save.
        $request->user()?->flushEffectivePermissions();

        return redirect()->route('tenant.admin.roles.edit', $role)->with('status', 'Role saved.');
    }

    /**
     * A new, non-system role named "<name> (copy)" (or "(copy 2)", ...) with
     * exactly the source role's stored permissions, dormant grants included,
     * so the copy behaves the same once those modules come back.
     */
    public function duplicate(Role $role): RedirectResponse
    {
        $copy = DB::transaction(function () use ($role): Role {
            $permissions = $this->storedKeys($role);
            $name = $this->uniqueCopyName($role->name);

            $copy = new Role;
            $copy->forceFill([
                'name' => $name,
                'slug' => $this->uniqueSlug($name),
                'permissions' => $permissions,
                'is_system' => false,
            ])->save();

            $this->log($copy, 'created', [
                'duplicated_from' => ['id' => $role->id, 'name' => $role->name],
                'permissions' => ['after' => $permissions],
            ]);

            return $copy;
        });

        return redirect()->route('tenant.admin.roles.edit', $copy)->with('status', "Copied \"{$role->name}\". Rename it and adjust its permissions.");
    }

    /**
     * Refused for system roles and while any user (active or not) holds the
     * role: users.role_id is nullOnDelete, so deleting an assigned role would
     * silently strip those employees of every permission.
     *
     * The role row is locked first. On MySQL/InnoDB a concurrent user save
     * that points role_id at this role needs a shared lock on the same row
     * for its foreign-key check, so it either committed before our lock (and
     * the users check below sees it) or waits and then fails its FK check
     * against the deleted row; it can never land in between.
     */
    public function destroy(Request $request, Role $role): RedirectResponse
    {
        DB::transaction(function () use ($role): void {
            /** @var Role $locked */
            $locked = Role::query()->lockForUpdate()->findOrFail($role->getKey());

            if ($locked->is_system) {
                throw ValidationException::withMessages([
                    'role' => "\"{$locked->name}\" is a built-in role and cannot be deleted.",
                ]);
            }

            $holders = $locked->users()->count();
            if ($holders > 0) {
                throw ValidationException::withMessages([
                    'role' => "\"{$locked->name}\" is assigned to {$holders} ".Str::plural('user', $holders).'. Move them to another role first.',
                ]);
            }

            $this->log($locked, 'deleted', [
                'name' => $locked->name,
                'permissions' => ['before' => $this->storedKeys($locked)],
            ]);

            $locked->delete();
        });

        $request->user()?->flushEffectivePermissions();

        return redirect()->route('tenant.admin.roles.index')->with('status', 'Role deleted.');
    }

    /**
     * Props shared by the create and edit screens. `granted` is only the
     * role's keys the editor can show (entitled, not owner-only); the rest
     * stay on the server and are only counted, so the page can say they are
     * being kept.
     *
     * @return array<string, mixed>
     */
    private function editorProps(?Role $role): array
    {
        $activeKeys = array_flip($this->activeKeys());
        $stored = $role ? $this->storedKeys($role) : [];
        $entitledModules = tenant()->entitledModules();

        return [
            'role' => $role ? [
                'id' => $role->id,
                'name' => $role->name,
                'slug' => $role->slug,
                'is_system' => (bool) $role->is_system,
                'updated_at' => $role->updated_at?->toJSON(),
                'users_count' => $role->users()->count(),
            ] : null,
            'granted' => array_values(array_filter($stored, fn (string $key): bool => isset($activeKeys[$key]))),
            'dormantCount' => count(array_filter($stored, fn (string $key): bool => $this->isDormant($key, $entitledModules))),
            'modules' => $this->entitledTree(),
        ];
    }

    /**
     * PermissionCatalog::grouped() narrowed to the entitled modules, minus
     * owner-only keys (never grantable, so never shown), minus any group or
     * module that ends up empty.
     *
     * @return list<array{module: string, label: string, groups: list<array{group: string, permissions: list<array{key: string, label: string}>}>}>
     */
    private function entitledTree(): array
    {
        $entitled = array_flip(tenant()->entitledModules());
        $tree = [];

        foreach (PermissionCatalog::grouped() as $module) {
            if (! isset($entitled[$module['module']])) {
                continue;
            }

            $groups = [];
            foreach ($module['groups'] as $group) {
                $permissions = [];
                foreach ($group['permissions'] as $permission) {
                    if (! $permission['owner_only']) {
                        $permissions[] = ['key' => $permission['key'], 'label' => $permission['label']];
                    }
                }
                if ($permissions !== []) {
                    $groups[] = ['group' => $group['group'], 'permissions' => $permissions];
                }
            }

            if ($groups !== []) {
                $tree[] = ['module' => $module['module'], 'label' => $module['label'], 'groups' => $groups];
            }
        }

        return $tree;
    }

    /**
     * Every key the editor offers for this tenant right now.
     *
     * @return list<string>
     */
    private function activeKeys(): array
    {
        return array_values(array_filter(
            PermissionCatalog::keysForModules(tenant()->entitledModules()),
            fn (string $key): bool => ! PermissionCatalog::isOwnerOnly($key),
        ));
    }

    /**
     * A grant kept across saves although the editor does not show it: a real,
     * role-grantable key whose module this tenant is not entitled to today.
     * Unknown (removed) keys and owner-only keys are not dormant; they could
     * never take effect, so a save drops them.
     *
     * @param  list<string>  $entitledModules
     */
    private function isDormant(string $key, array $entitledModules): bool
    {
        return PermissionCatalog::has($key)
            && ! PermissionCatalog::isOwnerOnly($key)
            && ! in_array(PermissionCatalog::moduleOf($key), $entitledModules, true);
    }

    /**
     * The role's JSON as a list of strings, tolerating null (a role not yet
     * backfilled) and junk entries.
     *
     * @return list<string>
     */
    private function storedKeys(Role $role): array
    {
        $stored = is_array($role->permissions) ? $role->permissions : [];

        return array_values(array_unique(array_filter($stored, 'is_string')));
    }

    /**
     * @param  list<string>  $keys
     * @return list<string>
     */
    private function inCatalogOrder(array $keys): array
    {
        $wanted = array_flip($keys);

        return array_values(array_filter(
            array_keys(PermissionCatalog::permissions()),
            fn (string $key): bool => isset($wanted[$key]),
        ));
    }

    /**
     * Compares instants, not strings, so the timezone and fraction format of
     * the serialized value do not matter. A role without a timestamp matches
     * only a form that also loaded none.
     */
    private function isStale(?CarbonInterface $current, ?string $loadedAt): bool
    {
        if ($current === null || $loadedAt === null) {
            return $current !== null || $loadedAt !== null;
        }

        return ! $current->equalTo(Carbon::parse($loadedAt));
    }

    /**
     * updated_at is stored to the second, so two saves inside one second
     * would leave it unchanged and the second editor's stale check would
     * pass. Always move it strictly forward instead.
     */
    private function bumpUpdatedAt(Role $role): void
    {
        $previous = $role->getOriginal('updated_at');
        $next = Carbon::now()->startOfSecond();

        if ($previous instanceof CarbonInterface && $next->lessThanOrEqualTo($previous)) {
            $next = $previous->copy()->startOfSecond()->addSecond();
        }

        $role->updated_at = $next;
    }

    /**
     * A slug derived from the name, suffixed -2, -3, ... until unused. Never
     * user-supplied: slugs are what code (and the provisioning templates)
     * match on.
     */
    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'role';
        $base = Str::limit($base, 80, '');
        $slug = $base;

        for ($suffix = 2; Role::query()->where('slug', $slug)->exists(); $suffix++) {
            $slug = "{$base}-{$suffix}";
        }

        return $slug;
    }

    private function uniqueCopyName(string $name): string
    {
        $base = Str::limit($name, 85, '');
        $candidate = "{$base} (copy)";

        for ($suffix = 2; Role::query()->where('name', $candidate)->exists(); $suffix++) {
            $candidate = "{$base} (copy {$suffix})";
        }

        return $candidate;
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function log(Role $role, string $action, array $changes): void
    {
        ActivityLog::create([
            // Explicit 'web' guard, matching ActivityLogObserver::write().
            'user_id' => Auth::guard('web')->id(),
            'action' => $action,
            'subject_type' => $role->getMorphClass(),
            'subject_id' => $role->getKey(),
            'description' => Str::limit("Role #{$role->getKey()} \"{$role->name}\" {$action}", 250),
            'changes' => $changes === [] ? null : $changes,
        ]);
    }
}
