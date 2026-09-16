<?php

use App\Enums\FiscalYearStatus;
use App\Exports\CapitalPurchaseListExport;
use App\Exports\PurchaseListExport;
use App\Exports\PurchaseReturnListExport;
use App\Models\Account;
use App\Models\CapitalPurchase;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;

/**
 * T13 item 8: the purchase, purchase-return and capital-purchase lists each
 * get an exact SQL-summed totals row (never a page's worth of client-side
 * addition) and an Excel export covering the same filtered set, with a
 * trailing "Total" row - same shape SaleController::filteredTotals()/
 * export() already established.
 */
uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionPurchaseListTotalsTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function loginPurchaseListTotalsUser(string $domain): void
{
    test()->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);
}

function purchaseListTotalsOpenFiscalYear(): void
{
    FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
}

test('the purchases list totals row sums exactly the filtered set, and the export matches it with a trailing total row', function () {
    $domain = 'purchase-list-totals.tenant-test';
    $tenant = provisionPurchaseListTotalsTenant($domain);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);
        purchaseListTotalsOpenFiscalYear();
        $admin = User::factory()->create();
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );
        Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-02', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 250, 'discount' => 0]],
            $admin,
        );
    });

    loginPurchaseListTotalsUser($domain);

    $this->get("http://{$domain}/purchases")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('totals.total', '350.00'));

    Excel::fake();

    $this->get("http://{$domain}/purchases/export")->assertOk();

    Excel::assertDownloaded('purchases.xlsx', function (PurchaseListExport $export) {
        return $export->collection()->count() === 3
            && $export->collection()->last()['supplier'] === 'Total'
            && $export->collection()->last()['total'] === '350.00';
    });

    $tenant->delete();
});

test('the purchase returns list totals row and export match the filtered set', function () {
    $domain = 'purchase-return-list-totals.tenant-test';
    $tenant = provisionPurchaseListTotalsTenant($domain);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);
        purchaseListTotalsOpenFiscalYear();
        $admin = User::factory()->create();
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => true]);

        $purchase = Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 5, 'rate' => 100, 'discount' => 0]],
            $admin,
        );
        $line = $purchase->lines()->firstOrFail();

        PurchaseReturn::post(
            ['purchase_id' => $purchase->id, 'date' => '2026-06-05'],
            [['purchase_line_id' => $line->id, 'quantity' => 2]],
            $admin,
        );
    });

    loginPurchaseListTotalsUser($domain);

    $this->get("http://{$domain}/purchase-returns")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('totals.total', '200.00'));

    Excel::fake();

    $this->get("http://{$domain}/purchase-returns/export")->assertOk();

    Excel::assertDownloaded('purchase-returns.xlsx', function (PurchaseReturnListExport $export) {
        return $export->collection()->count() === 2
            && $export->collection()->last()['supplier'] === 'Total'
            && $export->collection()->last()['total'] === '200.00';
    });

    $tenant->delete();
});

test('the capital purchases list totals row and export cover every capital purchase', function () {
    $domain = 'capital-purchase-list-totals.tenant-test';
    $tenant = provisionPurchaseListTotalsTenant($domain);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);
        purchaseListTotalsOpenFiscalYear();
        $admin = User::factory()->create();
        $account = Account::factory()->create();

        CapitalPurchase::post(
            ['type' => 'capital', 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['account_id' => $account->id, 'amount' => 1000, 'vatable' => false]],
            $admin,
        );
        CapitalPurchase::post(
            ['type' => 'capital', 'date' => '2026-06-02', 'payment_mode' => 'credit'],
            [['account_id' => $account->id, 'amount' => 500, 'vatable' => false]],
            $admin,
        );
    });

    loginPurchaseListTotalsUser($domain);

    $this->get("http://{$domain}/capital-purchases")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('totals.total', '1500.00'));

    Excel::fake();

    $this->get("http://{$domain}/capital-purchases/export")->assertOk();

    Excel::assertDownloaded('capital-purchases.xlsx', function (CapitalPurchaseListExport $export) {
        return $export->collection()->count() === 3
            && $export->collection()->last()['supplier'] === 'Total'
            && $export->collection()->last()['total'] === '1500.00';
    });

    $tenant->delete();
});
