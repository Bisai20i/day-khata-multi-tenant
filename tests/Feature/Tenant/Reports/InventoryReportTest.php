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

function provisionInventoryReportTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function loginInventoryReportTestUser(string $domain): void
{
    test()->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);
}

test('stock summary computes opening/in/out/closing quantities and weighted-average valuation over a date range', function () {
    $domain = 'stock-summary-report.tenant-test';
    $tenant = provisionInventoryReportTestTenant($domain);

    $itemId = null;
    $tenant->run(function () use (&$itemId) {
        User::factory()->create(['email' => 'owner@example.com']);

        $item = Item::factory()->create(['name' => 'Widget', 'unit' => 'pcs', 'is_stockable' => true]);
        $itemId = $item->id;
        $storeId = Store::where('is_active', true)->orderBy('id')->firstOrFail()->id;

        // Before the range (from=2026-02-01): contributes to opening only.
        //
        // The valuation now comes from StockCosting, which averages each
        // movement's `value` rather than unit_cost_rate x quantity, so a
        // priced movement has to carry one; recordStockMovement() derives the
        // per-base-unit rate from it. 10 units for Rs 1,000 is Rs 100 each.
        $item->recordStockMovement(StockMovementType::Purchase, 10, '2026-01-01', $storeId, value: 1000);

        // Within the range: qty in / qty out. Only the purchase feeds the
        // valuation basis - a sale leaves stock at selling price, not cost.
        $item->recordStockMovement(StockMovementType::Purchase, 5, '2026-02-10', $storeId, value: 650);
        $item->recordStockMovement(StockMovementType::Sale, 3, '2026-02-15', $storeId);

        // After the range (to=2026-02-28): must not affect qty in/out,
        // opening, closing, or the as-of valuation - this is the "as of a
        // past date" guarantee, not just Item::currentStock().
        $item->recordStockMovement(StockMovementType::Purchase, 20, '2026-03-01', $storeId, value: 4000);

        // Cancelled: falls inside the range but must be excluded from
        // every sum.
        $item->stockMovements()->create([
            'store_id' => $storeId,
            'movement_type' => StockMovementType::AdjustmentIn,
            'quantity' => 100,
            'date' => '2026-02-20',
            'cancelled' => true,
        ]);
    });

    loginInventoryReportTestUser($domain);

    $this->get("http://{$domain}/reports/stock-summary?from=2026-02-01&to=2026-02-28")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Reports/StockSummary')
            ->where('from', '2026-02-01')
            ->where('to', '2026-02-28')
            ->where('rows', fn ($rows) => count($rows) === 1)
            ->where('rows.0.itemId', $itemId)
            // Every figure is an exact decimal string now: quantities and the
            // average cost at 4 places, rupee values at 2.
            ->where('rows.0.opening', '10.0000')
            ->where('rows.0.qtyIn', '5.0000')
            ->where('rows.0.qtyOut', '3.0000')
            ->where('rows.0.closing', '12.0000')
            // weighted avg = (1000 + 650) / 15 = 110.0
            ->where('rows.0.avgCost', '110.0000')
            // 110 * 12 = 1320.00, rounded to rupees exactly once.
            ->where('rows.0.valuation', '1320.00')
            ->where('grandTotalValuation', '1320.00')
            ->etc()
        );

    $tenant->delete();
});

test('an item with no movements at all is excluded from the report', function () {
    $domain = 'stock-summary-empty-item.tenant-test';
    $tenant = provisionInventoryReportTestTenant($domain);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);
        Item::factory()->create(['name' => 'Untouched Item', 'is_stockable' => true]);
    });

    loginInventoryReportTestUser($domain);

    $this->get("http://{$domain}/reports/stock-summary?from=2026-02-01&to=2026-02-28")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Reports/StockSummary')
            ->where('rows', [])
            ->etc()
        );

    $tenant->delete();
});

test('stock summary is scoped to a single store when store_id is given, and sums across all stores when omitted', function () {
    $domain = 'stock-summary-store-scope.tenant-test';
    $tenant = provisionInventoryReportTestTenant($domain);

    $branchStoreId = null;
    $tenant->run(function () use (&$branchStoreId) {
        User::factory()->create(['email' => 'owner@example.com']);

        $item = Item::factory()->create(['name' => 'Widget', 'unit' => 'pcs', 'is_stockable' => true]);

        $mainStoreId = Store::where('is_active', true)->orderBy('id')->firstOrFail()->id;
        $branchStoreId = Store::factory()->create(['name' => 'Branch Store'])->id;

        // Main store: 10 in @ 100. The priced `value` is what StockCosting
        // averages over; unit_cost_rate alone is not a cost basis.
        $item->recordStockMovement(StockMovementType::Purchase, 10, '2026-02-05', $mainStoreId, value: 1000);

        // Branch store: 6 in @ 100.
        $item->recordStockMovement(StockMovementType::Purchase, 6, '2026-02-10', $branchStoreId, value: 600);
    });

    loginInventoryReportTestUser($domain);

    // Filtered to the branch store only: closing 6, valuation 600.
    $this->get("http://{$domain}/reports/stock-summary?from=2026-02-01&to=2026-02-28&store_id={$branchStoreId}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            // The quantity is store-scoped but the weighted average cost
            // never is: (1000 + 600) / 16 = 100 in both views, so the
            // per-store values still add up to the all-stores total.
            ->where('rows.0.closing', '6.0000')
            ->where('rows.0.avgCost', '100.0000')
            ->where('rows.0.valuation', '600.00')
            ->where('grandTotalValuation', '600.00')
            ->where('storeId', $branchStoreId)
            ->etc()
        );

    // Unfiltered: cross-store closing 16, valuation 1600 - must match the
    // total both stores' movements sum to, i.e. the pre-store-filter behaviour.
    $this->get("http://{$domain}/reports/stock-summary?from=2026-02-01&to=2026-02-28")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('rows.0.closing', '16.0000')
            ->where('rows.0.avgCost', '100.0000')
            ->where('rows.0.valuation', '1600.00')
            ->where('grandTotalValuation', '1600.00')
            ->where('storeId', null)
            ->etc()
        );

    $tenant->delete();
});

test('the combined stock summary leaves store transfers out of qty in and qty out', function () {
    $domain = 'stock-summary-transfers.tenant-test';
    $tenant = provisionInventoryReportTestTenant($domain);

    // Audit P0-17: moving 10 units from the main store to the branch created a
    // TransferIn that looked like 10 more units bought and a TransferOut that
    // looked like 10 sold, so the all-stores movement columns read double the
    // real activity. Relocating your own goods is not stock entering or
    // leaving the business. Opening and closing still count transfers, because
    // for a single store they really are movements.
    $itemId = null;
    $branchId = null;

    $tenant->run(function () use (&$itemId, &$branchId) {
        User::factory()->create(['email' => 'owner@example.com']);

        $item = Item::factory()->create(['name' => 'Travelling Item', 'unit' => 'pcs', 'is_stockable' => true, 'purchase_rate' => 7]);
        $itemId = $item->id;

        $main = Store::where('is_active', true)->orderBy('id')->firstOrFail();
        $branch = Store::factory()->create(['name' => 'Branch Store']);
        $branchId = $branch->id;

        $item->recordStockMovement(StockMovementType::Purchase, 30, '2026-02-01', $main->id, value: 3000);
        $item->recordStockMovement(StockMovementType::TransferOut, 10, '2026-02-05', $main->id);
        $item->recordStockMovement(StockMovementType::TransferIn, 10, '2026-02-05', $branch->id);
    });

    loginInventoryReportTestUser($domain);

    // Combined: 30 in, nothing out, 30 on hand. The two transfer legs cancel
    // in the closing quantity and must not show up in the movement columns.
    $this->get("http://{$domain}/reports/stock-summary?from=2026-02-01&to=2026-02-28")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('rows', 1)
            ->where('rows.0.qtyIn', '30.0000')
            ->where('rows.0.qtyOut', '0.0000')
            ->where('rows.0.closing', '30.0000')
        );

    // One store: the transfer in IS a real movement for the branch.
    $this->get("http://{$domain}/reports/stock-summary?from=2026-02-01&to=2026-02-28&store_id={$branchId}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('rows', 1)
            ->where('rows.0.qtyIn', '10.0000')
            ->where('rows.0.qtyOut', '0.0000')
            ->where('rows.0.closing', '10.0000')
        );

    $tenant->delete();
});
