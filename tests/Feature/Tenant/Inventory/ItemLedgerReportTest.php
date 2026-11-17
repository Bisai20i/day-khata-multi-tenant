<?php

use App\Enums\FiscalYearStatus;
use App\Enums\StockMovementType;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionItemLedgerReportTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function loginItemLedgerReportUser(string $domain, array $permissions = ['stock_reports.view']): User
{
    $role = Role::create([
        'name' => 'Stock Reporter',
        'slug' => 'stock-reporter-'.uniqid(),
        'permissions' => $permissions,
    ]);

    $user = User::factory()->create([
        'email' => 'reporter@example.com',
        'role_id' => $role->id,
    ]);

    test()->post("http://{$domain}/login", [
        'email' => 'reporter@example.com',
        'password' => 'password',
    ]);

    return $user;
}

test('guest cannot access the item ledger report', function () {
    $domain = 'item-ledger-rep-guest.tenant-test';
    $tenant = provisionItemLedgerReportTenant($domain);

    $this->get("http://{$domain}/reports/item-ledger")
        ->assertRedirect("http://{$domain}/login");

    $tenant->delete();
});

test('user without stock_reports.view permission is forbidden', function () {
    $domain = 'item-ledger-rep-forbidden.tenant-test';
    $tenant = provisionItemLedgerReportTenant($domain);

    $tenant->run(function () use ($domain) {
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        loginItemLedgerReportUser($domain, permissions: ['items.view']);
    });

    $this->get("http://{$domain}/reports/item-ledger")
        ->assertForbidden();

    $tenant->delete();
});

test('item ledger report defaults to first active stockable item when no item_id is provided', function () {
    $domain = 'item-ledger-rep-default.tenant-test';
    $tenant = provisionItemLedgerReportTenant($domain);

    $item1Id = null;
    $item2Id = null;

    $tenant->run(function () use ($domain, &$item1Id, &$item2Id) {
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        loginItemLedgerReportUser($domain);

        // Non-stockable or inactive items should not be selected as default
        Item::factory()->create(['name' => 'Service Item', 'is_stockable' => false, 'is_active' => true]);
        Item::factory()->create(['name' => 'AAA Inactive Item', 'is_stockable' => true, 'is_active' => false]);

        $item1 = Item::factory()->create(['name' => 'Apple', 'is_stockable' => true, 'is_active' => true]);
        $item2 = Item::factory()->create(['name' => 'Banana', 'is_stockable' => true, 'is_active' => true]);

        $item1Id = $item1->id;
        $item2Id = $item2->id;
    });

    $this->get("http://{$domain}/reports/item-ledger")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Inventory/Items/Ledger')
            ->where('item.id', $item1Id)
            ->where('item.name', 'Apple')
            ->has('items', 2)
            ->where('items.0.id', $item1Id)
            ->where('items.1.id', $item2Id)
        );

    $tenant->delete();
});

test('item ledger report renders empty state when no items exist in tenant', function () {
    $domain = 'item-ledger-rep-empty.tenant-test';
    $tenant = provisionItemLedgerReportTenant($domain);

    $tenant->run(function () use ($domain) {
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        loginItemLedgerReportUser($domain);
    });

    $this->get("http://{$domain}/reports/item-ledger")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Inventory/Items/Ledger')
            ->where('item', null)
            ->where('openingBalance', '0.0000')
            ->where('closingBalance', '0.0000')
            ->has('items', 0)
            ->has('entries', 0)
        );

    $tenant->delete();
});

test('item ledger report filters by specific item_id, date range, and store', function () {
    $domain = 'item-ledger-rep-filtered.tenant-test';
    $tenant = provisionItemLedgerReportTenant($domain);

    $item1Id = null;
    $item2Id = null;
    $storeId = null;

    $tenant->run(function () use ($domain, &$item1Id, &$item2Id, &$storeId) {
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        loginItemLedgerReportUser($domain);

        $store = Store::where('is_active', true)->firstOrFail();
        $storeId = $store->id;

        $item1 = Item::factory()->create(['name' => 'Widget A', 'is_stockable' => true, 'is_active' => true]);
        $item2 = Item::factory()->create(['name' => 'Widget B', 'is_stockable' => true, 'is_active' => true]);

        $item1Id = $item1->id;
        $item2Id = $item2->id;

        $item2->recordStockMovement(StockMovementType::AdjustmentIn, 15, '2026-06-10', $storeId);
    });

    $this->get("http://{$domain}/reports/item-ledger?item_id={$item2Id}&from=2026-06-01&to=2026-06-30&store_id={$storeId}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Inventory/Items/Ledger')
            ->where('item.id', $item2Id)
            ->where('item.name', 'Widget B')
            ->where('from', '2026-06-01')
            ->where('to', '2026-06-30')
            ->where('storeId', $storeId)
            ->has('entries', 1)
            ->where('entries.0.type', 'Adjustment In')
            ->where('entries.0.quantity', '15.0000')
        );

    $tenant->delete();
});

test('legacy item ledger route /items/{item}/ledger continues to function', function () {
    $domain = 'item-ledger-rep-legacy.tenant-test';
    $tenant = provisionItemLedgerReportTenant($domain);

    $itemId = null;

    $tenant->run(function () use ($domain, &$itemId) {
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        loginItemLedgerReportUser($domain);

        $item = Item::factory()->create(['name' => 'Legacy Item', 'is_stockable' => true, 'is_active' => true]);
        $itemId = $item->id;
    });

    $this->get("http://{$domain}/items/{$itemId}/ledger")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Inventory/Items/Ledger')
            ->where('item.id', $itemId)
            ->where('item.name', 'Legacy Item')
            ->has('items', 1)
        );

    $tenant->delete();
});
