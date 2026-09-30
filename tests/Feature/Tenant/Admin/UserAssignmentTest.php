<?php

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The users page escalation guard and owner protection (P12, plan section 5):
 * a non-owner holding users.manage manages only people and roles within
 * their own reach, and nobody can deactivate the owner or change the owner's
 * role. Tenancy is ended after every test for the reason given in
 * UserManagementTest.
 *
 * Status codes: 403 when the target user is off limits (the owner, a peer
 * above the actor), 422 (session errors on role_id / is_active) when the
 * submitted value is the problem.
 */
afterEach(function () {
    tenancy()->end();
});

/**
 * A tenant with an owner (owner@example.com) and a manager
 * (manager@example.com) whose role grants $managerKeys.
 *
 * @param  list<string>  $managerKeys
 * @param  list<string>|null  $enabledModules  null = the test default (every module)
 */
function provisionAssignmentTenant(string $domain, array $managerKeys = ['users.manage', 'sales.view', 'sales.create'], ?array $enabledModules = null): Tenant
{
    $attributes = ['company_name' => 'Acme Co'];
    if ($enabledModules !== null) {
        $attributes['enabled_modules'] = $enabledModules;
    }

    $tenant = Tenant::create($attributes);
    $tenant->domains()->create(['domain' => $domain]);

    $tenant->run(function () use ($managerKeys) {
        User::factory()->create(['email' => 'owner@example.com', 'name' => 'Olivia Owner']);
        User::factory()->create([
            'email' => 'manager@example.com',
            'name' => 'Manny Manager',
            'role_id' => roleWithPermissions($managerKeys, 'Manager role')->id,
        ]);
    });

    return $tenant;
}

function signInForAssignment(string $domain, string $email): void
{
    test()->post("http://{$domain}/logout");
    test()->post("http://{$domain}/login", ['email' => $email, 'password' => 'password']);
}

/**
 * @param  list<string>  $keys
 */
function assignmentRole(Tenant $tenant, array $keys, string $name): Role
{
    $role = null;
    $tenant->run(function () use (&$role, $keys, $name) {
        $role = roleWithPermissions($keys, $name);
    });

    return $role;
}

function assignmentUser(Tenant $tenant, string $email, ?Role $role, bool $isActive = true): User
{
    $user = null;
    $tenant->run(function () use (&$user, $email, $role, $isActive) {
        $user = User::factory()->create([
            'email' => $email,
            'role_id' => $role?->id,
            'is_owner' => false,
            'is_active' => $isActive,
        ]);
    });

    return $user;
}

function assignmentFind(Tenant $tenant, string $email): User
{
    $user = null;
    $tenant->run(function () use (&$user, $email) {
        $user = User::query()->where('email', $email)->firstOrFail();
    });

    return $user;
}

/**
 * @return array<string, mixed>
 */
function assignmentPayload(User $user, array $overrides = []): array
{
    return array_merge([
        'name' => $user->name,
        'email' => $user->email,
        'role_id' => $user->role_id,
        'is_active' => (bool) $user->is_active,
    ], $overrides);
}

test('a manager with users.manage can create a user with a narrower role and move an employee to it', function () {
    $domain = 'assign-narrower.tenant-test';
    $tenant = provisionAssignmentTenant($domain);
    $narrower = assignmentRole($tenant, ['sales.view'], 'Viewer');
    $employee = assignmentUser($tenant, 'employee@example.com', assignmentRole($tenant, ['sales.view', 'sales.create'], 'Counter'));
    signInForAssignment($domain, 'manager@example.com');

    $this->post("http://{$domain}/admin/users", [
        'name' => 'New Hire',
        'email' => 'hire@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'role_id' => $narrower->id,
        // Never mass assignable, never read from the request.
        'is_owner' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $this->put("http://{$domain}/admin/users/{$employee->id}", assignmentPayload($employee, ['role_id' => $narrower->id]))
        ->assertRedirect()->assertSessionHasNoErrors();

    $hire = assignmentFind($tenant, 'hire@example.com');
    expect($hire->role_id)->toBe($narrower->id)
        ->and($hire->isOwner())->toBeFalse()
        ->and(assignmentFind($tenant, 'employee@example.com')->role_id)->toBe($narrower->id);

    $tenant->delete();
});

test('a manager can always take a role away (no role grants nothing)', function () {
    $domain = 'assign-null-role.tenant-test';
    $tenant = provisionAssignmentTenant($domain);
    $employee = assignmentUser($tenant, 'employee@example.com', assignmentRole($tenant, ['sales.view'], 'Viewer'));
    signInForAssignment($domain, 'manager@example.com');

    $this->put("http://{$domain}/admin/users/{$employee->id}", assignmentPayload($employee, ['role_id' => null]))
        ->assertRedirect()->assertSessionHasNoErrors();

    expect(assignmentFind($tenant, 'employee@example.com')->role_id)->toBeNull();

    $tenant->delete();
});

test('a manager cannot create a user with a broader role', function () {
    $domain = 'assign-broader-create.tenant-test';
    $tenant = provisionAssignmentTenant($domain);
    $broader = assignmentRole($tenant, ['users.manage', 'sales.view', 'purchases.view'], 'Broader');
    signInForAssignment($domain, 'manager@example.com');

    $this->post("http://{$domain}/admin/users", [
        'name' => 'New Hire',
        'email' => 'hire@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'role_id' => $broader->id,
    ])->assertSessionHasErrors('role_id');

    $tenant->run(function () {
        expect(User::query()->where('email', 'hire@example.com')->exists())->toBeFalse();
    });

    $tenant->delete();
});

test('a manager cannot give an employee a broader role', function () {
    $domain = 'assign-broader-update.tenant-test';
    $tenant = provisionAssignmentTenant($domain);
    $viewer = assignmentRole($tenant, ['sales.view'], 'Viewer');
    $broader = assignmentRole($tenant, ['users.manage', 'sales.view', 'sales.cancel'], 'Broader');
    $employee = assignmentUser($tenant, 'employee@example.com', $viewer);
    signInForAssignment($domain, 'manager@example.com');

    $this->put("http://{$domain}/admin/users/{$employee->id}", assignmentPayload($employee, ['role_id' => $broader->id]))
        ->assertSessionHasErrors('role_id');

    expect(assignmentFind($tenant, 'employee@example.com')->role_id)->toBe($viewer->id);

    $tenant->delete();
});

test('dormant grants count: a role holding keys of a switched-off module is only assignable by someone holding them too', function () {
    $domain = 'assign-dormant.tenant-test';
    // Purchases is not entitled, so purchases.view grants nothing today but
    // would the moment the module is switched back on.
    $tenant = provisionAssignmentTenant($domain, ['users.manage', 'sales.view'], ['sales']);
    $withDormant = assignmentRole($tenant, ['sales.view', 'purchases.view'], 'Sales plus dormant purchases');
    signInForAssignment($domain, 'manager@example.com');

    $this->post("http://{$domain}/admin/users", [
        'name' => 'New Hire',
        'email' => 'hire@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'role_id' => $withDormant->id,
    ])->assertSessionHasErrors('role_id');

    // A manager whose own role carries the same dormant key may assign it.
    $tenant->run(function () {
        $manager = User::query()->where('email', 'manager@example.com')->firstOrFail();
        $manager->role->forceFill(['permissions' => ['users.manage', 'sales.view', 'purchases.view']])->save();
    });
    tenancy()->end();

    $this->post("http://{$domain}/admin/users", [
        'name' => 'Second Hire',
        'email' => 'second@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'role_id' => $withDormant->id,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $tenant->run(function () {
        expect(User::query()->where('email', 'hire@example.com')->exists())->toBeFalse()
            ->and(User::query()->where('email', 'second@example.com')->exists())->toBeTrue();
    });

    $tenant->delete();
});

test('a manager cannot edit the owner at all', function () {
    $domain = 'assign-edit-owner.tenant-test';
    $tenant = provisionAssignmentTenant($domain);
    $owner = assignmentFind($tenant, 'owner@example.com');
    signInForAssignment($domain, 'manager@example.com');

    $this->put("http://{$domain}/admin/users/{$owner->id}", assignmentPayload($owner, ['name' => 'Renamed']))
        ->assertForbidden();
    $this->put("http://{$domain}/admin/users/{$owner->id}", assignmentPayload($owner, ['is_active' => false]))
        ->assertForbidden();

    $fresh = assignmentFind($tenant, 'owner@example.com');
    expect($fresh->name)->toBe('Olivia Owner')
        ->and($fresh->is_active)->toBeTrue()
        ->and($fresh->isOwner())->toBeTrue();

    $tenant->delete();
});

test('a manager cannot change their own role or deactivate themselves, but may edit their own details', function () {
    $domain = 'assign-self.tenant-test';
    $tenant = provisionAssignmentTenant($domain);
    $viewer = assignmentRole($tenant, ['sales.view'], 'Viewer');
    $manager = assignmentFind($tenant, 'manager@example.com');
    signInForAssignment($domain, 'manager@example.com');

    $this->put("http://{$domain}/admin/users/{$manager->id}", assignmentPayload($manager, ['role_id' => $viewer->id]))
        ->assertSessionHasErrors('role_id');
    $this->put("http://{$domain}/admin/users/{$manager->id}", assignmentPayload($manager, ['role_id' => null]))
        ->assertSessionHasErrors('role_id');
    $this->put("http://{$domain}/admin/users/{$manager->id}", assignmentPayload($manager, ['is_active' => false]))
        ->assertSessionHasErrors('is_active');

    $fresh = assignmentFind($tenant, 'manager@example.com');
    expect($fresh->role_id)->toBe($manager->role_id)
        ->and($fresh->is_active)->toBeTrue();

    $this->put("http://{$domain}/admin/users/{$manager->id}", assignmentPayload($manager, ['name' => 'Manny Renamed']))
        ->assertRedirect()->assertSessionHasNoErrors();

    expect(assignmentFind($tenant, 'manager@example.com')->name)->toBe('Manny Renamed');

    $tenant->delete();
});

test('a manager cannot edit a peer whose current role is broader than theirs', function () {
    $domain = 'assign-broader-peer.tenant-test';
    $tenant = provisionAssignmentTenant($domain);
    $viewer = assignmentRole($tenant, ['sales.view'], 'Viewer');
    $peer = assignmentUser($tenant, 'peer@example.com', assignmentRole($tenant, ['users.manage', 'sales.view', 'purchases.view'], 'Senior'));
    signInForAssignment($domain, 'manager@example.com');

    $this->put("http://{$domain}/admin/users/{$peer->id}", assignmentPayload($peer, ['is_active' => false]))
        ->assertForbidden();
    $this->put("http://{$domain}/admin/users/{$peer->id}", assignmentPayload($peer, ['role_id' => $viewer->id]))
        ->assertForbidden();
    $this->put("http://{$domain}/admin/users/{$peer->id}", assignmentPayload($peer, [
        'password' => 'takeover123',
        'password_confirmation' => 'takeover123',
    ]))->assertForbidden();

    $fresh = assignmentFind($tenant, 'peer@example.com');
    expect($fresh->is_active)->toBeTrue()
        ->and($fresh->role_id)->toBe($peer->role_id);

    $tenant->delete();
});

test('the owner cannot be deactivated or have their role changed, even by themselves', function () {
    $domain = 'assign-owner-self.tenant-test';
    $tenant = provisionAssignmentTenant($domain);
    $viewer = assignmentRole($tenant, ['sales.view'], 'Viewer');
    $owner = assignmentFind($tenant, 'owner@example.com');
    signInForAssignment($domain, 'owner@example.com');

    $this->put("http://{$domain}/admin/users/{$owner->id}", assignmentPayload($owner, ['is_active' => false]))
        ->assertSessionHasErrors('is_active');
    $this->put("http://{$domain}/admin/users/{$owner->id}", assignmentPayload($owner, ['role_id' => $viewer->id]))
        ->assertSessionHasErrors('role_id');

    $fresh = assignmentFind($tenant, 'owner@example.com');
    expect($fresh->is_active)->toBeTrue()
        ->and($fresh->role_id)->toBeNull()
        ->and($fresh->isOwner())->toBeTrue();

    // Name, email and password stay editable for the owner's own row.
    $this->put("http://{$domain}/admin/users/{$owner->id}", assignmentPayload($owner, ['name' => 'Olivia Renamed']))
        ->assertRedirect()->assertSessionHasNoErrors();

    expect(assignmentFind($tenant, 'owner@example.com')->name)->toBe('Olivia Renamed');

    $tenant->delete();
});

test('the owner can give any role and deactivate any non-owner', function () {
    $domain = 'assign-owner-any.tenant-test';
    $tenant = provisionAssignmentTenant($domain);
    $everything = assignmentRole($tenant, ['users.manage', 'sales.view', 'sales.cancel', 'purchases.view', 'purchases.cancel'], 'Everything');
    $manager = assignmentFind($tenant, 'manager@example.com');
    signInForAssignment($domain, 'owner@example.com');

    $this->put("http://{$domain}/admin/users/{$manager->id}", assignmentPayload($manager, ['role_id' => $everything->id]))
        ->assertRedirect()->assertSessionHasNoErrors();
    $this->put("http://{$domain}/admin/users/{$manager->id}", assignmentPayload($manager, ['role_id' => $everything->id, 'is_active' => false]))
        ->assertRedirect()->assertSessionHasNoErrors();

    $fresh = assignmentFind($tenant, 'manager@example.com');
    expect($fresh->role_id)->toBe($everything->id)
        ->and($fresh->is_active)->toBeFalse();

    $tenant->delete();
});

test('the page offers a manager only assignable roles and no edit action on the owner or a broader peer', function () {
    $domain = 'assign-index.tenant-test';
    $tenant = provisionAssignmentTenant($domain);
    $viewer = assignmentRole($tenant, ['sales.view'], 'Viewer');
    $broader = assignmentRole($tenant, ['users.manage', 'sales.view', 'purchases.view'], 'Broader');
    // Owner-only keys never grant through a role, so they neither widen nor
    // block a role for the subset rule.
    $withOwnerOnly = assignmentRole($tenant, ['sales.view', 'roles.manage', 'ownership.transfer'], 'Odd one');
    $peer = assignmentUser($tenant, 'peer@example.com', $broader);
    $employee = assignmentUser($tenant, 'employee@example.com', $viewer);
    $owner = assignmentFind($tenant, 'owner@example.com');
    $manager = assignmentFind($tenant, 'manager@example.com');
    signInForAssignment($domain, 'manager@example.com');

    $this->get("http://{$domain}/admin/users")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Admin/Users')
            ->where('roles', function ($roles) use ($viewer, $broader, $withOwnerOnly, $manager) {
                $ids = collect($roles)->pluck('id')->all();

                return in_array($viewer->id, $ids, true)
                    && in_array($withOwnerOnly->id, $ids, true)
                    && in_array($manager->role_id, $ids, true)
                    && ! in_array($broader->id, $ids, true);
            })
            ->where('users', function ($users) use ($owner, $peer, $employee, $manager) {
                $byId = collect($users)->keyBy('id');

                return $byId[$owner->id]['is_owner'] === true
                    && $byId[$owner->id]['editable'] === false
                    && $byId[$peer->id]['editable'] === false
                    && $byId[$employee->id]['editable'] === true
                    && $byId[$manager->id]['editable'] === true
                    && ! array_key_exists('password', $byId[$employee->id]);
            }));

    $tenant->delete();
});

test('the owner is offered every role and can edit every row', function () {
    $domain = 'assign-index-owner.tenant-test';
    $tenant = provisionAssignmentTenant($domain);
    $broader = assignmentRole($tenant, ['users.manage', 'sales.view', 'purchases.view'], 'Broader');
    signInForAssignment($domain, 'owner@example.com');

    $this->get("http://{$domain}/admin/users")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Admin/Users')
            ->where('roles', fn ($roles) => in_array($broader->id, collect($roles)->pluck('id')->all(), true))
            ->where('users', fn ($users) => collect($users)->every(fn ($user) => $user['editable'] === true)));

    $tenant->delete();
});
