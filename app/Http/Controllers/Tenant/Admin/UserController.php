<?php

namespace App\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\Admin\TransferOwnershipRequest;
use App\Models\Role;
use App\Models\User;
use App\Support\Permissions\OwnershipTransfer;
use App\Support\Permissions\OwnershipTransferException;
use App\Support\Permissions\PermissionCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The users page (plans/roles-permissions-entitlements.md section 5). The
 * routes are behind can:users.manage, which a role may carry, so this
 * controller is where the escalation guard lives: holding users.manage lets
 * someone manage the people BELOW them, never hand out, or take away from
 * others, more than they hold themselves.
 *
 * The rules:
 * - The owner can do anything to anyone except to the owner row itself:
 *   nobody, the owner included, can deactivate the owner or change the
 *   owner's role here. Ownership only moves through transferOwnership().
 * - Anyone else ("a non-owner") may assign only roles within their reach
 *   (see reachOf()), cannot edit the owner at all, cannot change their own
 *   role or deactivate themselves, and cannot edit a user whose CURRENT role
 *   is outside their reach (otherwise they could demote or lock out a peer
 *   above them).
 * - No role (role_id null) grants nothing, so it is always within reach.
 *
 * Status codes, consistently: 403 when the target USER is off limits (the
 * owner, a peer above the actor), because no payload could make the request
 * acceptable; 422 (a validation error on role_id or is_active) when the
 * submitted VALUES are the problem (a role that is too broad, a change to
 * the owner's or one's own role or status), because a different value would
 * be accepted.
 *
 * is_owner is never read from the request: it is not fillable and not
 * validated here, and only OwnershipTransfer writes it.
 *
 * There is no destroy() on purpose: every created_by FK (journal_vouchers,
 * sales, purchases, ...) is restrictOnDelete(), so a user who has ever
 * posted anything can never be hard-deleted anyway. Deactivation
 * (is_active=false via update()) is the only lifecycle action offered.
 */
class UserController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $actor */
        $actor = $request->user();
        $reach = $this->reachOf($actor);

        $users = User::query()
            ->with('role:id,name,slug,permissions')
            ->orderBy('name')
            ->get()
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role_id' => $user->role_id,
                'role' => $user->role ? ['id' => $user->role->id, 'name' => $user->role->name, 'slug' => $user->role->slug] : null,
                'is_active' => (bool) $user->is_active,
                'is_owner' => $user->isOwner(),
                'editable' => $this->mayEdit($actor, $user, $reach),
            ])
            ->values();

        $roles = Role::query()
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'permissions'])
            ->filter(fn (Role $role): bool => $this->withinReach($role, $reach))
            ->map(fn (Role $role): array => ['id' => $role->id, 'name' => $role->name, 'slug' => $role->slug])
            ->values();

        return Inertia::render('Tenant/Admin/Users', [
            'users' => $users,
            'roles' => $roles,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'role_id' => ['required', 'integer', 'exists:roles,id'],
        ]);

        /** @var User $actor */
        $actor = $request->user();

        DB::transaction(function () use ($actor, $data): void {
            $actorNow = $this->freshActor($actor);
            $role = Role::query()->find($data['role_id']);

            if (! $this->withinReach($role, $this->reachOf($actorNow))) {
                $this->rejectRole($role);
            }

            User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'role_id' => $data['role_id'],
                'is_active' => true,
            ]);
        });

        return redirect()->route('tenant.admin.users')->with('status', 'Employee added.');
    }

    /**
     * The target row is re-read under lockForUpdate and every rule is checked
     * against that locked row, so an ownership transfer committed between
     * page load and save (OwnershipTransfer locks the same rows) cannot be
     * overwritten: a user who just became owner is then treated as the owner.
     */
    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user)],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
            // present + nullable: the owner may have no role, and saving their
            // row must be able to say "unchanged", but a client that forgot
            // the field must not be read as "remove the role".
            'role_id' => ['present', 'nullable', 'integer', 'exists:roles,id'],
            'is_active' => ['required', 'boolean'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $newRoleId = $data['role_id'] === null ? null : (int) $data['role_id'];
        $newIsActive = (bool) $data['is_active'];

        DB::transaction(function () use ($actor, $user, $data, $newRoleId, $newIsActive): void {
            /** @var User $target */
            $target = User::query()->lockForUpdate()->findOrFail($user->getKey());
            $actorNow = $this->isSameUser($actor, $target) ? $target : $this->freshActor($actor);

            $this->guardUpdate($actorNow, $target, $newRoleId, $newIsActive);

            $target->name = $data['name'];
            $target->email = $data['email'];
            $target->role_id = $newRoleId;
            $target->is_active = $newIsActive;

            if (! empty($data['password'])) {
                $target->password = $data['password'];
            }

            $target->save();
        });

        // The actor may have edited their own row through the locked copy.
        $actor->flushEffectivePermissions();

        return redirect()->route('tenant.admin.users')->with('status', 'Employee updated.');
    }

    /**
     * Route gate: can:ownership.transfer, an owner-only key, so only the
     * owner reaches this (a role carrying the key in its JSON still gets 403).
     * The isOwner() check below repeats that on purpose; OwnershipTransfer
     * checks it a third time against the locked row.
     *
     * Afterwards the old owner is governed by their own role, which may not
     * include users.manage, so they are sent to the dashboard in that case
     * instead of to a page that would now answer 403.
     */
    public function transferOwnership(TransferOwnershipRequest $request, User $user): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        if (! $actor->isOwner()) {
            abort(403, 'Only the owner can transfer ownership.');
        }

        try {
            OwnershipTransfer::run($actor, $user);
        } catch (OwnershipTransferException $exception) {
            throw ValidationException::withMessages(['transfer' => $exception->getMessage()]);
        }

        $destination = $actor->hasPermission('users.manage') ? 'tenant.admin.users' : 'tenant.dashboard';

        return redirect()->route($destination)->with('status', "{$user->name} is now the owner of this company.");
    }

    /**
     * Every rule from the class docblock, checked against the locked target
     * row and a fresh copy of the actor.
     */
    private function guardUpdate(User $actor, User $target, ?int $newRoleId, bool $newIsActive): void
    {
        $currentRoleId = $target->role_id === null ? null : (int) $target->role_id;
        $changesRole = $newRoleId !== $currentRoleId;

        if ($target->isOwner()) {
            if (! $actor->isOwner()) {
                abort(403, "Only the owner can edit the owner's account.");
            }

            if ($changesRole) {
                throw ValidationException::withMessages([
                    'role_id' => "The owner's role cannot be changed. Transfer ownership to someone else first.",
                ]);
            }

            if (! $newIsActive) {
                throw ValidationException::withMessages([
                    'is_active' => 'The owner cannot be deactivated. Transfer ownership to someone else first.',
                ]);
            }

            return;
        }

        if ($actor->isOwner()) {
            return;
        }

        if ($this->isSameUser($actor, $target)) {
            if ($changesRole) {
                throw ValidationException::withMessages(['role_id' => 'You cannot change your own role.']);
            }

            if (! $newIsActive) {
                throw ValidationException::withMessages(['is_active' => 'You cannot deactivate your own account.']);
            }

            return;
        }

        $reach = $this->reachOf($actor);

        if (! $this->withinReach($this->roleById($currentRoleId), $reach)) {
            abort(403, "{$target->name}'s role includes permissions you do not have, so you cannot edit them.");
        }

        if ($changesRole) {
            $newRole = $this->roleById($newRoleId);

            if (! $this->withinReach($newRole, $reach)) {
                $this->rejectRole($newRole);
            }
        }
    }

    /**
     * Whether the actor may open the edit form for this user at all (the
     * page hides the edit action otherwise; update() enforces it).
     *
     * @param  array<string, true>|null  $reach
     */
    private function mayEdit(User $actor, User $target, ?array $reach): bool
    {
        if ($actor->isOwner()) {
            return true;
        }

        if ($target->isOwner()) {
            return false;
        }

        return $this->isSameUser($actor, $target) || $this->withinReach($target->role, $reach);
    }

    /**
     * The keys a non-owner may hand out: every key in their role's JSON that
     * could ever grant something through a role. Null means "no limit" (the
     * owner). An inactive actor reaches nothing.
     *
     * Deliberately the role's FULL stored list, not the actor's effective
     * set, and compared against the target role's full list (withinReach):
     * a grant from a module this company is not entitled to today is dormant
     * and grants nothing now, but it WOULD grant the moment the module is
     * switched back on. Comparing full lists means a role is assignable only
     * if it could never grant more than the actor's own role, today or after
     * any entitlement change, so re-enabling a module can never turn an
     * earlier assignment into an escalation. It is also strictly narrower
     * than comparing effective sets: if the target's full list is inside the
     * actor's, its entitled part is inside the actor's entitled part.
     *
     * @return array<string, true>|null
     */
    private function reachOf(User $actor): ?array
    {
        if ($actor->isOwner()) {
            return null;
        }

        if (! $actor->isActive()) {
            return [];
        }

        return $this->roleKeys($this->roleById($actor->role_id === null ? null : (int) $actor->role_id));
    }

    /**
     * Whether assigning this role (or no role) stays within the reach.
     *
     * @param  array<string, true>|null  $reach
     */
    private function withinReach(?Role $role, ?array $reach): bool
    {
        if ($reach === null) {
            return true;
        }

        foreach ($this->roleKeys($role) as $key => $_) {
            if (! isset($reach[$key])) {
                return false;
            }
        }

        return true;
    }

    /**
     * The keys of a role that count for the subset comparison, as a flipped
     * set: every string in its JSON except owner-only keys, which a role can
     * never grant (EffectivePermissions drops them) and so cannot widen
     * anyone. Keys the catalog does not know (any more) are kept: they grant
     * nothing today, but a later catalog could know them again, so they must
     * be matched too (fail closed).
     *
     * @return array<string, true>
     */
    private function roleKeys(?Role $role): array
    {
        $stored = $role !== null && is_array($role->permissions) ? $role->permissions : [];
        $keys = [];

        foreach ($stored as $key) {
            if (is_string($key) && ! PermissionCatalog::isOwnerOnly($key)) {
                $keys[$key] = true;
            }
        }

        return $keys;
    }

    private function roleById(?int $roleId): ?Role
    {
        return $roleId === null ? null : Role::query()->find($roleId);
    }

    /**
     * The acting user as the database has them now, so an ownership transfer
     * or a role change committed since the request started is honoured.
     */
    private function freshActor(User $actor): User
    {
        return User::query()->find($actor->getKey()) ?? $actor;
    }

    private function isSameUser(User $first, User $second): bool
    {
        return (string) $first->getKey() === (string) $second->getKey();
    }

    /**
     * @throws ValidationException always
     */
    private function rejectRole(?Role $role): never
    {
        $name = $role?->name ?? 'That role';

        throw ValidationException::withMessages([
            'role_id' => "\"{$name}\" includes permissions you do not have yourself, so you cannot give it to anyone.",
        ]);
    }
}
