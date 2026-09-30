<?php

use App\Support\Permissions\PermissionCatalog;
use App\Support\Permissions\RoleBackfill;
use Tests\TestCase;

/**
 * RoleBackfill's key lists are frozen copies of the catalog at rollout, so
 * these tests pin their shape and catch a later catalog rename (which would
 * leave migrated tenants holding a dead key). Opts in to TestCase for
 * config(), like PermissionCatalogTest. The DB-backed backfill behaviour is
 * covered by tests/Feature/Tenant/Authorization/RoleBackfillTest.php.
 */
uses(TestCase::class);

beforeEach(fn () => PermissionCatalog::flush());
afterEach(fn () => PermissionCatalog::flush());

test('the frozen grantable list has 143 unique keys', function () {
    expect(RoleBackfill::GRANTABLE_AT_ROLLOUT)->toHaveCount(143)
        ->and(array_unique(RoleBackfill::GRANTABLE_AT_ROLLOUT))->toHaveCount(143);
});

test('the staff parity list has 100 unique keys and is a subset of the grantable list', function () {
    expect(RoleBackfill::STAFF_PARITY)->toHaveCount(100)
        ->and(array_unique(RoleBackfill::STAFF_PARITY))->toHaveCount(100)
        ->and(array_diff(RoleBackfill::STAFF_PARITY, RoleBackfill::GRANTABLE_AT_ROLLOUT))->toBe([]);
});

test('every frozen key still exists in the current catalog', function () {
    foreach ([...RoleBackfill::GRANTABLE_AT_ROLLOUT, ...RoleBackfill::STAFF_PARITY] as $key) {
        expect(PermissionCatalog::has($key))->toBeTrue("Frozen key [{$key}] is no longer in the catalog");
    }
});
