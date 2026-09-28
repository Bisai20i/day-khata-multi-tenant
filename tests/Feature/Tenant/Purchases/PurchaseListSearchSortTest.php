<?php

use App\Enums\FiscalYearStatus;
use App\Exports\PurchaseListExport;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Covers the supplier bill number search, server-side sort and CSV export
 * added to PurchaseController::index()/export() so the purchases list
 * matches the sales list (Sales/Index.vue).
 */
uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

/**
 * Tenant with three purchases: bill B-100 for 300, B-200 for 100, X-300 for 200.
 */
function provisionPurchaseListSearchSortTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $admin = User::factory()->create();
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        foreach ([['B-100', '2026-06-01', 300], ['B-200', '2026-06-02', 100], ['X-300', '2026-06-03', 200]] as [$billNumber, $date, $rate]) {
            Purchase::post(
                ['supplier_id' => $supplier->id, 'bill_number' => $billNumber, 'date' => $date, 'payment_mode' => 'credit'],
                [['item_id' => $item->id, 'quantity' => 1, 'rate' => $rate, 'discount' => 0]],
                $admin,
            );
        }
    });

    test()->post("http://{$domain}/login", ['email' => 'owner@example.com', 'password' => 'password']);

    return $tenant;
}

test('the bill number search narrows the purchases list to a partial match and narrows the totals with it', function () {
    $domain = 'purchase-search-bill.tenant-test';
    $tenant = provisionPurchaseListSearchSortTenant($domain);

    $this->get("http://{$domain}/purchases?".http_build_query(['search' => 'b-']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('purchases.total', 2)
            ->where('filters.search', 'b-')
            ->where('totals.total', '400.00'));

    $tenant->delete();
});

test('sorting by total ascending orders the whole filtered set by amount', function () {
    $domain = 'purchase-sort-total.tenant-test';
    $tenant = provisionPurchaseListSearchSortTenant($domain);

    $this->get("http://{$domain}/purchases?".http_build_query(['sort' => 'total', 'sort_dir' => 'asc']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('filters.sort', 'total')
            ->where('filters.sort_dir', 'asc')
            ->where('purchases.data.0.bill_number', 'B-200')
            ->where('purchases.data.1.bill_number', 'X-300')
            ->where('purchases.data.2.bill_number', 'B-100'));

    $tenant->delete();
});

test('the default order is newest first and an unknown sort column falls back to date', function () {
    $domain = 'purchase-sort-invalid.tenant-test';
    $tenant = provisionPurchaseListSearchSortTenant($domain);

    $this->get("http://{$domain}/purchases?".http_build_query(['sort' => 'id; DROP TABLE purchases;', 'sort_dir' => 'sideways']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('filters.sort', 'date')
            ->where('filters.sort_dir', 'desc')
            ->where('purchases.data.0.bill_number', 'X-300'));

    $tenant->delete();
});

test('the purchases export honours the search and streams a csv when format=csv is requested', function () {
    Excel::fake();

    $domain = 'purchase-export-csv.tenant-test';
    $tenant = provisionPurchaseListSearchSortTenant($domain);

    $this->get("http://{$domain}/purchases/export?".http_build_query(['search' => 'X-3', 'format' => 'csv']))->assertOk();

    Excel::assertDownloaded('purchases.csv', function (PurchaseListExport $export) {
        // One matching bill plus the trailing total row.
        return $export->collection()->count() === 2
            && $export->collection()->first()['bill_number'] === 'X-300'
            && $export->collection()->last()['total'] === '200.00';
    });

    $tenant->delete();
});

test('a cancelled purchase stays in the list but is left out of the totals row and the export total', function () {
    Excel::fake();

    $domain = 'purchase-totals-cancelled.tenant-test';
    $tenant = provisionPurchaseListSearchSortTenant($domain);

    $tenant->run(function () {
        Purchase::where('bill_number', 'B-100')->firstOrFail()->cancel(User::where('email', '!=', 'owner@example.com')->firstOrFail(), 'Entered twice');
    });

    $this->get("http://{$domain}/purchases")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('purchases.total', 3)
            ->where('totals.total', '300.00'));

    $this->get("http://{$domain}/purchases/export")->assertOk();

    Excel::assertDownloaded('purchases.xlsx', fn (PurchaseListExport $export) => $export->collection()->last()['total'] === '300.00');

    $tenant->delete();
});
