<?php

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Permissions\PermissionCatalog;
use App\Support\Permissions\RoleBackfill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * RoleBackfill against a real tenant database. Provisioning seeds roles with
 * permissions already set, so each test first rewinds the roles to the
 * pre-rollout shape (admin and staff, permissions NULL, is_system false) and seeds
 * legacy users (is_owner false, which the factory gives any user created
 * with a role_id). See TenantDatabaseSeederTest for why tenancy is ended
 * after every test.
 */
afterEach(function () {
    tenancy()->end();
});

function provisionRoleBackfillTenant(string $domain, ?string $contactEmail = null): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co', 'contact_email' => $contactEmail]);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function rewindRolesToLegacyShape(): void
{
    // Provisioning now seeds admin, manager and cashier (P13). A pre-rollout
    // tenant had exactly admin and staff, so rebuild that shape here.
    DB::table('roles')->whereNotIn('slug', ['admin'])->delete();
    DB::table('roles')->insert(['name' => 'Staff', 'slug' => 'staff', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('roles')->update(['permissions' => null, 'is_system' => false]);
}

function roleBackfillRoleId(string $slug): int
{
    return (int) Role::query()->where('slug', $slug)->value('id');
}

function roleBackfillUser(string $email, string $roleSlug = 'admin', bool $isActive = true): User
{
    return User::factory()->create([
        'email' => $email,
        'role_id' => roleBackfillRoleId($roleSlug),
        'is_active' => $isActive,
    ]);
}

/**
 * @return list<int>
 */
function roleBackfillOwnerIds(): array
{
    return User::query()->where('is_owner', true)->orderBy('id')->pluck('id')->all();
}

/**
 * @return array{roles: array<int, mixed>, users: array<int, mixed>}
 */
function roleBackfillSnapshot(): array
{
    return [
        'roles' => DB::table('roles')->orderBy('id')->get(['id', 'slug', 'permissions', 'is_system'])->map(fn ($row) => (array) $row)->all(),
        'users' => DB::table('users')->orderBy('id')->get(['id', 'role_id', 'is_owner', 'is_active'])->map(fn ($row) => (array) $row)->all(),
    ];
}

test('the owner is the active admin whose email matches the tenant contact email', function () {
    $tenant = provisionRoleBackfillTenant('backfill-contact.tenant-test', 'boss@example.com');

    $tenant->run(function () {
        rewindRolesToLegacyShape();
        $firstAdmin = roleBackfillUser('first@example.com');
        $boss = roleBackfillUser('boss@example.com');
        $thirdAdmin = roleBackfillUser('third@example.com');

        RoleBackfill::run();

        expect(roleBackfillOwnerIds())->toBe([$boss->id]);

        foreach ([$firstAdmin, $thirdAdmin] as $otherAdmin) {
            $otherAdmin->refresh();
            expect($otherAdmin->is_owner)->toBeFalse()
                ->and($otherAdmin->role_id)->toBe(roleBackfillRoleId('admin'));
        }
    });

    $tenant->delete();
});

test('the contact email match is case-insensitive', function () {
    $tenant = provisionRoleBackfillTenant('backfill-case.tenant-test', '  Boss@Example.COM ');

    $tenant->run(function () {
        rewindRolesToLegacyShape();
        roleBackfillUser('first@example.com');
        $boss = roleBackfillUser('boss@EXAMPLE.com');

        RoleBackfill::run();

        expect(roleBackfillOwnerIds())->toBe([$boss->id]);
    });

    $tenant->delete();
});

test('without a contact email match the lowest-id active admin becomes owner', function () {
    $tenant = provisionRoleBackfillTenant('backfill-fallback.tenant-test', 'nobody@example.com');

    $tenant->run(function () {
        rewindRolesToLegacyShape();
        roleBackfillUser('staffer@example.com', 'staff');
        $lowestAdmin = roleBackfillUser('first@example.com');
        $otherAdmin = roleBackfillUser('second@example.com');

        RoleBackfill::run();

        expect(roleBackfillOwnerIds())->toBe([$lowestAdmin->id])
            ->and($otherAdmin->refresh()->role_id)->toBe(roleBackfillRoleId('admin'));
    });

    $tenant->delete();
});

test('a tenant with no contact email falls back to the lowest-id active admin', function () {
    $tenant = provisionRoleBackfillTenant('backfill-no-contact.tenant-test');

    $tenant->run(function () {
        rewindRolesToLegacyShape();
        $lowestAdmin = roleBackfillUser('first@example.com');
        roleBackfillUser('second@example.com');

        RoleBackfill::run();

        expect(roleBackfillOwnerIds())->toBe([$lowestAdmin->id]);
    });

    $tenant->delete();
});

test('deactivated admins are never picked, even when their email matches the contact email', function () {
    $tenant = provisionRoleBackfillTenant('backfill-inactive.tenant-test', 'boss@example.com');

    $tenant->run(function () {
        rewindRolesToLegacyShape();
        roleBackfillUser('boss@example.com', 'admin', false);
        roleBackfillUser('first@example.com', 'admin', false);
        $activeAdmin = roleBackfillUser('second@example.com');

        RoleBackfill::run();

        expect(roleBackfillOwnerIds())->toBe([$activeAdmin->id]);
    });

    $tenant->delete();
});

test('a tenant with no active admin gets no owner and no exception', function () {
    $tenant = provisionRoleBackfillTenant('backfill-no-admin.tenant-test', 'boss@example.com');

    $tenant->run(function () {
        rewindRolesToLegacyShape();
        roleBackfillUser('boss@example.com', 'staff');
        roleBackfillUser('gone@example.com', 'admin', false);

        RoleBackfill::run();

        expect(roleBackfillOwnerIds())->toBe([]);
    });

    $tenant->delete();
});

test('a tenant without an admin role row gets no owner and no exception', function () {
    $tenant = provisionRoleBackfillTenant('backfill-no-admin-role.tenant-test', 'boss@example.com');

    $tenant->run(function () {
        rewindRolesToLegacyShape();
        roleBackfillUser('boss@example.com', 'staff');
        DB::table('roles')->where('slug', 'admin')->delete();

        RoleBackfill::run();

        expect(roleBackfillOwnerIds())->toBe([])
            ->and(Role::query()->where('slug', 'staff')->firstOrFail()->permissions)->toBe(RoleBackfill::STAFF_PARITY);
    });

    $tenant->delete();
});

test('an existing owner means no owner change at all', function () {
    $tenant = provisionRoleBackfillTenant('backfill-existing-owner.tenant-test', 'boss@example.com');

    $tenant->run(function () {
        rewindRolesToLegacyShape();
        $boss = roleBackfillUser('boss@example.com');
        $existingOwner = User::factory()->create([
            'email' => 'owner@example.com',
            'role_id' => roleBackfillRoleId('staff'),
            'is_owner' => true,
        ]);

        RoleBackfill::run();

        expect(roleBackfillOwnerIds())->toBe([$existingOwner->id])
            ->and($boss->refresh()->is_owner)->toBeFalse();
    });

    $tenant->delete();
});

test('roles get the frozen lists: admin all grantable and system, staff the parity list, others empty', function () {
    $tenant = provisionRoleBackfillTenant('backfill-roles.tenant-test');

    $tenant->run(function () {
        rewindRolesToLegacyShape();
        DB::table('roles')->insert(['name' => 'Accountant', 'slug' => 'accountant', 'created_at' => now(), 'updated_at' => now()]);

        RoleBackfill::run();

        $admin = Role::query()->where('slug', 'admin')->firstOrFail();
        $staff = Role::query()->where('slug', 'staff')->firstOrFail();
        $accountant = Role::query()->where('slug', 'accountant')->firstOrFail();

        expect($admin->permissions)->toBe(RoleBackfill::GRANTABLE_AT_ROLLOUT)
            ->and($admin->is_system)->toBeTrue()
            ->and($staff->permissions)->toBe(RoleBackfill::STAFF_PARITY)
            ->and($staff->is_system)->toBeFalse()
            ->and($accountant->permissions)->toBe([])
            ->and($accountant->is_system)->toBeFalse();
    });

    $tenant->delete();
});

test('an already edited role is never overwritten', function () {
    $tenant = provisionRoleBackfillTenant('backfill-edited.tenant-test');

    $tenant->run(function () {
        rewindRolesToLegacyShape();
        DB::table('roles')->where('slug', 'admin')->update(['permissions' => json_encode(['sales.view'])]);
        DB::table('roles')->where('slug', 'staff')->update(['permissions' => json_encode([])]);
        DB::table('roles')->insert([
            'name' => 'Cashier',
            'slug' => 'cashier',
            'permissions' => json_encode(['pos.view', 'sales.create']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        RoleBackfill::run();

        expect(Role::query()->where('slug', 'admin')->firstOrFail()->permissions)->toBe(['sales.view'])
            ->and(Role::query()->where('slug', 'staff')->firstOrFail()->permissions)->toBe([])
            ->and(Role::query()->where('slug', 'cashier')->firstOrFail()->permissions)->toBe(['pos.view', 'sales.create']);
    });

    $tenant->delete();
});

test('a second run changes nothing', function () {
    $tenant = provisionRoleBackfillTenant('backfill-idempotent.tenant-test', 'boss@example.com');

    $tenant->run(function () {
        rewindRolesToLegacyShape();
        DB::table('roles')->insert(['name' => 'Accountant', 'slug' => 'accountant', 'created_at' => now(), 'updated_at' => now()]);
        roleBackfillUser('first@example.com');
        roleBackfillUser('boss@example.com');
        roleBackfillUser('staffer@example.com', 'staff');

        RoleBackfill::run();
        $afterFirstRun = roleBackfillSnapshot();

        RoleBackfill::run();

        expect(roleBackfillSnapshot())->toEqual($afterFirstRun)
            ->and(roleBackfillOwnerIds())->toHaveCount(1);
    });

    $tenant->delete();
});

test('no owner-only key ever appears in any backfilled role', function () {
    $tenant = provisionRoleBackfillTenant('backfill-owner-only.tenant-test');

    $tenant->run(function () {
        rewindRolesToLegacyShape();
        DB::table('roles')->insert(['name' => 'Accountant', 'slug' => 'accountant', 'created_at' => now(), 'updated_at' => now()]);

        RoleBackfill::run();

        $ownerOnly = PermissionCatalog::ownerOnly();
        expect($ownerOnly)->not->toBe([]);

        Role::query()->get()->each(function (Role $role) use ($ownerOnly) {
            expect($role->permissions)->toBeArray()
                ->and(array_intersect($role->permissions, $ownerOnly))->toBe([]);
        });
    });

    $tenant->delete();
});
