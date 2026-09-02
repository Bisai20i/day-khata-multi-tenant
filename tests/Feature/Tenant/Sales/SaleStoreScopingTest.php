<?php

use App\Enums\FiscalYearStatus;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\ItemStockMovement;
use App\Models\Sale;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionSaleStoreScopingTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function saleStoreScopingActor(): User
{
    return User::factory()->create();
}

function saleStoreScopingOpenFiscalYear(): void
{
    FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
}

test('posting a sale with an explicit store_id records the stock movement against that store', function () {
    $tenant = provisionSaleStoreScopingTestTenant('sale-store-explicit.tenant-test');

    $tenant->run(function () {
        saleStoreScopingOpenFiscalYear();
        $actor = saleStoreScopingActor();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => true]);
        $branch = Store::factory()->create(['name' => 'Branch Store']);

        $sale = Sale::post(
            ['customer_id' => $customer->id, 'store_id' => $branch->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 2, 'rate' => 100, 'discount' => 0]],
            $actor,
        );

        expect($sale->store_id)->toBe($branch->id);

        $movement = ItemStockMovement::where('item_id', $item->id)->firstOrFail();
        expect($movement->store_id)->toBe($branch->id);
    });

    $tenant->delete();
});

test('omitting store_id on a sale falls back to the tenant default (only) active store', function () {
    $tenant = provisionSaleStoreScopingTestTenant('sale-store-fallback.tenant-test');

    $tenant->run(function () {
        saleStoreScopingOpenFiscalYear();
        $actor = saleStoreScopingActor();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => true]);

        // TenantDatabaseSeeder already seeds one active "Main Store" for
        // every provisioned tenant - this is the fallback target.
        $mainStore = Store::where('is_active', true)->orderBy('id')->firstOrFail();

        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $actor,
        );

        expect($sale->store_id)->toBe($mainStore->id);

        $movement = ItemStockMovement::where('item_id', $item->id)->firstOrFail();
        expect($movement->store_id)->toBe($mainStore->id);
    });

    $tenant->delete();
});

test('an inactive-only store setup rejects a sale with no active store to fall back to', function () {
    $tenant = provisionSaleStoreScopingTestTenant('sale-store-none-active.tenant-test');

    $tenant->run(function () {
        saleStoreScopingOpenFiscalYear();
        $actor = saleStoreScopingActor();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => true]);

        // Deactivate every seeded store so the fallback lookup finds none.
        Store::query()->update(['is_active' => false]);

        expect(fn () => Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $actor,
        ))->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('Item::currentStock($storeId) returns only that store net quantity, and null returns the cross-store total', function () {
    $tenant = provisionSaleStoreScopingTestTenant('sale-store-current-stock.tenant-test');

    $tenant->run(function () {
        saleStoreScopingOpenFiscalYear();
        $actor = saleStoreScopingActor();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => true]);
        $mainStore = Store::where('is_active', true)->orderBy('id')->firstOrFail();
        $branch = Store::factory()->create(['name' => 'Branch Store']);

        Sale::post(
            ['customer_id' => $customer->id, 'store_id' => $mainStore->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 3, 'rate' => 100, 'discount' => 0]],
            $actor,
        );

        Sale::post(
            ['customer_id' => $customer->id, 'store_id' => $branch->id, 'invoice_type' => 'full', 'date' => '2026-06-02', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 5, 'rate' => 100, 'discount' => 0]],
            $actor,
        );

        expect($item->fresh()->currentStock($mainStore->id))->toBe(-3.0)
            ->and($item->fresh()->currentStock($branch->id))->toBe(-5.0)
            ->and($item->fresh()->currentStock())->toBe(-8.0);
    });

    $tenant->delete();
});
