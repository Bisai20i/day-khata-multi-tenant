<?php

use App\Models\Item;
use App\Models\ItemStockMovement;
use App\Models\StockAdjustment;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionStockAdjustmentStoreScopingTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function stockAdjustmentStoreScopingActor(): User
{
    return User::factory()->create();
}

test('posting a stock adjustment with an explicit store_id records the movement against that store', function () {
    $tenant = provisionStockAdjustmentStoreScopingTenant('stock-adjustment-store-explicit.tenant-test');

    $tenant->run(function () {
        $actor = stockAdjustmentStoreScopingActor();
        $item = Item::factory()->create(['is_stockable' => true]);
        $secondStore = Store::factory()->create(['is_active' => true]);

        $adjustment = StockAdjustment::post(
            ['date' => '2026-06-01', 'store_id' => $secondStore->id],
            [['item_id' => $item->id, 'direction' => 'in', 'reason_type' => 'found', 'quantity' => 5, 'unit_cost_rate' => 10]],
            $actor,
        );

        expect($adjustment->store_id)->toBe($secondStore->id);

        $movement = ItemStockMovement::where('item_id', $item->id)->firstOrFail();
        expect($movement->store_id)->toBe($secondStore->id)
            ->and($item->fresh()->currentStock($secondStore->id))->toBe(5.0)
            ->and($item->fresh()->currentStock())->toBe(5.0);
    });

    $tenant->delete();
});

test('omitting store_id falls back to the default (lowest-id active) store', function () {
    $tenant = provisionStockAdjustmentStoreScopingTenant('stock-adjustment-store-fallback.tenant-test');

    $tenant->run(function () {
        $actor = stockAdjustmentStoreScopingActor();
        $item = Item::factory()->create(['is_stockable' => true]);

        // A second, higher-id active store exists too - the fallback must
        // still resolve to the seeded "Main Store" (lowest id), not just
        // any active store.
        Store::factory()->create(['is_active' => true]);
        $defaultStoreId = Store::where('is_active', true)->orderBy('id')->value('id');

        $adjustment = StockAdjustment::post(
            ['date' => '2026-06-01'],
            [['item_id' => $item->id, 'direction' => 'in', 'reason_type' => 'found', 'quantity' => 3, 'unit_cost_rate' => 10]],
            $actor,
        );

        expect($adjustment->store_id)->toBe($defaultStoreId);

        $movement = ItemStockMovement::where('item_id', $item->id)->firstOrFail();
        expect($movement->store_id)->toBe($defaultStoreId);
    });

    $tenant->delete();
});

test('posting a stock adjustment with no active store configured throws', function () {
    $tenant = provisionStockAdjustmentStoreScopingTenant('stock-adjustment-store-none-active.tenant-test');

    $tenant->run(function () {
        $actor = stockAdjustmentStoreScopingActor();
        $item = Item::factory()->create(['is_stockable' => true]);

        Store::query()->update(['is_active' => false]);

        expect(fn () => StockAdjustment::post(
            ['date' => '2026-06-01'],
            [['item_id' => $item->id, 'direction' => 'in', 'reason_type' => 'found', 'quantity' => 3, 'unit_cost_rate' => 10]],
            $actor,
        ))->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});
