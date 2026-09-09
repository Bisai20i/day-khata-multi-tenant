<?php

use App\Enums\StockMovementType;
use App\Models\Item;
use App\Models\StockAdjustment;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionDamageLostStockReportTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function loginDamageLostStockReportTestUser(string $domain): void
{
    test()->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);
}

test('the damage and lost stock report lists damage/lost lines and aggregates them item-wise', function () {
    $domain = 'damage-lost-stock.tenant-test';
    $tenant = provisionDamageLostStockReportTestTenant($domain);

    $tenant->run(function () {
        $admin = User::factory()->create(['email' => 'owner@example.com']);
        $item = Item::factory()->create(['name' => 'Widget', 'unit' => 'pcs', 'is_stockable' => true]);
        $item->recordStockMovement(StockMovementType::Opening, 100, '2026-06-01', Store::where('is_active', true)->orderBy('id')->value('id'));

        // One damage line and one lost line for the same item on two
        // different adjustments - should both show up raw and be summed
        // together (3 + 2 = 5) in the item-wise view.
        StockAdjustment::post(
            ['date' => '2026-06-05', 'note' => 'Breakage'],
            [['item_id' => $item->id, 'direction' => 'out', 'reason_type' => 'damage', 'quantity' => 3, 'remarks' => 'Dropped crate']],
            $admin,
        );

        StockAdjustment::post(
            ['date' => '2026-06-10'],
            [['item_id' => $item->id, 'direction' => 'out', 'reason_type' => 'lost', 'quantity' => 2]],
            $admin,
        );

        // A 'found' (non-damage/lost) line and an 'in'-direction line must
        // never appear in this report.
        StockAdjustment::post(
            ['date' => '2026-06-12'],
            [['item_id' => $item->id, 'direction' => 'in', 'reason_type' => 'found', 'quantity' => 50]],
            $admin,
        );
    });

    loginDamageLostStockReportTestUser($domain);

    $this->get("http://{$domain}/reports/damage-lost-stock?from=2026-06-01&to=2026-06-30")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Reports/DamageLostStock')
            ->has('lines', 2)
            ->where('lines.0.reason', 'damage')
            ->where('lines.0.quantity', 3)
            ->where('lines.1.reason', 'lost')
            ->where('lines.1.quantity', 2)
            ->has('itemWise', 1)
            ->where('itemWise.0.name', 'Widget')
            ->where('itemWise.0.total_quantity', 5)
            ->where('itemWise.0.transaction_count', 2)
        );

    $tenant->delete();
});

test('a cancelled stock adjustment is excluded from the damage and lost stock report', function () {
    $domain = 'damage-lost-stock-cancelled.tenant-test';
    $tenant = provisionDamageLostStockReportTestTenant($domain);

    $tenant->run(function () {
        $admin = User::factory()->create(['email' => 'owner@example.com']);
        $item = Item::factory()->create(['name' => 'Widget', 'is_stockable' => true]);
        $item->recordStockMovement(StockMovementType::Opening, 10, '2026-06-01', Store::where('is_active', true)->orderBy('id')->value('id'));

        $adjustment = StockAdjustment::post(
            ['date' => '2026-06-05'],
            [['item_id' => $item->id, 'direction' => 'out', 'reason_type' => 'damage', 'quantity' => 4]],
            $admin,
        );
        $adjustment->cancel($admin, 'Recorded in error');
    });

    loginDamageLostStockReportTestUser($domain);

    $this->get("http://{$domain}/reports/damage-lost-stock?from=2026-06-01&to=2026-06-30")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('lines', 0)
            ->has('itemWise', 0)
        );

    $tenant->delete();
});

test('the damage and lost stock report can be narrowed to a single reason', function () {
    $domain = 'damage-lost-stock-reason-filter.tenant-test';
    $tenant = provisionDamageLostStockReportTestTenant($domain);

    $tenant->run(function () {
        $admin = User::factory()->create(['email' => 'owner@example.com']);
        $item = Item::factory()->create(['name' => 'Widget', 'is_stockable' => true]);
        $item->recordStockMovement(StockMovementType::Opening, 10, '2026-06-01', Store::where('is_active', true)->orderBy('id')->value('id'));

        StockAdjustment::post(
            ['date' => '2026-06-05'],
            [['item_id' => $item->id, 'direction' => 'out', 'reason_type' => 'damage', 'quantity' => 3]],
            $admin,
        );
        StockAdjustment::post(
            ['date' => '2026-06-06'],
            [['item_id' => $item->id, 'direction' => 'out', 'reason_type' => 'lost', 'quantity' => 2]],
            $admin,
        );
    });

    loginDamageLostStockReportTestUser($domain);

    $this->get("http://{$domain}/reports/damage-lost-stock?from=2026-06-01&to=2026-06-30&reason=lost")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('lines', 1)
            ->where('lines.0.reason', 'lost')
            ->where('lines.0.quantity', 2)
        );

    $tenant->delete();
});
