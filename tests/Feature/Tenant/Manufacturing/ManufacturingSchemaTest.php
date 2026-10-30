<?php

use App\Enums\InventoryClass;
use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\Item;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionManufacturingSchemaTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

/**
 * Where each manufacturing account must sit: [group name, subgroup name|null].
 */
function expectedManufacturingPlacement(): array
{
    return [
        'AS12' => ['Current Assets', 'Stock'],
        'AS13' => ['Current Assets', 'Stock'],
        'AS14' => ['Current Assets', 'Stock'],
        'EXE10' => ['Purchase Accounts', null],
        'EXE23' => ['Indirect Expenses', null],
        'EXE24' => ['Indirect Expenses', null],
    ];
}

test('a freshly provisioned tenant has the six manufacturing accounts under the right groups', function () {
    $tenant = provisionManufacturingSchemaTenant('mfg-seeded.tenant-test');

    $tenant->run(function () {
        foreach (expectedManufacturingPlacement() as $code => [$groupName, $subgroupName]) {
            $accounts = Account::where('code', $code)->get();
            expect($accounts)->toHaveCount(1);

            $account = $accounts->first();
            $groupId = $subgroupName === null ? $account->account_group_id : $account->subgroup->account_group_id;
            expect($account->subgroup?->name)->toBe($subgroupName)
                ->and(AccountGroup::find($groupId)->name)->toBe($groupName);
        }

        foreach (array_keys(expectedManufacturingPlacement()) as $code) {
            expect(Account::SYSTEM_CODES)->toContain($code);
        }
    });
});

test('the accounts migration creates missing accounts once and is idempotent', function () {
    $tenant = provisionManufacturingSchemaTenant('mfg-migration.tenant-test');

    $tenant->run(function () {
        Account::whereIn('code', array_keys(expectedManufacturingPlacement()))->delete();
        expect(Account::whereIn('code', array_keys(expectedManufacturingPlacement()))->count())->toBe(0);

        $migration = require database_path('migrations/tenant/2026_10_25_100000_add_manufacturing_accounts_to_chart_of_accounts.php');

        $migration->up();
        $migration->up();

        foreach (expectedManufacturingPlacement() as $code => [$groupName, $subgroupName]) {
            expect(Account::where('code', $code)->count())->toBe(1);
            expect(Account::where('code', $code)->first()->name)->not->toBe('');
        }

        $migration->down();
        expect(Account::whereIn('code', array_keys(expectedManufacturingPlacement()))->count())->toBe(0);
    });
});

test('the schema migrations created the manufacturing tables and columns', function () {
    $tenant = provisionManufacturingSchemaTenant('mfg-tables.tenant-test');

    $tenant->run(function () {
        foreach (['material_issue_notes', 'material_issue_items', 'production_entries', 'production_direct_costs', 'production_completions', 'production_wip_entries', 'fiscal_year_inventory_snapshots'] as $table) {
            expect(Schema::hasTable($table))->toBeTrue();
        }

        expect(Schema::hasColumn('items', 'inventory_class'))->toBeTrue()
            ->and(Schema::hasColumn('item_stock_movements', 'cost_value'))->toBeTrue();
    });
});

test('items default to trading and the factory states set the perpetual classes', function () {
    $tenant = provisionManufacturingSchemaTenant('mfg-item.tenant-test');

    $tenant->run(function () {
        $trading = Item::factory()->create()->fresh();
        $raw = Item::factory()->rawMaterial()->create()->fresh();
        $finished = Item::factory()->finishedGood()->create()->fresh();

        expect($trading->inventory_class)->toBe(InventoryClass::Trading)
            ->and($trading->isPerpetual())->toBeFalse()
            ->and($trading->inventoryAccountId())->toBeNull()
            ->and($raw->inventory_class)->toBe(InventoryClass::RawMaterial)
            ->and($raw->isPerpetual())->toBeTrue()
            ->and($raw->inventoryAccountId())->toBe(Account::where('code', 'AS12')->value('id'))
            ->and($finished->inventoryAccountId())->toBe(Account::where('code', 'AS14')->value('id'));
    });
});

test('inventoryAccountId throws a clear message when the account is missing', function () {
    $tenant = provisionManufacturingSchemaTenant('mfg-missing.tenant-test');

    $tenant->run(function () {
        $raw = Item::factory()->rawMaterial()->create()->fresh();
        Account::where('code', 'AS12')->delete();

        expect(fn () => $raw->inventoryAccountId())
            ->toThrow(RuntimeException::class, 'Run the tenant migrations');
    });
});
