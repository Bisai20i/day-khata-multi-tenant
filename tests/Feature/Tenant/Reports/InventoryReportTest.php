<?php

use App\Enums\StockMovementType;
use App\Models\Item;
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

function nearly(float $expected): Closure
{
    return fn ($actual) => abs((float) $actual - $expected) < 0.001;
}

test('stock summary computes opening/in/out/closing quantities and weighted-average valuation over a date range', function () {
    $domain = 'stock-summary-report.tenant-test';
    $tenant = provisionInventoryReportTestTenant($domain);

    $itemId = null;
    $tenant->run(function () use (&$itemId) {
        User::factory()->create(['email' => 'owner@example.com']);

        $item = Item::factory()->create(['name' => 'Widget', 'unit' => 'pcs', 'is_stockable' => true]);
        $itemId = $item->id;

        // Before the range (from=2026-02-01): contributes to opening only.
        $item->recordStockMovement(StockMovementType::Purchase, 10, '2026-01-01', null, 100);

        // Within the range: qty in / qty out, and both feed the valuation
        // basis since they're stock-increasing/-decreasing respectively.
        $item->recordStockMovement(StockMovementType::Purchase, 5, '2026-02-10', null, 130);
        $item->recordStockMovement(StockMovementType::Sale, 3, '2026-02-15');

        // After the range (to=2026-02-28): must not affect qty in/out,
        // opening, closing, or the as-of valuation - this is the "as of a
        // past date" guarantee, not just Item::currentStock().
        $item->recordStockMovement(StockMovementType::Purchase, 20, '2026-03-01', null, 200);

        // Cancelled: falls inside the range but must be excluded from
        // every sum.
        $item->stockMovements()->create([
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
            ->where('rows', fn ($rows) => count($rows) === 1)
            ->where('rows.0.itemId', $itemId)
            ->where('rows.0.opening', nearly(10.0))
            ->where('rows.0.qtyIn', nearly(5.0))
            ->where('rows.0.qtyOut', nearly(3.0))
            ->where('rows.0.closing', nearly(12.0))
            // weighted avg = (10*100 + 5*130) / 15 = 110.0
            ->where('rows.0.avgCost', nearly(110.0))
            // 110 * 12 = 1320.0
            ->where('rows.0.valuation', nearly(1320.0))
            ->where('grandTotalValuation', nearly(1320.0))
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
