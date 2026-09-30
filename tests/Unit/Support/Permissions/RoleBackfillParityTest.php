<?php

use App\Support\Permissions\PermissionCatalog;
use App\Support\Permissions\RoleBackfill;
use Tests\TestCase;

/**
 * Rollout parity checks (P14). The legacy admin/staff/inactive-admin fixture
 * backfill is covered by tests/Feature/Tenant/Authorization/RoleBackfillTest.php
 * and the dry run by tests/Feature/Console/PermissionsOwnerDryRunTest.php.
 */
uses(TestCase::class);

beforeEach(fn () => PermissionCatalog::flush());
afterEach(fn () => PermissionCatalog::flush());

test('the frozen staff list contains no owner-only key and only catalog keys', function () {
    expect(array_intersect(RoleBackfill::STAFF_PARITY, PermissionCatalog::ownerOnly()))->toBe([]);

    foreach (RoleBackfill::STAFF_PARITY as $key) {
        expect(PermissionCatalog::has($key))->toBeTrue("Staff key [{$key}] is not in the catalog");
    }
});

test('the frozen admin list contains no owner-only key and only catalog keys', function () {
    expect(array_intersect(RoleBackfill::GRANTABLE_AT_ROLLOUT, PermissionCatalog::ownerOnly()))->toBe([]);

    foreach (RoleBackfill::GRANTABLE_AT_ROLLOUT as $key) {
        expect(PermissionCatalog::has($key))->toBeTrue("Admin key [{$key}] is not in the catalog");
    }
});

test('the frozen admin list equals the grantable catalog keys at rollout', function () {
    // Expected to break when the catalog grows (a new grantable key): the
    // frozen list must NOT change then. Replace this with a subset assertion
    // (frozen list is a subset of PermissionCatalog::grantable()).
    expect(RoleBackfill::GRANTABLE_AT_ROLLOUT)->toEqualCanonicalizing(PermissionCatalog::grantable());
});
