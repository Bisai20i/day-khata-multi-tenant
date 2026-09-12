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

/**
 * Tenancy has no automatic "end of request" hook outside of a real PHP-FPM
 * style process boundary, so within a single test process the tenant
 * connection stays the default connection after an HTTP call. Revert to the
 * central connection after every test so RefreshDatabase's teardown rolls
 * back the connection it actually started a transaction on.
 */
afterEach(function () {
    tenancy()->end();
});

test('dashboard renders with real kpis, recent customers, and account head breakdown', function () {
    $domain = 'dashboard.tenant-test';
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);

        Customer::factory()->count(2)->create();
        Supplier::factory()->count(1)->create();
        Item::factory()->count(3)->create();
    });

    $this->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);

    $response = $this->get("http://{$domain}/dashboard");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Tenant/Dashboard')
        ->has('kpis.customers.total')
        ->has('kpis.customers.thisWeek')
        ->has('kpis.suppliers.total')
        ->has('kpis.suppliers.thisWeek')
        ->has('kpis.items.total')
        ->has('kpis.items.thisWeek')
        ->has('kpis.accounts.total')
        ->has('kpis.sales.today.count')
        ->has('kpis.sales.today.total')
        ->has('kpis.sales.thisWeek.count')
        ->has('kpis.sales.thisWeek.total')
        ->where('kpis.customers.total', 2)
        ->where('kpis.suppliers.total', 1)
        ->where('kpis.items.total', 3)
        ->where('kpis.sales.today.count', 0)
        ->where('kpis.sales.today.total', '0.00')
        ->has('recentCustomers', 2)
        ->has('recentCustomers.0', fn ($customer) => $customer
            ->has('name')
            ->has('mobile')
            ->has('code')
            ->has('added')
        )
        ->has('recentSales', 0)
        ->has('accountHeadBreakdown')
        ->has('accountHeadBreakdown.0', fn ($head) => $head
            ->has('name')
            ->has('count')
        )
    );

    $tenant->delete();
});

test('dashboard reports the financial snapshot: cash in hand, stock value, debtors, creditors, purchases, tax summary, trends, and top performers', function () {
    $domain = 'dashboard-financials.tenant-test';
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    $tenant->run(function () {
        $admin = User::factory()->create(['email' => 'owner@example.com']);

        FiscalYear::create([
            'name' => 'FY1',
            'start_date' => now()->subYear()->startOfYear(),
            'end_date' => now()->addYear()->endOfYear(),
            'status' => FiscalYearStatus::Open,
        ]);

        $ram = Customer::factory()->create(['name' => 'Ram Shrestha']);
        $sita = Customer::factory()->create(['name' => 'Sita Gurung']);
        $supplierA = Supplier::factory()->create(['name' => 'ABC Traders']);
        $supplierB = Supplier::factory()->create(['name' => 'XYZ Suppliers']);

        $vatableItem = Item::factory()->create(['name' => 'Vatable Widget', 'is_vatable' => true, 'is_stockable' => false]);
        $plainItem = Item::factory()->create(['name' => 'Plain Widget', 'is_vatable' => false, 'is_stockable' => false]);
        $stockItem = Item::factory()->create(['name' => 'Stocked Widget', 'is_vatable' => false, 'is_stockable' => true]);
        $creditPurchaseItem = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        // Credit sale (no cash settlement): leaves Ram's ledger account with
        // a real outstanding debit balance, i.e. a debtor. total = 1130
        // (1000 taxable + 130 VAT at the default 13% rate).
        Sale::post(
            ['customer_id' => $ram->id, 'invoice_type' => 'full', 'date' => now()->toDateString(), 'payment_mode' => 'credit'],
            [['item_id' => $vatableItem->id, 'quantity' => 1, 'rate' => 1000, 'discount' => 0]],
            $admin,
        );

        // Fully-settled cash sale: nets Sita's own ledger balance to zero
        // (doesn't affect debtors) but its cash settlement lands in the AS1
        // cash account.
        Sale::post(
            ['customer_id' => $sita->id, 'invoice_type' => 'full', 'date' => now()->toDateString(), 'payment_mode' => 'cash'],
            [['item_id' => $plainItem->id, 'quantity' => 1, 'rate' => 500, 'discount' => 0]],
            $admin,
        );

        // Credit purchase (no cash settlement): leaves ABC Traders' ledger
        // account with a real outstanding credit balance, i.e. a creditor.
        Purchase::post(
            ['supplier_id' => $supplierA->id, 'date' => now()->toDateString(), 'payment_mode' => 'credit', 'vat_rate' => 0],
            [['item_id' => $creditPurchaseItem->id, 'quantity' => 1, 'rate' => 300, 'discount' => 0]],
            $admin,
        );

        // Fully-settled cash purchase of a stockable item: nets XYZ
        // Suppliers' own ledger balance to zero (doesn't affect creditors),
        // pays cash out of AS1, and leaves 10 units on hand at a Rs. 20
        // weighted-average cost for the stock valuation.
        Purchase::post(
            ['supplier_id' => $supplierB->id, 'date' => now()->toDateString(), 'payment_mode' => 'cash', 'vat_rate' => 0],
            [['item_id' => $stockItem->id, 'quantity' => 10, 'rate' => 20, 'discount' => 0]],
            $admin,
        );
    });

    $this->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);

    $response = $this->get("http://{$domain}/dashboard");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Tenant/Dashboard')
        ->where('kpis.purchases.today.count', 2)
        ->where('kpis.purchases.today.total', '500.00')
        ->where('kpis.purchases.thisWeek.count', 2)
        ->where('kpis.purchases.thisWeek.total', '500.00')
        ->where('kpis.cashInHand', '300.00')
        ->where('kpis.stockValue', '200.00')
        ->where('kpis.debtors', '1130.00')
        ->where('kpis.creditors', '300.00')
        ->where('kpis.tax.thisWeek.taxable', '1000.00')
        ->where('kpis.tax.thisWeek.nontaxable', '500.00')
        ->where('kpis.tax.thisWeek.vat', '130.00')
        ->has('salesTrend', 7)
        ->where('salesTrend.6.date', now()->toDateString())
        ->where('salesTrend.6.total', '1630.00')
        ->has('purchaseTrend', 7)
        ->where('purchaseTrend.6.date', now()->toDateString())
        ->where('purchaseTrend.6.total', '500.00')
        ->has('topItemsThisMonth', 2)
        ->where('topItemsThisMonth.0.name', 'Vatable Widget')
        ->where('topItemsThisMonth.0.total', '1000.00')
        ->where('topItemsThisMonth.1.name', 'Plain Widget')
        ->where('topItemsThisMonth.1.total', '500.00')
        ->has('topCustomersThisMonth', 2)
        ->where('topCustomersThisMonth.0.name', 'Ram Shrestha')
        ->where('topCustomersThisMonth.0.total', '1130.00')
        ->where('topCustomersThisMonth.1.name', 'Sita Gurung')
        ->where('topCustomersThisMonth.1.total', '500.00')
    );

    $tenant->delete();
});

test('dashboard reports today\'s sales activity and lists recent sales, excluding cancelled ones', function () {
    $domain = 'dashboard-sales.tenant-test';
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    $tenant->run(function () {
        $admin = User::factory()->create(['email' => 'owner@example.com']);

        FiscalYear::create([
            'name' => 'FY1',
            'start_date' => now()->subYear()->startOfYear(),
            'end_date' => now()->addYear()->endOfYear(),
            'status' => FiscalYearStatus::Open,
        ]);

        $customer = Customer::factory()->create(['name' => 'Ram Shrestha']);
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        $todaySale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => now()->toDateString(), 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 500, 'discount' => 0]],
            $admin,
        );

        $cancelledSale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => now()->toDateString(), 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 250, 'discount' => 0]],
            $admin,
        );
        $cancelledSale->cancel($admin, 'test cancellation');

        // Sanity: the amount excluded from the KPIs/list below is the
        // cancelled sale's, not the still-posted one's.
        expect($todaySale->status)->toBe('posted');
    });

    $this->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);

    $response = $this->get("http://{$domain}/dashboard");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Tenant/Dashboard')
        ->where('kpis.sales.today.count', 1)
        ->where('kpis.sales.today.total', '500.00')
        ->where('kpis.sales.thisWeek.count', 1)
        ->where('kpis.sales.thisWeek.total', '500.00')
        ->has('recentSales', 1)
        ->has('recentSales.0', fn ($sale) => $sale
            ->where('customer', 'Ram Shrestha')
            ->where('total', '500.00')
            ->where('paymentMode', 'cash')
            ->has('date')
            ->has('id')
        )
    );

    $tenant->delete();
});

test('dashboard lists items at or below their reorder level and leaves the rest out', function () {
    $domain = 'dashboard-low-stock.tenant-test';
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);

        FiscalYear::create([
            'name' => 'FY1',
            'start_date' => now()->subYear()->startOfYear(),
            'end_date' => now()->addYear()->endOfYear(),
            'status' => FiscalYearStatus::Open,
        ]);

        $store = Store::factory()->create();

        // Below its reorder level.
        $low = Item::factory()->create(['name' => 'Nearly Out', 'is_stockable' => true, 'min_stock' => '10.00']);
        $low->recordStockMovement(StockMovementType::Purchase, '4', now()->toDateString(), $store->id, null, '5.0000', '20.00');

        // Exactly at its reorder level: "at or below" means this counts.
        $atLevel = Item::factory()->create(['name' => 'Exactly At Level', 'is_stockable' => true, 'min_stock' => '6.00']);
        $atLevel->recordStockMovement(StockMovementType::Purchase, '6', now()->toDateString(), $store->id, null, '5.0000', '30.00');

        // Comfortably above it.
        $healthy = Item::factory()->create(['name' => 'Well Stocked', 'is_stockable' => true, 'min_stock' => '2.00']);
        $healthy->recordStockMovement(StockMovementType::Purchase, '100', now()->toDateString(), $store->id, null, '5.0000', '500.00');

        // No reorder level set at all: not "low", just unmonitored.
        Item::factory()->create(['name' => 'No Reorder Level', 'is_stockable' => true, 'min_stock' => '0.00']);
    });

    $this->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);

    $this->get("http://{$domain}/dashboard")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Dashboard')
            ->has('lowStockItems', 2)
            ->where('lowStockItems.0.name', 'Exactly At Level')
            ->where('lowStockItems.0.stock', '6.0000')
            ->where('lowStockItems.0.minStock', '6.0000')
            ->where('lowStockItems.1.name', 'Nearly Out')
            ->where('lowStockItems.1.stock', '4.0000')
            ->where('lowStockItems.1.minStock', '10.0000'));

    $tenant->delete();
});
