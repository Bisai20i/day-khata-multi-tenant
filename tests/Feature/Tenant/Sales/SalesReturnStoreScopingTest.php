<?php

use App\Enums\FiscalYearStatus;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\ItemStockMovement;
use App\Models\Sale;
use App\Models\SaleReturnLine;
use App\Models\SalesReturn;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionSalesReturnStoreScopingTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function salesReturnStoreScopingAdmin(): User
{
    return User::factory()->create();
}

function salesReturnStoreScopingOpenFiscalYear(): void
{
    FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
}

test('posting a sales return with an explicit store_id records the return movement against that store', function () {
    $tenant = provisionSalesReturnStoreScopingTenant('sales-return-store-explicit.tenant-test');

    $tenant->run(function () {
        salesReturnStoreScopingOpenFiscalYear();
        $admin = salesReturnStoreScopingAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => true]);
        $secondStore = Store::factory()->create(['is_active' => true]);

        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 10, 'rate' => 100, 'discount' => 0]],
            $admin,
        );
        $saleLine = $sale->lines()->firstOrFail();

        $return = SalesReturn::post(
            ['sale_id' => $sale->id, 'date' => '2026-06-05', 'reason' => 'Damaged', 'store_id' => $secondStore->id],
            [['sale_line_id' => $saleLine->id, 'quantity' => 4]],
            $admin,
        );

        expect($return->store_id)->toBe($secondStore->id);

        $movement = ItemStockMovement::where('item_id', $item->id)
            ->where('reference_type', (new SaleReturnLine)->getMorphClass())
            ->firstOrFail();
        expect($movement->store_id)->toBe($secondStore->id);
    });

    $tenant->delete();
});

test('omitting store_id on a sales return falls back to the default active store', function () {
    $tenant = provisionSalesReturnStoreScopingTenant('sales-return-store-fallback.tenant-test');

    $tenant->run(function () {
        salesReturnStoreScopingOpenFiscalYear();
        $admin = salesReturnStoreScopingAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => true]);

        Store::factory()->create(['is_active' => true]);
        $defaultStoreId = Store::where('is_active', true)->orderBy('id')->value('id');

        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 5, 'rate' => 10, 'discount' => 0]],
            $admin,
        );
        $saleLine = $sale->lines()->firstOrFail();

        $return = SalesReturn::post(
            ['sale_id' => $sale->id, 'date' => '2026-06-02', 'reason' => null],
            [['sale_line_id' => $saleLine->id, 'quantity' => 2]],
            $admin,
        );

        expect($return->store_id)->toBe($defaultStoreId);
    });

    $tenant->delete();
});
