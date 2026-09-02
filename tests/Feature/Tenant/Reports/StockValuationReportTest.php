<?php

use App\Enums\StockMovementType;
use App\Models\Item;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionStockValuationReportTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function loginStockValuationReportTestUser(string $domain): void
{
    test()->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);
}

function stockValuationNearly(float $expected): Closure
{
    return fn ($actual) => abs((float) $actual - $expected) < 0.001;
}

test('stock valuation snapshot computes as-of quantity and weighted-average valuation, sorted by valuation descending, with a correct grand total', function () {
    $domain = 'stock-valuation-report.tenant-test';
    $tenant = provisionStockValuationReportTestTenant($domain);

    $widgetId = null;
    $gadgetId = null;
    $tenant->run(function () use (&$widgetId, &$gadgetId) {
        User::factory()->create(['email' => 'owner@example.com']);

        $widget = Item::factory()->create(['name' => 'Widget', 'unit' => 'pcs', 'is_stockable' => true]);
        $widgetId = $widget->id;
        $storeId = Store::where('is_active', true)->orderBy('id')->firstOrFail()->id;

        // On or before as_of (2026-02-28): both feed the weighted-average
        // cost basis and the as-of quantity.
        $widget->recordStockMovement(StockMovementType::Purchase, 10, '2026-01-01', $storeId, unitCostRate: 100);
        $widget->recordStockMovement(StockMovementType::Purchase, 5, '2026-02-10', $storeId, unitCostRate: 130);
        $widget->recordStockMovement(StockMovementType::Sale, 3, '2026-02-15', $storeId);

        // After as_of: must not affect quantity, cost basis, or valuation -
        // this is the "as of a past date" guarantee.
        $widget->recordStockMovement(StockMovementType::Purchase, 20, '2026-03-01', $storeId, unitCostRate: 200);

        // Cancelled: dated on or before as_of but must be excluded from
        // every sum.
        $widget->stockMovements()->create([
            'store_id' => $storeId,
            'movement_type' => StockMovementType::AdjustmentIn,
            'quantity' => 100,
            'date' => '2026-02-20',
            'cancelled' => true,
        ]);

        // A higher-valuation item, to prove sort order (valuation desc) and
        // that the grand total sums across items.
        $gadget = Item::factory()->create(['name' => 'Gadget', 'unit' => 'pcs', 'is_stockable' => true]);
        $gadgetId = $gadget->id;
        $gadget->recordStockMovement(StockMovementType::Purchase, 100, '2026-01-05', $storeId, unitCostRate: 20);
    });

    loginStockValuationReportTestUser($domain);

    $this->get("http://{$domain}/reports/stock-valuation?as_of=2026-02-28")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Reports/StockValuation')
            ->where('asOf', '2026-02-28')
            ->where('rows', fn ($rows) => count($rows) === 2)
            // Gadget (valuation 2000) sorts before Widget (valuation 1320).
            ->where('rows.0.itemId', $gadgetId)
            ->where('rows.0.quantity', stockValuationNearly(100.0))
            ->where('rows.0.avgCost', stockValuationNearly(20.0))
            ->where('rows.0.valuation', stockValuationNearly(2000.0))
            ->where('rows.1.itemId', $widgetId)
            // quantity as of 2026-02-28 = 10 + 5 - 3 = 12 (the 2026-03-01
            // purchase and cancelled adjustment are excluded).
            ->where('rows.1.quantity', stockValuationNearly(12.0))
            // weighted avg = (10*100 + 5*130) / 15 = 110.0
            ->where('rows.1.avgCost', stockValuationNearly(110.0))
            // 110 * 12 = 1320.0
            ->where('rows.1.valuation', stockValuationNearly(1320.0))
            // 2000 + 1320 = 3320.0
            ->where('grandTotalValuation', stockValuationNearly(3320.0))
            ->etc()
        );

    $tenant->delete();
});

test('an item with no movements at all is excluded from the stock valuation report', function () {
    $domain = 'stock-valuation-empty-item.tenant-test';
    $tenant = provisionStockValuationReportTestTenant($domain);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);
        Item::factory()->create(['name' => 'Untouched Item', 'is_stockable' => true]);
    });

    loginStockValuationReportTestUser($domain);

    $this->get("http://{$domain}/reports/stock-valuation?as_of=2026-02-28")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Reports/StockValuation')
            ->where('rows', [])
            ->etc()
        );

    $tenant->delete();
});

test('stock valuation is scoped to a single store when store_id is given, and sums across all stores when omitted', function () {
    $domain = 'stock-valuation-store-scope.tenant-test';
    $tenant = provisionStockValuationReportTestTenant($domain);

    $branchStoreId = null;
    $tenant->run(function () use (&$branchStoreId) {
        User::factory()->create(['email' => 'owner@example.com']);

        $widget = Item::factory()->create(['name' => 'Widget', 'unit' => 'pcs', 'is_stockable' => true]);

        $mainStoreId = Store::where('is_active', true)->orderBy('id')->firstOrFail()->id;
        $branchStoreId = Store::factory()->create(['name' => 'Branch Store'])->id;

        // Main store: 10 @ 100 = 1000.
        $widget->recordStockMovement(StockMovementType::Purchase, 10, '2026-01-05', $mainStoreId, unitCostRate: 100);

        // Branch store: 4 @ 100 = 400.
        $widget->recordStockMovement(StockMovementType::Purchase, 4, '2026-01-10', $branchStoreId, unitCostRate: 100);
    });

    loginStockValuationReportTestUser($domain);

    // Filtered to the branch store only: quantity 4, valuation 400.
    $this->get("http://{$domain}/reports/stock-valuation?as_of=2026-01-31&store_id={$branchStoreId}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('rows.0.quantity', stockValuationNearly(4.0))
            ->where('rows.0.valuation', stockValuationNearly(400.0))
            ->where('grandTotalValuation', stockValuationNearly(400.0))
            ->where('storeId', $branchStoreId)
            ->etc()
        );

    // Unfiltered: cross-store quantity 14, valuation 1400 - must match the
    // total both stores' movements sum to, i.e. the pre-store-filter behaviour.
    $this->get("http://{$domain}/reports/stock-valuation?as_of=2026-01-31")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('rows.0.quantity', stockValuationNearly(14.0))
            ->where('rows.0.valuation', stockValuationNearly(1400.0))
            ->where('grandTotalValuation', stockValuationNearly(1400.0))
            ->where('storeId', null)
            ->etc()
        );

    $tenant->delete();
});
