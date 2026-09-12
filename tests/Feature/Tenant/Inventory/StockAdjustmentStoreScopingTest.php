<?php

use App\Enums\FiscalYearStatus;
use App\Models\FiscalYear;
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

/**
 * Every stock document now resolves and guards its fiscal year by date
 * (CONTRACTS C4, audit P0-11), so the tenant needs one open year wide
 * enough to hold the dates these tests post on. firstOrCreate, so a test
 * that opens the tenant twice does not try to open a second year.
 */
function stockAdjustmentStoreScopingOpenFiscalYear(): FiscalYear
{
    return FiscalYear::firstOrCreate(
        ['name' => '2026'],
        [
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => FiscalYearStatus::Open,
        ],
    );
}

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
        stockAdjustmentStoreScopingOpenFiscalYear();
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
            ->and($item->fresh()->currentStock($secondStore->id)->toString())->toBe('5.0000')
            ->and($item->fresh()->currentStock()->toString())->toBe('5.0000');
    });

    $tenant->delete();
});

test('omitting store_id falls back to the default (lowest-id active) store', function () {
    $tenant = provisionStockAdjustmentStoreScopingTenant('stock-adjustment-store-fallback.tenant-test');

    $tenant->run(function () {
        stockAdjustmentStoreScopingOpenFiscalYear();
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
        stockAdjustmentStoreScopingOpenFiscalYear();
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

test('two "out" lines of the same item are aggregated before the stock check', function () {
    $tenant = provisionStockAdjustmentStoreScopingTenant('stock-adjustment-aggregate-lines.tenant-test');

    $tenant->run(function () {
        stockAdjustmentStoreScopingOpenFiscalYear();
        $actor = stockAdjustmentStoreScopingActor();
        $item = Item::factory()->create(['is_stockable' => true]);

        StockAdjustment::post(
            ['date' => '2026-06-01'],
            [['item_id' => $item->id, 'direction' => 'in', 'reason_type' => 'found', 'quantity' => 5, 'unit_cost_rate' => 10]],
            $actor,
        );

        // Two lines of 3 against 5 on hand. Checked one line at a time, both
        // passed and the item ended at -1 (audit P1); aggregated per item,
        // the document is refused as a whole.
        expect(fn () => StockAdjustment::post(
            ['date' => '2026-06-02'],
            [
                ['item_id' => $item->id, 'direction' => 'out', 'reason_type' => 'correction', 'quantity' => 3],
                ['item_id' => $item->id, 'direction' => 'out', 'reason_type' => 'correction', 'quantity' => 3],
            ],
            $actor,
        ))->toThrow(InvalidArgumentException::class);

        expect($item->fresh()->currentStock()->toString())->toBe('5.0000')
            ->and(StockAdjustment::count())->toBe(1);
    });

    $tenant->delete();
});

test('an "out" adjustment cannot spend stock held at another store', function () {
    $tenant = provisionStockAdjustmentStoreScopingTenant('stock-adjustment-out-store-scoped.tenant-test');

    $tenant->run(function () {
        stockAdjustmentStoreScopingOpenFiscalYear();
        $actor = stockAdjustmentStoreScopingActor();
        $item = Item::factory()->create(['is_stockable' => true]);
        $mainStore = Store::where('is_active', true)->orderBy('id')->firstOrFail();
        $branchStore = Store::factory()->create(['is_active' => true]);

        StockAdjustment::post(
            ['date' => '2026-06-01', 'store_id' => $mainStore->id],
            [['item_id' => $item->id, 'direction' => 'in', 'reason_type' => 'found', 'quantity' => 10, 'unit_cost_rate' => 10]],
            $actor,
        );

        // The old check summed every store's movements, so the branch could
        // write off stock that only ever existed in the main store.
        expect(fn () => StockAdjustment::post(
            ['date' => '2026-06-02', 'store_id' => $branchStore->id],
            [['item_id' => $item->id, 'direction' => 'out', 'reason_type' => 'correction', 'quantity' => 4]],
            $actor,
        ))->toThrow(InvalidArgumentException::class);

        expect($item->fresh()->currentStock($mainStore->id)->toString())->toBe('10.0000')
            ->and($item->fresh()->currentStock($branchStore->id)->toString())->toBe('0.0000');
    });

    $tenant->delete();
});
