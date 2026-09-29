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

function provisionItemLedgerTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function loginItemLedgerTestUser(string $domain): void
{
    test()->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);
}

/**
 * History used by most tests below, for one item:
 *   05-01 purchase +10, 05-20 sale -3 (both before the window),
 *   06-05 purchase return -2, 06-10 adjustment in +5, 06-15 sale -4,
 *   06-18 a cancelled adjustment in +100 (must never count),
 *   07-01 purchase +7 (after the window).
 *
 * @return array{itemId: int, saleInvoice: string, customerName: string}
 */
function seedItemLedgerHistory(): array
{
    FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
    $admin = User::factory()->create(['email' => 'owner@example.com']);
    $customer = Customer::factory()->create();
    $supplier = Supplier::factory()->create();
    $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => true]);
    $storeId = Store::where('is_active', true)->orderBy('id')->firstOrFail()->id;

    Purchase::post(
        ['supplier_id' => $supplier->id, 'date' => '2026-05-01', 'payment_mode' => 'cash'],
        [['item_id' => $item->id, 'quantity' => 10, 'rate' => 50, 'discount' => 0]],
        $admin,
    );
    Sale::post(
        ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-05-20', 'payment_mode' => 'cash'],
        [['item_id' => $item->id, 'quantity' => 3, 'rate' => 80, 'discount' => 0]],
        $admin,
    );

    $item->recordStockMovement(StockMovementType::PurchaseReturn, 2, '2026-06-05', $storeId);
    $item->recordStockMovement(StockMovementType::AdjustmentIn, 5, '2026-06-10', $storeId);
    $sale = Sale::post(
        ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-15', 'payment_mode' => 'cash'],
        [['item_id' => $item->id, 'quantity' => 4, 'rate' => 80, 'discount' => 0]],
        $admin,
    );
    $item->stockMovements()->create([
        'store_id' => $storeId,
        'movement_type' => StockMovementType::AdjustmentIn,
        'quantity' => 100,
        'date' => '2026-06-18',
        'cancelled' => true,
    ]);

    Purchase::post(
        ['supplier_id' => $supplier->id, 'date' => '2026-07-01', 'payment_mode' => 'cash'],
        [['item_id' => $item->id, 'quantity' => 7, 'rate' => 50, 'discount' => 0]],
        $admin,
    );

    return ['itemId' => $item->id, 'saleInvoice' => $sale->invoice_number, 'customerName' => $customer->name];
}

test('a window starting mid-history opens at the stock before it and runs a balance per row', function () {
    $domain = 'item-ledger-window.tenant-test';
    $tenant = provisionItemLedgerTestTenant($domain);

    $seeded = null;
    $tenant->run(function () use (&$seeded) {
        $seeded = seedItemLedgerHistory();
    });

    loginItemLedgerTestUser($domain);

    $this->get("http://{$domain}/items/{$seeded['itemId']}/ledger?from=2026-06-01&to=2026-06-30")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Inventory/Items/Ledger')
            ->where('item.id', $seeded['itemId'])
            ->where('from', '2026-06-01')
            ->where('to', '2026-06-30')
            // 10 purchased - 3 sold before the window.
            ->where('openingBalance', '7.0000')
            // The cancelled +100 on 06-18 is neither a row nor in any balance.
            ->has('entries', 3)
            ->where('entries.0.type', 'Purchase Return')
            ->where('entries.0.quantity', '-2.0000')
            ->where('entries.0.balance', '5.0000')
            ->where('entries.1.type', 'Adjustment In')
            ->where('entries.1.quantity', '5.0000')
            ->where('entries.1.balance', '10.0000')
            ->where('entries.2.type', 'Sale')
            ->where('entries.2.quantity', '-4.0000')
            ->where('entries.2.balance', '6.0000')
            ->where('entries.2.reference', "Sale {$seeded['saleInvoice']} · {$seeded['customerName']}")
            ->where('closingBalance', '6.0000')
        );

    $tenant->delete();
});

test('with no dates the ledger defaults to the open fiscal year', function () {
    $domain = 'item-ledger-default.tenant-test';
    $tenant = provisionItemLedgerTestTenant($domain);

    $seeded = null;
    $tenant->run(function () use (&$seeded) {
        $seeded = seedItemLedgerHistory();
    });

    loginItemLedgerTestUser($domain);

    $this->get("http://{$domain}/items/{$seeded['itemId']}/ledger")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('from', '2026-01-01')
            ->where('to', '2026-12-31')
            ->where('openingBalance', '0.0000')
            ->has('entries', 6)
            // 10 - 3 - 2 + 5 - 4 + 7
            ->where('closingBalance', '13.0000')
        );

    $tenant->delete();
});

test('an empty window still carries the opening balance through to closing', function () {
    $domain = 'item-ledger-empty.tenant-test';
    $tenant = provisionItemLedgerTestTenant($domain);

    $seeded = null;
    $tenant->run(function () use (&$seeded) {
        $seeded = seedItemLedgerHistory();
    });

    loginItemLedgerTestUser($domain);

    $this->get("http://{$domain}/items/{$seeded['itemId']}/ledger?from=2026-08-01&to=2026-08-31")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('entries', 0)
            ->where('openingBalance', '13.0000')
            ->where('closingBalance', '13.0000')
        );

    $tenant->delete();
});

test('a store filter narrows both the rows and the opening balance', function () {
    $domain = 'item-ledger-store.tenant-test';
    $tenant = provisionItemLedgerTestTenant($domain);

    $itemId = null;
    $otherStoreId = null;
    $tenant->run(function () use (&$itemId, &$otherStoreId) {
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        User::factory()->create(['email' => 'owner@example.com']);
        $item = Item::factory()->create(['is_stockable' => true]);
        $mainStoreId = Store::where('is_active', true)->orderBy('id')->firstOrFail()->id;
        $otherStoreId = Store::create(['name' => 'Branch', 'is_active' => true])->id;

        $item->recordStockMovement(StockMovementType::AdjustmentIn, 20, '2026-05-01', $mainStoreId);
        $item->recordStockMovement(StockMovementType::AdjustmentIn, 4, '2026-05-01', $otherStoreId);
        $item->recordStockMovement(StockMovementType::AdjustmentOut, 1, '2026-06-10', $otherStoreId);
        $item->recordStockMovement(StockMovementType::AdjustmentOut, 9, '2026-06-10', $mainStoreId);
        $itemId = $item->id;
    });

    loginItemLedgerTestUser($domain);

    $this->get("http://{$domain}/items/{$itemId}/ledger?from=2026-06-01&to=2026-06-30&store_id={$otherStoreId}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('storeId', $otherStoreId)
            ->where('openingBalance', '4.0000')
            ->has('entries', 1)
            ->where('entries.0.storeName', 'Branch')
            ->where('closingBalance', '3.0000')
        );

    $tenant->delete();
});

test('a to date before the from date is a validation error', function () {
    $domain = 'item-ledger-invalid.tenant-test';
    $tenant = provisionItemLedgerTestTenant($domain);

    $itemId = null;
    $tenant->run(function () use (&$itemId) {
        User::factory()->create(['email' => 'owner@example.com']);
        $itemId = Item::factory()->create()->id;
    });

    loginItemLedgerTestUser($domain);

    $this->get("http://{$domain}/items/{$itemId}/ledger?from=2026-06-30&to=2026-06-01")
        ->assertSessionHasErrors('to');

    $tenant->delete();
});

test('print and export return a PDF and a spreadsheet', function () {
    $domain = 'item-ledger-print.tenant-test';
    $tenant = provisionItemLedgerTestTenant($domain);

    $seeded = null;
    $tenant->run(function () use (&$seeded) {
        $seeded = seedItemLedgerHistory();
    });

    loginItemLedgerTestUser($domain);

    $this->get("http://{$domain}/items/{$seeded['itemId']}/ledger/print?from=2026-06-01&to=2026-06-30")
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    $this->get("http://{$domain}/items/{$seeded['itemId']}/ledger/export?from=2026-06-01&to=2026-06-30")
        ->assertOk()
        ->assertDownload("item-ledger-{$seeded['itemId']}.xlsx");

    $tenant->delete();
});
