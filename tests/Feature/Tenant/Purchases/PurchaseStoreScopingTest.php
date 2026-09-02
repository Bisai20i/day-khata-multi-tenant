<?php

use App\Enums\FiscalYearStatus;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\ItemStockMovement;
use App\Models\Purchase;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionPurchaseStoreScopingTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function purchaseStoreScopingActor(): User
{
    return User::factory()->create();
}

function purchaseStoreScopingOpenFiscalYear(): void
{
    FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
}

test('posting a purchase with an explicit store_id records the stock movement against that store', function () {
    $tenant = provisionPurchaseStoreScopingTestTenant('purchase-store-explicit.tenant-test');

    $tenant->run(function () {
        purchaseStoreScopingOpenFiscalYear();
        $actor = purchaseStoreScopingActor();
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => true]);
        $branch = Store::factory()->create(['name' => 'Branch Store']);

        $purchase = Purchase::post(
            ['supplier_id' => $supplier->id, 'store_id' => $branch->id, 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 4, 'rate' => 100]],
            $actor,
        );

        expect($purchase->store_id)->toBe($branch->id);

        $movement = ItemStockMovement::where('item_id', $item->id)->firstOrFail();
        expect($movement->store_id)->toBe($branch->id);
    });

    $tenant->delete();
});

test('omitting store_id on a purchase falls back to the tenant default (only) active store', function () {
    $tenant = provisionPurchaseStoreScopingTestTenant('purchase-store-fallback.tenant-test');

    $tenant->run(function () {
        purchaseStoreScopingOpenFiscalYear();
        $actor = purchaseStoreScopingActor();
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => true]);

        // TenantDatabaseSeeder already seeds one active "Main Store" for
        // every provisioned tenant - this is the fallback target.
        $mainStore = Store::where('is_active', true)->orderBy('id')->firstOrFail();

        $purchase = Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100]],
            $actor,
        );

        expect($purchase->store_id)->toBe($mainStore->id);

        $movement = ItemStockMovement::where('item_id', $item->id)->firstOrFail();
        expect($movement->store_id)->toBe($mainStore->id);
    });

    $tenant->delete();
});

test('an inactive-only store setup rejects a purchase with no active store to fall back to', function () {
    $tenant = provisionPurchaseStoreScopingTestTenant('purchase-store-none-active.tenant-test');

    $tenant->run(function () {
        purchaseStoreScopingOpenFiscalYear();
        $actor = purchaseStoreScopingActor();
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => true]);

        // Deactivate every seeded store so the fallback lookup finds none.
        Store::query()->update(['is_active' => false]);

        expect(fn () => Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100]],
            $actor,
        ))->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('Item::currentStock($storeId) returns only that store net quantity, and null returns the cross-store total', function () {
    $tenant = provisionPurchaseStoreScopingTestTenant('purchase-store-current-stock.tenant-test');

    $tenant->run(function () {
        purchaseStoreScopingOpenFiscalYear();
        $actor = purchaseStoreScopingActor();
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => true]);
        $mainStore = Store::where('is_active', true)->orderBy('id')->firstOrFail();
        $branch = Store::factory()->create(['name' => 'Branch Store']);

        Purchase::post(
            ['supplier_id' => $supplier->id, 'store_id' => $mainStore->id, 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 6, 'rate' => 100]],
            $actor,
        );

        Purchase::post(
            ['supplier_id' => $supplier->id, 'store_id' => $branch->id, 'date' => '2026-06-02', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 4, 'rate' => 100]],
            $actor,
        );

        expect($item->fresh()->currentStock($mainStore->id))->toBe(6.0)
            ->and($item->fresh()->currentStock($branch->id))->toBe(4.0)
            ->and($item->fresh()->currentStock())->toBe(10.0);
    });

    $tenant->delete();
});
