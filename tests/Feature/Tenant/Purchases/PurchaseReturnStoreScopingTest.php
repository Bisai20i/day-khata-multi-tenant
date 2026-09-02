<?php

use App\Enums\FiscalYearStatus;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\ItemStockMovement;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnLine;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionPurchaseReturnStoreScopingTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function purchaseReturnStoreScopingActor(): User
{
    return User::factory()->create();
}

function purchaseReturnStoreScopingOpenFiscalYear(): void
{
    FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
}

test('posting a purchase return with an explicit store_id records the return movement against that store', function () {
    $tenant = provisionPurchaseReturnStoreScopingTenant('purchase-return-store-explicit.tenant-test');

    $tenant->run(function () {
        purchaseReturnStoreScopingOpenFiscalYear();
        $actor = purchaseReturnStoreScopingActor();
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => true]);
        $secondStore = Store::factory()->create(['is_active' => true]);

        $purchase = Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 10, 'rate' => 100]],
            $actor,
        );
        $purchaseLine = $purchase->lines()->firstOrFail();

        $return = PurchaseReturn::post(
            ['purchase_id' => $purchase->id, 'date' => '2026-06-05', 'reason' => 'Damaged goods', 'store_id' => $secondStore->id],
            [['purchase_line_id' => $purchaseLine->id, 'quantity' => 4]],
            $actor,
        );

        expect($return->store_id)->toBe($secondStore->id);

        $movement = ItemStockMovement::where('item_id', $item->id)
            ->where('reference_type', (new PurchaseReturnLine)->getMorphClass())
            ->firstOrFail();
        expect($movement->store_id)->toBe($secondStore->id);
    });

    $tenant->delete();
});

test('omitting store_id on a purchase return falls back to the default active store', function () {
    $tenant = provisionPurchaseReturnStoreScopingTenant('purchase-return-store-fallback.tenant-test');

    $tenant->run(function () {
        purchaseReturnStoreScopingOpenFiscalYear();
        $actor = purchaseReturnStoreScopingActor();
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => true]);

        Store::factory()->create(['is_active' => true]);
        $defaultStoreId = Store::where('is_active', true)->orderBy('id')->value('id');

        $purchase = Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 5, 'rate' => 10]],
            $actor,
        );
        $purchaseLine = $purchase->lines()->firstOrFail();

        $return = PurchaseReturn::post(
            ['purchase_id' => $purchase->id, 'date' => '2026-06-02', 'reason' => null],
            [['purchase_line_id' => $purchaseLine->id, 'quantity' => 2]],
            $actor,
        );

        expect($return->store_id)->toBe($defaultStoreId);
    });

    $tenant->delete();
});
