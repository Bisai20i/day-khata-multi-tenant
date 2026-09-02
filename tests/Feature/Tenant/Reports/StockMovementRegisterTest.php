<?php

use App\Enums\FiscalYearStatus;
use App\Enums\StockMovementType;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionStockMovementRegisterTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function loginStockMovementRegisterTestUser(string $domain): void
{
    test()->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);
}

function stockMovementRegisterOpenFiscalYear(): FiscalYear
{
    return FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
}

test('a sale shows up with a negative signed quantity and a purchase with a positive one', function () {
    $domain = 'stock-movement-register-signs.tenant-test';
    $tenant = provisionStockMovementRegisterTestTenant($domain);

    $tenant->run(function () {
        stockMovementRegisterOpenFiscalYear();
        $admin = User::factory()->create(['email' => 'owner@example.com']);
        $customer = Customer::factory()->create();
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => true]);

        Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 10, 'rate' => 50, 'discount' => 0]],
            $admin,
        );

        Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-02', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 4, 'rate' => 80, 'discount' => 0]],
            $admin,
        );
    });

    loginStockMovementRegisterTestUser($domain);

    $this->get("http://{$domain}/reports/stock-movement-register?from=2026-06-01&to=2026-06-30")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Reports/StockMovementRegister')
            ->has('movements', 2)
            ->where('movements.0.movementType', 'Purchase')
            ->where('movements.0.quantity', 10)
            ->where('movements.1.movementType', 'Sale')
            ->where('movements.1.quantity', -4)
        );

    $tenant->delete();
});

test('a cancelled movement is excluded from the register', function () {
    $domain = 'stock-movement-register-cancelled.tenant-test';
    $tenant = provisionStockMovementRegisterTestTenant($domain);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);
        $item = Item::factory()->create(['is_stockable' => true]);
        $storeId = Store::where('is_active', true)->orderBy('id')->firstOrFail()->id;

        $item->stockMovements()->create([
            'store_id' => $storeId,
            'movement_type' => StockMovementType::AdjustmentIn,
            'quantity' => 25,
            'date' => '2026-06-10',
            'cancelled' => true,
        ]);
    });

    loginStockMovementRegisterTestUser($domain);

    $this->get("http://{$domain}/reports/stock-movement-register?from=2026-06-01&to=2026-06-30")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('movements', 0));

    $tenant->delete();
});

test('a movement outside the date range is excluded from the register', function () {
    $domain = 'stock-movement-register-daterange.tenant-test';
    $tenant = provisionStockMovementRegisterTestTenant($domain);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);
        $item = Item::factory()->create(['is_stockable' => true]);
        $storeId = Store::where('is_active', true)->orderBy('id')->firstOrFail()->id;

        $item->recordStockMovement(StockMovementType::AdjustmentIn, 5, '2026-07-15', $storeId);
    });

    loginStockMovementRegisterTestUser($domain);

    $this->get("http://{$domain}/reports/stock-movement-register?from=2026-06-01&to=2026-06-30")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('movements', 0));

    $tenant->delete();
});

test('the register can be narrowed to a single item', function () {
    $domain = 'stock-movement-register-item-filter.tenant-test';
    $tenant = provisionStockMovementRegisterTestTenant($domain);

    $itemAId = null;
    $tenant->run(function () use (&$itemAId) {
        User::factory()->create(['email' => 'owner@example.com']);
        $itemA = Item::factory()->create(['name' => 'Item A', 'is_stockable' => true]);
        $itemB = Item::factory()->create(['name' => 'Item B', 'is_stockable' => true]);
        $storeId = Store::where('is_active', true)->orderBy('id')->firstOrFail()->id;

        $itemA->recordStockMovement(StockMovementType::AdjustmentIn, 5, '2026-06-05', $storeId);
        $itemB->recordStockMovement(StockMovementType::AdjustmentIn, 7, '2026-06-06', $storeId);

        $itemAId = $itemA->id;
    });

    loginStockMovementRegisterTestUser($domain);

    $this->get("http://{$domain}/reports/stock-movement-register?from=2026-06-01&to=2026-06-30&item_id={$itemAId}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('movements', 1)
            ->where('movements.0.itemName', 'Item A')
        );

    $tenant->delete();
});

test('movements are sorted chronologically regardless of creation order', function () {
    $domain = 'stock-movement-register-sort-order.tenant-test';
    $tenant = provisionStockMovementRegisterTestTenant($domain);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);
        $item = Item::factory()->create(['is_stockable' => true]);
        $storeId = Store::where('is_active', true)->orderBy('id')->firstOrFail()->id;

        // Created out of chronological order on purpose.
        $item->recordStockMovement(StockMovementType::AdjustmentIn, 3, '2026-06-20', $storeId);
        $item->recordStockMovement(StockMovementType::AdjustmentIn, 1, '2026-06-05', $storeId);
        $item->recordStockMovement(StockMovementType::AdjustmentIn, 2, '2026-06-10', $storeId);
    });

    loginStockMovementRegisterTestUser($domain);

    $this->get("http://{$domain}/reports/stock-movement-register?from=2026-06-01&to=2026-06-30")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('movements', 3)
            ->where('movements.0.date', '2026-06-05')
            ->where('movements.1.date', '2026-06-10')
            ->where('movements.2.date', '2026-06-20')
        );

    $tenant->delete();
});

test('the register can be narrowed to a single store, carries the store name on every row, and lists every store when unfiltered', function () {
    $domain = 'stock-movement-register-store-filter.tenant-test';
    $tenant = provisionStockMovementRegisterTestTenant($domain);

    $branchStoreId = null;
    $branchStoreName = null;
    $tenant->run(function () use (&$branchStoreId, &$branchStoreName) {
        User::factory()->create(['email' => 'owner@example.com']);
        $item = Item::factory()->create(['name' => 'Widget', 'is_stockable' => true]);

        $mainStoreId = Store::where('is_active', true)->orderBy('id')->firstOrFail()->id;
        $branch = Store::factory()->create(['name' => 'Branch Store']);
        $branchStoreId = $branch->id;
        $branchStoreName = $branch->name;

        $item->recordStockMovement(StockMovementType::AdjustmentIn, 5, '2026-06-05', $mainStoreId);
        $item->recordStockMovement(StockMovementType::AdjustmentIn, 7, '2026-06-06', $branchStoreId);
    });

    loginStockMovementRegisterTestUser($domain);

    // Filtered to the branch store only: one row, carrying that store's name.
    $this->get("http://{$domain}/reports/stock-movement-register?from=2026-06-01&to=2026-06-30&store_id={$branchStoreId}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('movements', 1)
            ->where('movements.0.quantity', 7)
            ->where('movements.0.storeName', $branchStoreName)
            ->where('storeId', $branchStoreId)
        );

    // Unfiltered: both stores' movements show up, i.e. the pre-store-filter
    // behaviour is unchanged.
    $this->get("http://{$domain}/reports/stock-movement-register?from=2026-06-01&to=2026-06-30")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('movements', 2)
            ->where('storeId', null)
        );

    $tenant->delete();
});
