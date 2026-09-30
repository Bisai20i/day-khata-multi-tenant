<?php

use App\Models\ActivityLog;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Permissions\OwnershipTransfer;
use App\Support\Permissions\OwnershipTransferException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Ownership transfer (P12): the owner hands the company to another active
 * user, confirmed with their password, audit-logged, and afterwards exactly
 * one owner exists. The first half drives the HTTP route, the second half
 * calls OwnershipTransfer directly for the invariants the route cannot reach
 * (stale instances, a corrupt two-owner state, the promote() recovery path).
 *
 * SQLite ignores lockForUpdate(), so the row locking itself is covered by
 * review of OwnershipTransfer, not here. Tenancy is ended after every test
 * for the reason given in UserManagementTest.
 */
afterEach(function () {
    tenancy()->end();
});

/**
 * A tenant whose owner (owner@example.com) holds an admin-like role with
 * users.manage, plus an active employee (next@example.com) and an inactive
 * one (gone@example.com).
 */
function provisionTransferTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    $tenant->run(function () {
        $adminLike = roleWithPermissions(['users.manage', 'sales.view', 'sales.create'], 'Admin like');
        $counter = roleWithPermissions(['sales.view'], 'Counter');

        User::factory()->create(['email' => 'owner@example.com', 'name' => 'Olivia Owner', 'role_id' => $adminLike->id, 'is_owner' => true]);
        User::factory()->create(['email' => 'next@example.com', 'name' => 'Nina Next', 'role_id' => $counter->id]);
        User::factory()->create(['email' => 'gone@example.com', 'name' => 'Gary Gone', 'role_id' => $counter->id, 'is_active' => false]);
    });

    return $tenant;
}

function signInForTransfer(string $domain, string $email): void
{
    test()->post("http://{$domain}/logout");
    test()->post("http://{$domain}/login", ['email' => $email, 'password' => 'password']);
}

function transferFind(Tenant $tenant, string $email): User
{
    $user = null;
    $tenant->run(function () use (&$user, $email) {
        $user = User::query()->where('email', $email)->firstOrFail();
    });

    return $user;
}

/**
 * @return list<string>
 */
function transferOwnerEmails(Tenant $tenant): array
{
    $emails = [];
    $tenant->run(function () use (&$emails) {
        $emails = User::query()->where('is_owner', true)->orderBy('email')->pluck('email')->all();
    });

    return $emails;
}

function transferLogCount(Tenant $tenant, string $action = 'ownership.transferred'): int
{
    $count = 0;
    $tenant->run(function () use (&$count, $action) {
        $count = ActivityLog::query()->where('action', $action)->count();
    });

    return $count;
}

test('the owner transfers ownership: exactly one owner remains, roles are untouched and the change is audited', function () {
    $domain = 'transfer-ok.tenant-test';
    $tenant = provisionTransferTenant($domain);
    $owner = transferFind($tenant, 'owner@example.com');
    $next = transferFind($tenant, 'next@example.com');
    signInForTransfer($domain, 'owner@example.com');

    $this->post("http://{$domain}/admin/users/{$next->id}/transfer-ownership", ['current_password' => 'password'])
        ->assertRedirectContains('/admin/users')
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status');

    expect(transferOwnerEmails($tenant))->toBe(['next@example.com']);

    $freshOwner = transferFind($tenant, 'owner@example.com');
    $freshNext = transferFind($tenant, 'next@example.com');
    expect($freshOwner->role_id)->toBe($owner->role_id)
        ->and($freshOwner->is_active)->toBeTrue()
        ->and($freshNext->role_id)->toBe($next->role_id);

    $tenant->run(function () use ($owner, $next) {
        $log = ActivityLog::query()->where('action', 'ownership.transferred')->sole();
        expect($log->user_id)->toBe($owner->id)
            ->and($log->subject_type)->toBe((new User)->getMorphClass())
            ->and($log->subject_id)->toBe($next->id)
            ->and($log->changes['from']['id'])->toBe($owner->id)
            ->and($log->changes['to']['id'])->toBe($next->id);
    });

    $tenant->delete();
});

test('the old owner loses owner-only rights immediately after the transfer', function () {
    $domain = 'transfer-rights.tenant-test';
    $tenant = provisionTransferTenant($domain);
    $next = transferFind($tenant, 'next@example.com');
    $owner = transferFind($tenant, 'owner@example.com');
    signInForTransfer($domain, 'owner@example.com');

    $this->get("http://{$domain}/admin/roles")->assertOk();

    $this->post("http://{$domain}/admin/users/{$next->id}/transfer-ownership", ['current_password' => 'password'])
        ->assertSessionHasNoErrors();

    // Same session, no re-login: roles.manage and ownership.transfer are
    // owner-only, so both are gone at once.
    $this->get("http://{$domain}/admin/roles")->assertForbidden();
    $this->post("http://{$domain}/admin/users/{$owner->id}/transfer-ownership", ['current_password' => 'password'])
        ->assertForbidden();

    expect(transferOwnerEmails($tenant))->toBe(['next@example.com']);

    $tenant->delete();
});

test('a wrong or missing password is rejected and nothing changes', function () {
    $domain = 'transfer-password.tenant-test';
    $tenant = provisionTransferTenant($domain);
    $next = transferFind($tenant, 'next@example.com');
    signInForTransfer($domain, 'owner@example.com');

    $this->post("http://{$domain}/admin/users/{$next->id}/transfer-ownership", ['current_password' => 'not-my-password'])
        ->assertSessionHasErrors('current_password');
    $this->post("http://{$domain}/admin/users/{$next->id}/transfer-ownership", [])
        ->assertSessionHasErrors('current_password');

    expect(transferOwnerEmails($tenant))->toBe(['owner@example.com'])
        ->and(transferLogCount($tenant))->toBe(0);

    $tenant->delete();
});

test('an inactive target is rejected', function () {
    $domain = 'transfer-inactive.tenant-test';
    $tenant = provisionTransferTenant($domain);
    $gone = transferFind($tenant, 'gone@example.com');
    signInForTransfer($domain, 'owner@example.com');

    $this->post("http://{$domain}/admin/users/{$gone->id}/transfer-ownership", ['current_password' => 'password'])
        ->assertSessionHasErrors('transfer');

    expect(transferOwnerEmails($tenant))->toBe(['owner@example.com'])
        ->and(transferLogCount($tenant))->toBe(0);

    $tenant->delete();
});

test('transferring to oneself is rejected', function () {
    $domain = 'transfer-self.tenant-test';
    $tenant = provisionTransferTenant($domain);
    $owner = transferFind($tenant, 'owner@example.com');
    signInForTransfer($domain, 'owner@example.com');

    $this->post("http://{$domain}/admin/users/{$owner->id}/transfer-ownership", ['current_password' => 'password'])
        ->assertSessionHasErrors('transfer');

    expect(transferOwnerEmails($tenant))->toBe(['owner@example.com'])
        ->and(transferLogCount($tenant))->toBe(0);

    $tenant->delete();
});

test('a non-owner is denied even when their role JSON carries ownership.transfer', function () {
    $domain = 'transfer-non-owner.tenant-test';
    $tenant = provisionTransferTenant($domain);

    $tenant->run(function () {
        User::factory()->create([
            'email' => 'schemer@example.com',
            'role_id' => roleWithPermissions(['users.manage', 'ownership.transfer', 'roles.manage'], 'Schemer')->id,
        ]);
    });
    $schemer = transferFind($tenant, 'schemer@example.com');
    signInForTransfer($domain, 'schemer@example.com');

    $this->post("http://{$domain}/admin/users/{$schemer->id}/transfer-ownership", ['current_password' => 'password'])
        ->assertForbidden();

    expect(transferOwnerEmails($tenant))->toBe(['owner@example.com'])
        ->and(transferLogCount($tenant))->toBe(0);

    $tenant->delete();
});

test('run() moves the flag and syncs both instances, including their permission sets', function () {
    $tenant = provisionTransferTenant('transfer-unit-run.tenant-test');

    $tenant->run(function () {
        $owner = User::query()->where('email', 'owner@example.com')->firstOrFail();
        $next = User::query()->where('email', 'next@example.com')->firstOrFail();

        expect($owner->hasPermission('roles.manage'))->toBeTrue()
            ->and($next->hasPermission('roles.manage'))->toBeFalse();

        OwnershipTransfer::run($owner, $next);

        expect($owner->isOwner())->toBeFalse()
            ->and($next->isOwner())->toBeTrue()
            ->and($owner->hasPermission('roles.manage'))->toBeFalse()
            ->and($owner->hasPermission('users.manage'))->toBeTrue()
            ->and($next->hasPermission('roles.manage'))->toBeTrue()
            ->and($owner->isDirty('is_owner'))->toBeFalse()
            ->and(User::query()->where('is_owner', true)->pluck('email')->all())->toBe(['next@example.com']);
    });

    $tenant->delete();
});

test('run() re-reads the rows: a stale owner instance cannot transfer an ownership it no longer holds', function () {
    $tenant = provisionTransferTenant('transfer-unit-stale.tenant-test');

    $tenant->run(function () {
        $staleOwner = User::query()->where('email', 'owner@example.com')->firstOrFail();
        $next = User::query()->where('email', 'next@example.com')->firstOrFail();
        $third = User::factory()->create(['email' => 'third@example.com', 'role_id' => $next->role_id]);

        // Ownership already moved elsewhere (another tab, a central fix).
        OwnershipTransfer::run(User::query()->where('email', 'owner@example.com')->firstOrFail(), $next);

        expect($staleOwner->is_owner)->toBeTrue();
        expect(fn () => OwnershipTransfer::run($staleOwner, $third))
            ->toThrow(OwnershipTransferException::class, 'Only the current owner');

        expect(User::query()->where('is_owner', true)->pluck('email')->all())->toBe(['next@example.com']);
    });

    $tenant->delete();
});

test('run() refuses an inactive target, the same user and a target that is already owner', function () {
    $tenant = provisionTransferTenant('transfer-unit-refusals.tenant-test');

    $tenant->run(function () {
        $owner = User::query()->where('email', 'owner@example.com')->firstOrFail();
        $gone = User::query()->where('email', 'gone@example.com')->firstOrFail();

        expect(fn () => OwnershipTransfer::run($owner, $gone))->toThrow(OwnershipTransferException::class, 'inactive');
        expect(fn () => OwnershipTransfer::run($owner, $owner->fresh()))->toThrow(OwnershipTransferException::class);

        // A corrupt state with a second owner: handing ownership to someone
        // who already has it is refused.
        $second = User::factory()->create(['email' => 'second@example.com', 'is_owner' => true]);
        expect(fn () => OwnershipTransfer::run($owner, $second))->toThrow(OwnershipTransferException::class, 'already the owner');

        expect(User::query()->where('is_owner', true)->orderBy('email')->pluck('email')->all())
            ->toBe(['owner@example.com', 'second@example.com'])
            ->and(ActivityLog::query()->where('action', 'ownership.transferred')->count())->toBe(0);
    });

    $tenant->delete();
});

test('run() rolls back when the result would not be exactly one owner', function () {
    $tenant = provisionTransferTenant('transfer-unit-invariant.tenant-test');

    $tenant->run(function () {
        $owner = User::query()->where('email', 'owner@example.com')->firstOrFail();
        $next = User::query()->where('email', 'next@example.com')->firstOrFail();
        User::factory()->create(['email' => 'second@example.com', 'is_owner' => true]);

        expect(fn () => OwnershipTransfer::run($owner, $next))
            ->toThrow(OwnershipTransferException::class, 'exactly one');

        expect(User::query()->where('is_owner', true)->orderBy('email')->pluck('email')->all())
            ->toBe(['owner@example.com', 'second@example.com'])
            ->and($owner->isOwner())->toBeTrue()
            ->and($next->isOwner())->toBeFalse()
            ->and(ActivityLog::query()->where('action', 'ownership.transferred')->count())->toBe(0);
    });

    $tenant->delete();
});

test('promote() only works on a tenant with no owner, and only for an active user', function () {
    $tenant = provisionTransferTenant('transfer-unit-promote.tenant-test');

    $tenant->run(function () {
        $next = User::query()->where('email', 'next@example.com')->firstOrFail();
        $gone = User::query()->where('email', 'gone@example.com')->firstOrFail();

        expect(fn () => OwnershipTransfer::promote($next))
            ->toThrow(OwnershipTransferException::class, 'already has an owner');

        User::query()->where('email', 'owner@example.com')->update(['is_owner' => false]);

        expect(fn () => OwnershipTransfer::promote($gone))->toThrow(OwnershipTransferException::class, 'inactive');

        OwnershipTransfer::promote($next);

        expect($next->isOwner())->toBeTrue()
            ->and(User::query()->where('is_owner', true)->pluck('email')->all())->toBe(['next@example.com'])
            ->and(ActivityLog::query()->where('action', 'ownership.promoted')->where('subject_id', $next->id)->count())->toBe(1);
    });

    $tenant->delete();
});
