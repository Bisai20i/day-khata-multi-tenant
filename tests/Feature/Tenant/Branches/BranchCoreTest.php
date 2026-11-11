<?php

use App\Models\Branch;
use App\Models\Item;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Branches\BranchRuleViolation;
use App\Support\Permissions\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

/**
 * Tenant creation migrates and seeds the tenant database (see the
 * TenantCreated pipeline), so Main and the Main Store already exist.
 */
function provisionBranchCoreTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

/**
 * Give a store a stock movement so it counts as in use.
 */
function branchCoreTestAddMovement(Store $store): void
{
    $item = Item::factory()->create();

    DB::table('item_stock_movements')->insert([
        'item_id' => $item->id,
        'store_id' => $store->id,
        'movement_type' => 'adjustment_in',
        'quantity' => '1.0000',
        'date' => '2026-01-01',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * Give two stores a stock transfer document so both count as in use.
 */
function branchCoreTestAddTransfer(Store $from, Store $to): void
{
    $user = User::factory()->create();

    DB::table('stock_transfers')->insert([
        'date' => '2026-01-01',
        'from_store_id' => $from->id,
        'to_store_id' => $to->id,
        'created_by' => $user->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

test('a fresh tenant has exactly one Main branch and its seeded store belongs to it', function () {
    $tenant = provisionBranchCoreTestTenant('branch-main.tenant-test');

    $tenant->run(function () {
        $mains = Branch::query()->where('is_main', true)->get();

        expect($mains)->toHaveCount(1)
            ->and($mains->first()->name)->toBe('Main')
            ->and($mains->first()->code)->toBe('MAIN')
            ->and($mains->first()->is_active)->toBeTrue()
            ->and(Branch::main()->is($mains->first()))->toBeTrue()
            ->and(Branch::query()->count())->toBe(1)
            ->and(Store::query()->where('name', 'Main Store')->firstOrFail()->branch_id)->toBe($mains->first()->id);
    });

    $tenant->delete();
});

test('ensureMain is idempotent and a second Main cannot be created', function () {
    $tenant = provisionBranchCoreTestTenant('branch-ensure-main.tenant-test');

    $tenant->run(function () {
        $main = Branch::main();

        expect(Branch::ensureMain()->is($main))->toBeTrue()
            ->and(Branch::query()->where('is_main', true)->count())->toBe(1);

        expect(fn () => Branch::factory()->create(['is_main' => true]))
            ->toThrow(BranchRuleViolation::class);

        expect(Branch::query()->where('is_main', true)->count())->toBe(1);
    });

    $tenant->delete();
});

test('the Main branch cannot be deactivated or stop being Main', function () {
    $tenant = provisionBranchCoreTestTenant('branch-main-guard.tenant-test');

    $tenant->run(function () {
        $main = Branch::main();

        expect(fn () => $main->update(['is_active' => false]))
            ->toThrow(BranchRuleViolation::class, 'Main branch cannot be deactivated');

        expect(fn () => Branch::main()->update(['is_main' => false]))
            ->toThrow(BranchRuleViolation::class);

        expect(Branch::main()->is_active)->toBeTrue();
    });

    $tenant->delete();
});

test('another branch can be deactivated and reactivated', function () {
    $tenant = provisionBranchCoreTestTenant('branch-deactivate.tenant-test');

    $tenant->run(function () {
        $branch = Branch::factory()->create();

        $branch->update(['is_active' => false]);
        expect(Branch::active()->pluck('id')->all())->toBe([Branch::main()->id]);

        $branch->update(['is_active' => true]);
        expect(Branch::active()->count())->toBe(2);
    });

    $tenant->delete();
});

test('no branch can be deleted, including Main', function () {
    $tenant = provisionBranchCoreTestTenant('branch-no-delete.tenant-test');

    $tenant->run(function () {
        $branch = Branch::factory()->create();

        expect(fn () => $branch->delete())->toThrow(BranchRuleViolation::class, 'never deleted');
        expect(fn () => Branch::main()->delete())->toThrow(BranchRuleViolation::class);

        expect(Branch::query()->count())->toBe(2);
    });

    $tenant->delete();
});

test('a store defaults to Main and exposes its branch, and a branch exposes its stores and default store', function () {
    $tenant = provisionBranchCoreTestTenant('branch-store-rel.tenant-test');

    $tenant->run(function () {
        $store = Store::factory()->create();
        $branch = Branch::factory()->create();
        $branchStore = Store::factory()->create(['branch_id' => $branch->id]);
        $branch->update(['default_store_id' => $branchStore->id]);

        expect($store->branch->is(Branch::main()))->toBeTrue()
            ->and($branch->stores->pluck('id')->all())->toBe([$branchStore->id])
            ->and($branch->fresh()->defaultStore->is($branchStore))->toBeTrue();
    });

    $tenant->delete();
});

test('an unused store can change branch', function () {
    $tenant = provisionBranchCoreTestTenant('branch-store-free.tenant-test');

    $tenant->run(function () {
        $store = Store::factory()->create();
        $branch = Branch::factory()->create();

        $store->update(['branch_id' => $branch->id]);

        expect($store->fresh()->branch_id)->toBe($branch->id);
    });

    $tenant->delete();
});

test('a store with a stock movement cannot change branch', function () {
    $tenant = provisionBranchCoreTestTenant('branch-store-movement.tenant-test');

    $tenant->run(function () {
        $store = Store::factory()->create();
        $branch = Branch::factory()->create();
        branchCoreTestAddMovement($store);

        expect(fn () => $store->update(['branch_id' => $branch->id]))
            ->toThrow(BranchRuleViolation::class, 'cannot be changed');

        expect($store->fresh()->branch_id)->toBe(Branch::main()->id);
    });

    $tenant->delete();
});

test('a store referenced by a document on either side cannot change branch', function () {
    $tenant = provisionBranchCoreTestTenant('branch-store-document.tenant-test');

    $tenant->run(function () {
        $from = Store::factory()->create();
        $to = Store::factory()->create();
        $branch = Branch::factory()->create();
        branchCoreTestAddTransfer($from, $to);

        expect(fn () => $from->update(['branch_id' => $branch->id]))->toThrow(BranchRuleViolation::class);
        expect(fn () => $to->update(['branch_id' => $branch->id]))->toThrow(BranchRuleViolation::class);

        $unrelated = Store::factory()->create();
        $unrelated->update(['branch_id' => $branch->id]);
        expect($unrelated->fresh()->branch_id)->toBe($branch->id);
    });

    $tenant->delete();
});

test('a used store can still be edited when its branch is unchanged', function () {
    $tenant = provisionBranchCoreTestTenant('branch-store-edit.tenant-test');

    $tenant->run(function () {
        $store = Store::factory()->create();
        branchCoreTestAddMovement($store);

        $store->update(['name' => 'Renamed store', 'branch_id' => $store->branch_id]);

        expect($store->fresh()->name)->toBe('Renamed store');
    });

    $tenant->delete();
});

test('a used store is blocked from moving to another branch while an unused store moves freely', function () {
    $tenant = provisionBranchCoreTestTenant('branch-store-move.tenant-test');

    $tenant->run(function () {
        $used = Store::factory()->create();
        $unused = Store::factory()->create();
        $other = Branch::factory()->create();
        branchCoreTestAddMovement($used);

        $used->branch_id = $other->id;
        expect(fn () => $used->save())->toThrow(BranchRuleViolation::class, 'cannot be changed');
        expect($used->fresh()->branch_id)->toBe(Branch::main()->id);

        $unused->branch_id = $other->id;
        $unused->save();
        expect($unused->fresh()->branch_id)->toBe($other->id);

        $unused->update(['branch_id' => Branch::main()->id]);
        expect($unused->fresh()->branch_id)->toBe(Branch::main()->id);
    });

    $tenant->delete();
});

test('Store::USAGE_COLUMNS lists every foreign key that points at stores, bar the setting references', function () {
    $tenant = provisionBranchCoreTestTenant('branch-store-usage-drift.tenant-test');

    $tenant->run(function () {
        $settingReferences = ['users.store_id', 'company_settings.default_store_id', 'branches.default_store_id'];
        $found = [];

        foreach (Schema::getTables() as $table) {
            foreach (Schema::getForeignKeys($table['name']) as $foreignKey) {
                if ($foreignKey['foreign_table'] !== 'stores') {
                    continue;
                }

                foreach ($foreignKey['columns'] as $column) {
                    $found[] = $table['name'].'.'.$column;
                }
            }
        }

        $declared = [];
        foreach (Store::USAGE_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                $declared[] = $table.'.'.$column;
            }
        }

        $found = array_values(array_diff($found, $settingReferences));
        sort($found);
        sort($declared);

        expect($found)->not->toBeEmpty()
            ->and($declared)->toBe($found);
    });

    $tenant->delete();
});

test('a user limited to some branches can be assigned branches and a default branch', function () {
    $tenant = provisionBranchCoreTestTenant('branch-user-api.tenant-test');

    $tenant->run(function () {
        $user = User::factory()->create(['all_branches' => false]);
        $branch = Branch::factory()->create();
        $other = Branch::factory()->create();

        $user->branches()->attach([$branch->id, $other->id]);
        $user->forceFill(['default_branch_id' => $branch->id])->save();
        $user = $user->fresh();

        expect($user->all_branches)->toBeFalse()->toBeBool()
            ->and($user->branches->pluck('id')->sort()->values()->all())->toBe([$branch->id, $other->id])
            ->and($user->defaultBranch->is($branch))->toBeTrue();

        $user->forceFill(['all_branches' => true])->save();
        expect($user->fresh()->all_branches)->toBeTrue()->toBeBool();
    });

    $tenant->delete();
});

test('new users default to all branches with no default branch, and branches can be assigned', function () {
    $tenant = provisionBranchCoreTestTenant('branch-users.tenant-test');

    $tenant->run(function () {
        $user = User::factory()->create();
        $row = DB::table('users')->where('id', $user->id)->first();

        expect((bool) $row->all_branches)->toBeTrue()
            ->and($row->default_branch_id)->toBeNull();

        $branch = Branch::factory()->create();
        $branch->users()->attach($user->id);

        expect($branch->users->pluck('id')->all())->toBe([$user->id]);

        expect(fn () => $branch->users()->attach($user->id))->toThrow(Exception::class);
    });

    $tenant->delete();
});

test('the permission catalog knows the branches module and its keys', function () {
    expect(PermissionCatalog::modules())->toHaveKey('branches');

    foreach (['branches.view', 'branches.manage', 'branches.report', 'stock_transfers.receive'] as $key) {
        expect(PermissionCatalog::has($key))->toBeTrue()
            ->and(PermissionCatalog::moduleOf($key))->toBe('branches')
            ->and(PermissionCatalog::isOwnerOnly($key))->toBeFalse();
    }
});
