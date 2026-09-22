<?php

use App\Enums\StockMovementType;
use App\Models\Item;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Inventory\StockCosting;
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
        //
        // StockCosting builds the basis from each movement's `value`, never
        // from unit_cost_rate x quantity, so a priced movement has to carry
        // one; recordStockMovement() derives the per-base-unit rate from it.
        // 10 units for Rs 1,000 is Rs 100 each, 5 for Rs 650 is Rs 130 each.
        $widget->recordStockMovement(StockMovementType::Purchase, 10, '2026-01-01', $storeId, value: 1000);
        $widget->recordStockMovement(StockMovementType::Purchase, 5, '2026-02-10', $storeId, value: 650);
        $widget->recordStockMovement(StockMovementType::Sale, 3, '2026-02-15', $storeId);

        // After as_of: must not affect quantity, cost basis, or valuation -
        // this is the "as of a past date" guarantee.
        $widget->recordStockMovement(StockMovementType::Purchase, 20, '2026-03-01', $storeId, value: 4000);

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
        $gadget->recordStockMovement(StockMovementType::Purchase, 100, '2026-01-05', $storeId, value: 2000);
    });

    loginStockValuationReportTestUser($domain);

    $this->get("http://{$domain}/reports/stock-valuation?as_of=2026-02-28")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Reports/StockValuation')
            ->where('asOf', '2026-02-28')
            ->where('rows', fn ($rows) => count($rows) === 2)
            // Gadget (valuation 2000) sorts before Widget (valuation 1320).
            // Every figure is an exact decimal string now: quantities and the
            // average cost at 4 places, rupee values at 2.
            ->where('rows.0.itemId', $gadgetId)
            ->where('rows.0.quantity', '100.0000')
            ->where('rows.0.avgCost', '20.0000')
            ->where('rows.0.valuation', '2000.00')
            ->where('rows.1.itemId', $widgetId)
            // quantity as of 2026-02-28 = 10 + 5 - 3 = 12 (the 2026-03-01
            // purchase and cancelled adjustment are excluded).
            ->where('rows.1.quantity', '12.0000')
            // weighted avg = (1000 + 650) / 15 = 110.0
            ->where('rows.1.avgCost', '110.0000')
            // 110 * 12 = 1320.00, rounded to rupees exactly once.
            ->where('rows.1.valuation', '1320.00')
            // 2000 + 1320 = 3320.00
            ->where('grandTotalValuation', '3320.00')
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

        // Main store: 10 @ 100 = 1000. The priced `value` is what
        // StockCosting averages over; unit_cost_rate alone is not a basis.
        $widget->recordStockMovement(StockMovementType::Purchase, 10, '2026-01-05', $mainStoreId, value: 1000);

        // Branch store: 4 @ 100 = 400.
        $widget->recordStockMovement(StockMovementType::Purchase, 4, '2026-01-10', $branchStoreId, value: 400);
    });

    loginStockValuationReportTestUser($domain);

    // Filtered to the branch store only: quantity 4, valuation 400.
    $this->get("http://{$domain}/reports/stock-valuation?as_of=2026-01-31&store_id={$branchStoreId}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            // The quantity is store-scoped but the weighted average cost
            // never is, so the per-store values still add up to 1,400.
            ->where('rows.0.quantity', '4.0000')
            ->where('rows.0.valuation', '400.00')
            ->where('grandTotalValuation', '400.00')
            ->where('storeId', $branchStoreId)
            ->etc()
        );

    // Unfiltered: cross-store quantity 14, valuation 1400 - must match the
    // total both stores' movements sum to, i.e. the pre-store-filter behaviour.
    $this->get("http://{$domain}/reports/stock-valuation?as_of=2026-01-31")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('rows.0.quantity', '14.0000')
            ->where('rows.0.valuation', '1400.00')
            ->where('grandTotalValuation', '1400.00')
            ->where('storeId', null)
            ->etc()
        );

    $tenant->delete();
});

test('the stock_status filter isolates positive and negative on-hand quantities, mirroring legacy stockValuationReport()', function () {
    $domain = 'stock-valuation-status-filter.tenant-test';
    $tenant = provisionStockValuationReportTestTenant($domain);

    $positiveId = null;
    $negativeId = null;
    $tenant->run(function () use (&$positiveId, &$negativeId) {
        User::factory()->create(['email' => 'owner@example.com']);
        $storeId = Store::where('is_active', true)->orderBy('id')->firstOrFail()->id;

        // Positive stock: 10 in, 3 out, 7 on hand.
        $positive = Item::factory()->create(['name' => 'Positive Item', 'unit' => 'pcs', 'is_stockable' => true]);
        $positiveId = $positive->id;
        $positive->recordStockMovement(StockMovementType::Purchase, 10, '2026-01-01', $storeId, value: 1000);
        $positive->recordStockMovement(StockMovementType::Sale, 3, '2026-01-05', $storeId);

        // Negative stock (a data-entry error, or an oversold item where the
        // caller allowed it): 2 in, 5 out, -3 on hand. This is the class of
        // bug legacy's stock_status=negative filter exists to surface, and
        // recordStockMovement() carries no guard against it (the guard, if
        // any, lives in the document controller, not the model) - matching
        // how the other tests in this file build raw movements directly.
        $negative = Item::factory()->create(['name' => 'Negative Item', 'unit' => 'pcs', 'is_stockable' => true]);
        $negativeId = $negative->id;
        $negative->recordStockMovement(StockMovementType::Purchase, 2, '2026-01-01', $storeId, value: 200);
        $negative->recordStockMovement(StockMovementType::Sale, 5, '2026-01-05', $storeId);
    });

    loginStockValuationReportTestUser($domain);

    // Default ('all'): both rows show, unchanged from before this filter
    // existed.
    $this->get("http://{$domain}/reports/stock-valuation?as_of=2026-01-31")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('stockStatus', 'all')
            ->where('rows', fn ($rows) => count($rows) === 2)
            ->etc()
        );

    // stock_status=positive: only the item with positive on-hand quantity.
    $this->get("http://{$domain}/reports/stock-valuation?as_of=2026-01-31&stock_status=positive")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('stockStatus', 'positive')
            ->where('rows', fn ($rows) => count($rows) === 1)
            ->where('rows.0.itemId', $positiveId)
            ->where('rows.0.quantity', '7.0000')
            ->etc()
        );

    // stock_status=negative: only the item with negative on-hand quantity.
    $this->get("http://{$domain}/reports/stock-valuation?as_of=2026-01-31&stock_status=negative")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('stockStatus', 'negative')
            ->where('rows', fn ($rows) => count($rows) === 1)
            ->where('rows.0.itemId', $negativeId)
            ->where('rows.0.quantity', '-3.0000')
            ->etc()
        );

    // An unrecognised value falls back to 'all' rather than erroring.
    $this->get("http://{$domain}/reports/stock-valuation?as_of=2026-01-31&stock_status=bogus")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('stockStatus', 'all')
            ->where('rows', fn ($rows) => count($rows) === 2)
            ->etc()
        );

    $tenant->delete();
});

test('the stock valuation report reports exactly what StockCosting reports', function () {
    $domain = 'stock-valuation-matches-costing.tenant-test';
    $tenant = provisionStockValuationReportTestTenant($domain);

    // StockCosting is the one place stock is valued (C10). The Balance Sheet
    // and the year-end closing entry read it too, so this screen agreeing
    // with it is what keeps the report and the books from drifting. The old
    // controller ran its own weighted average off ItemStockMovement and
    // disagreed with both.
    $expected = null;
    $expectedRows = null;

    $tenant->run(function () use (&$expected, &$expectedRows) {
        User::factory()->create(['email' => 'owner@example.com']);
        $storeId = Store::where('is_active', true)->orderBy('id')->firstOrFail()->id;

        // 100,000 units at Rs 1 plus 200,000 at Rs 2 is Rs 500,000 of stock.
        // Rounding the average (1.666666...) to 4 decimals and multiplying
        // afterwards reported Rs 500,010.00 (audit P0-17).
        $bulk = Item::factory()->create(['name' => 'Bulk Item', 'unit' => 'pcs', 'is_stockable' => true, 'purchase_rate' => 7]);
        $bulk->recordStockMovement(StockMovementType::Purchase, 100000, '2026-01-01', $storeId, value: 100000);
        $bulk->recordStockMovement(StockMovementType::Purchase, 200000, '2026-01-02', $storeId, value: 400000);

        $plain = Item::factory()->create(['name' => 'Plain Item', 'unit' => 'pcs', 'is_stockable' => true, 'purchase_rate' => 7]);
        $plain->recordStockMovement(StockMovementType::Purchase, 4, '2026-01-03', $storeId, value: 400);

        $expected = StockCosting::totalClosingValue('2026-02-28')->toString();
        $expectedRows = StockCosting::valuationRows('2026-02-28')
            ->mapWithKeys(fn (array $row) => [$row['item_id'] => $row['value']->toString()])
            ->all();

        expect($expected)->toBe('500400.00');
    });

    loginStockValuationReportTestUser($domain);

    $this->get("http://{$domain}/reports/stock-valuation?as_of=2026-02-28")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('grandTotalValuation', $expected)
            ->has('rows', count($expectedRows))
            ->where('rows', fn ($rows) => collect($rows)->every(
                fn ($row) => ($expectedRows[$row['itemId']] ?? null) === $row['valuation'],
            ))
        );

    $tenant->delete();
});
