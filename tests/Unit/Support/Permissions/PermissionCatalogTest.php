<?php

use App\Support\Permissions\PermissionCatalog;
use Tests\TestCase;

/**
 * Role JSON stores these keys forever, so the catalog's invariants are
 * pinned here: a permission pointing at a module that does not exist would be
 * ungrantable, and a requires cycle would hang or mis-resolve a tenant's
 * module set. Unit tests do not boot Laravel by default (tests/Pest.php only
 * extends TestCase for Feature), so this file opts in for config().
 */
uses(TestCase::class);

beforeEach(fn () => PermissionCatalog::flush());
afterEach(fn () => PermissionCatalog::flush());

test('every permission references a known module', function () {
    $modules = array_keys(PermissionCatalog::modules());

    foreach (PermissionCatalog::permissions() as $key => $def) {
        expect($modules)->toContain($def['module']);
    }
});

test('every requires entry references a known module and the graph is acyclic', function () {
    $modules = PermissionCatalog::modules();

    foreach ($modules as $key => $def) {
        foreach ($def['requires'] as $required) {
            expect($modules)->toHaveKey($required);
        }
    }

    $visiting = [];
    $done = [];
    $visit = function (string $key) use (&$visit, &$visiting, &$done, $modules): void {
        expect($visiting)->not->toHaveKey($key);
        if (isset($done[$key])) {
            return;
        }
        $visiting[$key] = true;
        foreach ($modules[$key]['requires'] as $required) {
            $visit($required);
        }
        unset($visiting[$key]);
        $done[$key] = true;
    };
    foreach (array_keys($modules) as $key) {
        $visit($key);
    }
});

test('owner-only keys are exactly the four agreed', function () {
    expect(PermissionCatalog::ownerOnly())->toEqualCanonicalizing([
        'roles.manage',
        'backups.manage',
        'fiscal_year.close_archive',
        'ownership.transfer',
    ]);
});

test('core is always on', function () {
    expect(PermissionCatalog::modules()['core']['always_on'])->toBeTrue();
});

test('default_modules is every non-core module', function () {
    $nonCore = array_values(array_diff(array_keys(PermissionCatalog::modules()), ['core']));

    expect(config('permissions.default_modules'))->toEqualCanonicalizing($nonCore);
});

test('resolveModules pulls in requires and always-on modules', function () {
    $resolved = PermissionCatalog::resolveModules(['pos']);

    expect($resolved)->toContain('pos', 'sales', 'core');
});

test('resolveModules ignores unknown module keys', function () {
    expect(PermissionCatalog::resolveModules(['nope', 'core']))->not->toContain('nope');
});

test('resolveModules of nothing is only the always-on modules', function () {
    $alwaysOn = array_keys(array_filter(
        PermissionCatalog::modules(),
        fn (array $def): bool => $def['always_on'],
    ));

    expect(PermissionCatalog::resolveModules([]))->toBe($alwaysOn)
        ->and(PermissionCatalog::resolveModules([]))->toContain('core');
});

test('resolveModules follows config order regardless of input order', function () {
    expect(PermissionCatalog::resolveModules(['pos', 'sales']))
        ->toBe(PermissionCatalog::resolveModules(['sales', 'pos']));
});

test('no legacy key is claimed by two permissions', function () {
    $legacy = [];
    foreach (PermissionCatalog::permissions() as $def) {
        array_push($legacy, ...$def['legacy']);
    }

    expect($legacy)->toBe(array_values(array_unique($legacy)));
});

test('every permission key is resource.action in snake case', function () {
    foreach (array_keys(PermissionCatalog::permissions()) as $key) {
        expect($key)->toMatch('/^[a-z_]+\.[a-z_]+$/');
    }
});

test('grantable excludes owner-only and has/moduleOf/isOwnerOnly agree', function () {
    $grantable = PermissionCatalog::grantable();

    foreach (PermissionCatalog::ownerOnly() as $key) {
        expect($grantable)->not->toContain($key)
            ->and(PermissionCatalog::isOwnerOnly($key))->toBeTrue();
    }
    expect(count($grantable) + count(PermissionCatalog::ownerOnly()))->toBe(count(PermissionCatalog::permissions()))
        ->and(PermissionCatalog::has('roles.manage'))->toBeTrue()
        ->and(PermissionCatalog::has('nope.nope'))->toBeFalse()
        ->and(PermissionCatalog::moduleOf('nope.nope'))->toBeNull()
        ->and(PermissionCatalog::isOwnerOnly('nope.nope'))->toBeFalse();
});

test('keysForModules core contains no key from another module', function () {
    foreach (PermissionCatalog::keysForModules(['core']) as $key) {
        expect(PermissionCatalog::moduleOf($key))->toBe('core');
    }
});

test('grouped lists every permission once under its own module', function () {
    $seen = [];
    foreach (PermissionCatalog::grouped() as $module) {
        foreach ($module['groups'] as $group) {
            foreach ($group['permissions'] as $permission) {
                expect(PermissionCatalog::moduleOf($permission['key']))->toBe($module['module']);
                $seen[] = $permission['key'];
            }
        }
    }

    expect($seen)->toEqualCanonicalizing(array_keys(PermissionCatalog::permissions()));
});

describe('with a fixture config', function () {
    beforeEach(function () {
        config()->set('permissions', [
            'modules' => [
                'core' => ['label' => 'Core', 'always_on' => true],
                'c' => ['label' => 'C'],
                'b' => ['label' => 'B', 'requires' => ['c']],
                'a' => ['label' => 'A', 'requires' => ['b']],
                'x' => ['label' => 'X', 'requires' => ['y']],
                'y' => ['label' => 'Y', 'requires' => ['x']],
            ],
            'permissions' => [
                'core.view' => ['module' => 'core', 'group' => 'G1', 'label' => 'View'],
                'a.edit' => ['module' => 'a', 'group' => 'G2', 'label' => 'Edit', 'legacy' => ['EditA']],
                'a.manage' => ['module' => 'a', 'group' => 'G1', 'label' => 'Manage', 'owner_only' => true],
                'a.print' => ['module' => 'a', 'group' => 'G2', 'label' => 'Print'],
            ],
        ]);
        PermissionCatalog::flush();
    });

    test('requires resolve transitively in config order', function () {
        expect(PermissionCatalog::resolveModules(['a']))->toBe(['core', 'c', 'b', 'a']);
    });

    test('a requires cycle terminates and includes both members', function () {
        expect(PermissionCatalog::resolveModules(['x']))->toBe(['core', 'x', 'y']);
    });

    test('optional flags default and keysForModules merges per module', function () {
        expect(PermissionCatalog::permissions()['core.view'])->toMatchArray(['owner_only' => false, 'legacy' => []])
            ->and(PermissionCatalog::keysForModules(['a', 'core']))->toBe(['a.edit', 'a.manage', 'a.print', 'core.view'])
            ->and(PermissionCatalog::keysForModules(['zzz']))->toBe([])
            ->and(PermissionCatalog::grantable())->toBe(['core.view', 'a.edit', 'a.print']);
    });

    test('grouped nests modules, groups in first-seen order, then permissions', function () {
        $grouped = PermissionCatalog::grouped();

        expect(array_column($grouped, 'module'))->toBe(['core', 'a'])
            ->and(array_column($grouped[1]['groups'], 'group'))->toBe(['G2', 'G1'])
            ->and($grouped[1]['groups'][0]['permissions'])->toBe([
                ['key' => 'a.edit', 'label' => 'Edit', 'owner_only' => false],
                ['key' => 'a.print', 'label' => 'Print', 'owner_only' => false],
            ]);
    });
});
