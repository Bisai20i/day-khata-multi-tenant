<?php

use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Permissions\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Owner-only role editor (P11). Tenancy is ended after every test for the
 * reason given in UserManagementTest.
 */
afterEach(function () {
    tenancy()->end();
});

/**
 * @param  list<string>|null  $enabledModules  null = the test default (every module)
 */
function provisionRoleEditorTenant(string $domain, ?array $enabledModules = null): Tenant
{
    $attributes = ['company_name' => 'Acme Co'];
    if ($enabledModules !== null) {
        $attributes['enabled_modules'] = $enabledModules;
    }

    $tenant = Tenant::create($attributes);
    $tenant->domains()->create(['domain' => $domain]);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);
    });

    return $tenant;
}

function signInToRoleEditorTenant(string $domain, string $email = 'owner@example.com'): void
{
    test()->post("http://{$domain}/logout");
    test()->post("http://{$domain}/login", ['email' => $email, 'password' => 'password']);
}

/**
 * @param  list<string>  $permissions
 */
function makeRoleEditorRole(Tenant $tenant, string $name, array $permissions, bool $isSystem = false): Role
{
    $role = null;
    $tenant->run(function () use (&$role, $name, $permissions, $isSystem) {
        $role = roleWithPermissions($permissions, $name);
        $role->forceFill(['is_system' => $isSystem])->save();
        $role->refresh();
    });

    return $role;
}

/**
 * @param  list<string>  $keys
 * @return list<string>
 */
function inRoleEditorCatalogOrder(array $keys): array
{
    return array_values(array_intersect(array_keys(PermissionCatalog::permissions()), $keys));
}

test('the owner can create a role; slug is generated and the save is audited', function () {
    $domain = 'roles-create.tenant-test';
    $tenant = provisionRoleEditorTenant($domain);
    signInToRoleEditorTenant($domain);

    $this->post("http://{$domain}/admin/roles", [
        'name' => 'Front Desk',
        'slug' => 'attacker-chosen',
        'permissions' => ['sales.create', 'sales.view'],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $tenant->run(function () {
        $role = Role::query()->where('name', 'Front Desk')->firstOrFail();
        expect($role->slug)->toBe('front-desk');
        expect($role->is_system)->toBeFalse();
        expect($role->permissions)->toBe(inRoleEditorCatalogOrder(['sales.view', 'sales.create']));

        $log = ActivityLog::query()->where('subject_type', $role->getMorphClass())->where('subject_id', $role->id)->sole();
        expect($log->action)->toBe('created');
        expect($log->changes['permissions']['after'])->toBe($role->permissions);
    });

    $tenant->delete();
});

test('granting a non-entitled, owner-only or unknown permission is rejected with 422 naming the key', function () {
    $domain = 'roles-reject.tenant-test';
    $tenant = provisionRoleEditorTenant($domain, ['sales']);
    signInToRoleEditorTenant($domain);

    foreach (['purchases.view', 'roles.manage', 'backups.manage', 'nope.view'] as $badKey) {
        $response = $this->post("http://{$domain}/admin/roles", [
            'name' => "Bad {$badKey}",
            'permissions' => ['sales.view', $badKey],
        ]);

        $response->assertSessionHasErrors('permissions.1');
        expect(session('errors')->first('permissions.1'))->toContain($badKey);

        $this->postJson("http://{$domain}/admin/roles", [
            'name' => "Bad json {$badKey}",
            'permissions' => ['sales.view', $badKey],
        ])->assertStatus(422)->assertJsonValidationErrors('permissions.1');
    }

    $tenant->run(function () {
        expect(Role::query()->where('name', 'like', 'Bad%')->exists())->toBeFalse();
    });

    $tenant->delete();
});

test('a non-owner gets 403 on every roles route even with roles.manage and users.manage in their role JSON', function () {
    $domain = 'roles-non-owner.tenant-test';
    $tenant = provisionRoleEditorTenant($domain);
    $target = makeRoleEditorRole($tenant, 'Target', ['sales.view']);

    $tenant->run(function () {
        $user = userWithPermissions(['users.manage', 'roles.manage']);
        $user->forceFill(['email' => 'manager@example.com'])->save();
    });

    signInToRoleEditorTenant($domain, 'manager@example.com');

    // Sanity: the same user is otherwise authorized (users.manage works).
    expect($this->get("http://{$domain}/admin/users")->status())->not->toBe(403);

    $this->get("http://{$domain}/admin/roles")->assertForbidden();
    $this->get("http://{$domain}/admin/roles/create")->assertForbidden();
    $this->post("http://{$domain}/admin/roles", ['name' => 'Sneaky', 'permissions' => []])->assertForbidden();
    $this->get("http://{$domain}/admin/roles/{$target->id}/edit")->assertForbidden();
    $this->put("http://{$domain}/admin/roles/{$target->id}", [
        'name' => 'Target',
        'permissions' => ['sales.view', 'sales.cancel'],
        'updated_at' => $target->updated_at?->toJSON(),
    ])->assertForbidden();
    $this->post("http://{$domain}/admin/roles/{$target->id}/duplicate")->assertForbidden();
    $this->delete("http://{$domain}/admin/roles/{$target->id}")->assertForbidden();

    $tenant->run(function () use ($target) {
        expect(Role::query()->where('name', 'Sneaky')->exists())->toBeFalse();
        expect(Role::query()->where('name', 'like', 'Target (copy%')->exists())->toBeFalse();
        expect($target->fresh()->permissions)->toBe(['sales.view']);
    });

    $tenant->delete();
});

test('the owner can still manage roles with the admin module switched off', function () {
    $domain = 'roles-admin-off.tenant-test';
    $allButAdmin = array_values(array_diff(array_keys(PermissionCatalog::modules()), ['admin']));
    $tenant = provisionRoleEditorTenant($domain, $allButAdmin);
    signInToRoleEditorTenant($domain);

    $this->get("http://{$domain}/admin/roles")->assertOk();
    $this->post("http://{$domain}/admin/roles", ['name' => 'Clerk', 'permissions' => ['items.view']])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $tenant->run(function () {
        expect(Role::query()->where('name', 'Clerk')->value('permissions'))->toBe(['items.view']);
    });

    $tenant->delete();
});

test('update replaces the entitled grants, keeps dormant grants from switched-off modules, and is audited', function () {
    $domain = 'roles-dormant.tenant-test';
    $tenant = provisionRoleEditorTenant($domain, ['sales']);
    $role = makeRoleEditorRole($tenant, 'Mixed', [
        'sales.view', 'sales.cancel',
        // Dormant: purchases is not entitled, so the editor never shows these.
        'purchases.view', 'purchases.create',
        // Never effective: owner-only and unknown keys are dropped on save.
        'backups.manage', 'removed.key',
    ]);
    signInToRoleEditorTenant($domain);

    $this->put("http://{$domain}/admin/roles/{$role->id}", [
        'name' => 'Mixed renamed',
        'permissions' => ['sales.view', 'sales.create'],
        'updated_at' => $role->updated_at->toJSON(),
    ])->assertRedirect()->assertSessionHasNoErrors();

    $tenant->run(function () use ($role) {
        $fresh = $role->fresh();
        expect($fresh->name)->toBe('Mixed renamed');
        expect($fresh->permissions)->toBe(inRoleEditorCatalogOrder(['sales.view', 'sales.create', 'purchases.view', 'purchases.create']));

        $log = ActivityLog::query()->where('subject_type', $fresh->getMorphClass())->where('subject_id', $fresh->id)->where('action', 'updated')->sole();
        expect($log->changes['name'])->toEqual(['from' => 'Mixed', 'to' => 'Mixed renamed']);
        expect($log->changes['permissions']['added'])->toBe(['sales.create']);
        expect($log->changes['permissions']['removed'])->toEqualCanonicalizing(['sales.cancel', 'backups.manage', 'removed.key']);
        expect($log->changes['permissions']['before'])->toContain('purchases.view');
        expect($log->changes['permissions']['after'])->toBe($fresh->permissions);
    });

    $tenant->delete();
});

test('a stale update is rejected: 409 for JSON, errors.updated_at for the Inertia form, nothing saved', function () {
    $domain = 'roles-stale.tenant-test';
    $tenant = provisionRoleEditorTenant($domain);
    $role = makeRoleEditorRole($tenant, 'Till Clerk', ['sales.view']);
    signInToRoleEditorTenant($domain);

    $loadedAt = $role->updated_at->toJSON();

    // First session saves with the timestamp it loaded.
    $this->put("http://{$domain}/admin/roles/{$role->id}", [
        'name' => 'Till Clerk',
        'permissions' => ['sales.view', 'sales.create'],
        'updated_at' => $loadedAt,
    ])->assertSessionHasNoErrors();

    // Second session still holds the old timestamp (even within the same
    // second, the first save moved updated_at strictly forward).
    $this->put("http://{$domain}/admin/roles/{$role->id}", [
        'name' => 'Till Clerk',
        'permissions' => ['sales.view', 'sales.cancel'],
        'updated_at' => $loadedAt,
    ])->assertRedirect()->assertSessionHasErrors('updated_at');

    $this->putJson("http://{$domain}/admin/roles/{$role->id}", [
        'name' => 'Till Clerk',
        'permissions' => ['sales.view', 'sales.cancel'],
        'updated_at' => $loadedAt,
    ])->assertStatus(409)->assertJsonValidationErrors('updated_at');

    $tenant->run(function () use ($role) {
        expect($role->fresh()->permissions)->toBe(inRoleEditorCatalogOrder(['sales.view', 'sales.create']));
        expect(ActivityLog::query()->where('subject_id', $role->id)->where('action', 'updated')->count())->toBe(1);
    });

    $tenant->delete();
});

test('a built-in role keeps its name but its permissions can be edited', function () {
    $domain = 'roles-system-name.tenant-test';
    $tenant = provisionRoleEditorTenant($domain);
    $role = makeRoleEditorRole($tenant, 'Built In', ['sales.view'], isSystem: true);
    signInToRoleEditorTenant($domain);

    $this->put("http://{$domain}/admin/roles/{$role->id}", [
        'name' => 'Renamed',
        'permissions' => ['sales.view'],
        'updated_at' => $role->updated_at->toJSON(),
    ])->assertSessionHasErrors('name');

    $this->put("http://{$domain}/admin/roles/{$role->id}", [
        'name' => 'Built In',
        'permissions' => ['sales.view', 'sales.print'],
        'updated_at' => $role->updated_at->toJSON(),
    ])->assertSessionHasNoErrors();

    $tenant->run(function () use ($role) {
        $fresh = $role->fresh();
        expect($fresh->name)->toBe('Built In');
        expect($fresh->permissions)->toBe(inRoleEditorCatalogOrder(['sales.view', 'sales.print']));
    });

    $tenant->delete();
});

test('delete is refused for a role in use and for a built-in role, and allowed otherwise', function () {
    $domain = 'roles-delete.tenant-test';
    $tenant = provisionRoleEditorTenant($domain);
    $inUse = makeRoleEditorRole($tenant, 'In use', ['sales.view']);
    $system = makeRoleEditorRole($tenant, 'System', ['sales.view'], isSystem: true);
    $unused = makeRoleEditorRole($tenant, 'Unused', ['sales.view']);

    $tenant->run(function () use ($inUse) {
        // An inactive holder still blocks deletion: nullOnDelete would strip
        // their role the moment they were reactivated.
        User::factory()->create(['role_id' => $inUse->id, 'is_owner' => false, 'is_active' => false]);
    });
    signInToRoleEditorTenant($domain);

    $this->delete("http://{$domain}/admin/roles/{$inUse->id}")->assertRedirect()->assertSessionHasErrors('role');
    $this->delete("http://{$domain}/admin/roles/{$system->id}")->assertRedirect()->assertSessionHasErrors('role');
    $this->delete("http://{$domain}/admin/roles/{$unused->id}")->assertRedirect()->assertSessionHasNoErrors();

    $tenant->run(function () use ($inUse, $system, $unused) {
        expect(Role::query()->whereKey($inUse->id)->exists())->toBeTrue();
        expect(Role::query()->whereKey($system->id)->exists())->toBeTrue();
        expect(Role::query()->whereKey($unused->id)->exists())->toBeFalse();
        expect(User::query()->where('role_id', $inUse->id)->count())->toBe(1);

        $log = ActivityLog::query()->where('subject_id', $unused->id)->where('action', 'deleted')->sole();
        expect($log->changes['permissions']['before'])->toBe(['sales.view']);
    });

    $tenant->delete();
});

test('duplicate copies the permission set, dormant grants included, under a unique name and slug', function () {
    $domain = 'roles-duplicate.tenant-test';
    $tenant = provisionRoleEditorTenant($domain, ['sales']);
    $source = makeRoleEditorRole($tenant, 'Till Clerk', ['sales.view', 'sales.create', 'purchases.view'], isSystem: true);
    signInToRoleEditorTenant($domain);

    $this->post("http://{$domain}/admin/roles/{$source->id}/duplicate")->assertRedirect()->assertSessionHasNoErrors();
    $this->post("http://{$domain}/admin/roles/{$source->id}/duplicate")->assertRedirect()->assertSessionHasNoErrors();

    $tenant->run(function () use ($source) {
        $first = Role::query()->where('name', 'Till Clerk (copy)')->firstOrFail();
        $second = Role::query()->where('name', 'Till Clerk (copy 2)')->firstOrFail();

        foreach ([$first, $second] as $copy) {
            expect($copy->permissions)->toBe(['sales.view', 'sales.create', 'purchases.view']);
            expect($copy->is_system)->toBeFalse();
            expect($copy->slug)->not->toBe($source->slug);
        }
        expect($first->slug)->not->toBe($second->slug);
        expect(ActivityLog::query()->where('subject_id', $first->id)->where('action', 'created')->exists())->toBeTrue();
    });

    $tenant->delete();
});

test('the edit page lists only entitled modules, never owner-only keys, and hides dormant grants', function () {
    $domain = 'roles-edit-props.tenant-test';
    $tenant = provisionRoleEditorTenant($domain, ['sales']);
    $role = makeRoleEditorRole($tenant, 'Till Clerk', ['sales.view', 'purchases.view', 'purchases.create']);
    signInToRoleEditorTenant($domain);

    $this->get("http://{$domain}/admin/roles/{$role->id}/edit")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Admin/Roles/Edit')
            ->where('modules', function ($modules) {
                $modules = collect($modules);
                expect($modules->pluck('module')->all())->toBe(['core', 'sales']);

                $keys = $modules->flatMap(fn ($module) => collect($module['groups'])
                    ->flatMap(fn ($group) => collect($group['permissions'])->pluck('key')));
                foreach (PermissionCatalog::ownerOnly() as $ownerOnly) {
                    expect($keys->contains($ownerOnly))->toBeFalse();
                }

                return true;
            })
            ->where('granted', ['sales.view'])
            ->where('dormantCount', 2)
            ->where('role.id', $role->id));

    $this->get("http://{$domain}/admin/roles/create")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Admin/Roles/Edit')
            ->where('role', null)
            ->where('modules', fn ($modules) => collect($modules)->pluck('module')->all() === ['core', 'sales']));

    $tenant->delete();
});

test('the index lists roles with user counts', function () {
    $domain = 'roles-index.tenant-test';
    $tenant = provisionRoleEditorTenant($domain);
    $role = makeRoleEditorRole($tenant, 'Counted', ['sales.view']);

    $tenant->run(function () use ($role) {
        User::factory()->count(2)->create(['role_id' => $role->id, 'is_owner' => false]);
    });
    signInToRoleEditorTenant($domain);

    $this->get("http://{$domain}/admin/roles")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Admin/Roles/Index')
            ->where('roles', fn ($roles) => collect($roles)->firstWhere('id', $role->id)['users_count'] === 2));

    $tenant->delete();
});
