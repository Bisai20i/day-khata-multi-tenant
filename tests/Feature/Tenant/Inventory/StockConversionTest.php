<?php

use App\Enums\FiscalYearStatus;
use App\Enums\StockMovementType;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\ItemStockMovement;
use App\Models\StockConversion;
use App\Models\StockConversionLine;
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
function stockConversionTestOpenFiscalYear(): FiscalYear
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

function provisionStockConversionTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function stockConversionTestActor(): User
{
    return User::factory()->create();
}

test('production consumes raw materials and produces a finished good with correct stock movements', function () {
    $tenant = provisionStockConversionTestTenant('stock-conversion-production.tenant-test');

    $tenant->run(function () {
        stockConversionTestOpenFiscalYear();
        $actor = stockConversionTestActor();
        $rawA = Item::factory()->create(['is_stockable' => true, 'name' => 'Raw A']);
        $rawB = Item::factory()->create(['is_stockable' => true, 'name' => 'Raw B']);
        $finished = Item::factory()->create(['is_stockable' => true, 'name' => 'Finished Good']);
        $store = Store::where('is_active', true)->orderBy('id')->firstOrFail();

        // Seed opening stock for both raw materials directly (avoids
        // depending on StockAdjustment/opening-stock plumbing here).
        $rawA->recordStockMovement(StockMovementType::Opening, 10, '2026-06-01', $store->id);
        $rawB->recordStockMovement(StockMovementType::Opening, 3, '2026-06-01', $store->id);

        $conversion = StockConversion::post(
            ['type' => 'production', 'date' => '2026-06-02', 'note' => 'Assemble finished good'],
            [
                ['item_id' => $rawA->id, 'quantity' => 10],
                ['item_id' => $rawB->id, 'quantity' => 3],
            ],
            [
                ['item_id' => $finished->id, 'quantity' => 5, 'unit_cost_rate' => 20],
            ],
            $actor,
        );

        expect($conversion->type->value)->toBe('production')
            ->and($conversion->store_id)->toBe($store->id)
            ->and($rawA->fresh()->currentStock($store->id)->toString())->toBe('10.0000')
            ->and($rawB->fresh()->currentStock($store->id)->toString())->toBe('3.0000')
            ->and($finished->fresh()->currentStock($store->id)->toString())->toBe('5.0000');

        $movements = ItemStockMovement::query()
            ->where('reference_type', (new StockConversionLine)->getMorphClass())
            ->whereIn('reference_id', $conversion->lines()->pluck('id'))
            ->get();

        expect($movements)->toHaveCount(3);

        $rawAMovement = $movements->firstWhere('item_id', $rawA->id);
        $rawBMovement = $movements->firstWhere('item_id', $rawB->id);
        $finishedMovement = $movements->firstWhere('item_id', $finished->id);

        expect($rawAMovement->movement_type)->toBe(StockMovementType::ProductionOut)
            ->and((float) $rawAMovement->quantity)->toBe(10.0)
            ->and($rawAMovement->store_id)->toBe($store->id)
            ->and($rawBMovement->movement_type)->toBe(StockMovementType::ProductionOut)
            ->and((float) $rawBMovement->quantity)->toBe(3.0)
            ->and($rawBMovement->store_id)->toBe($store->id)
            ->and($finishedMovement->movement_type)->toBe(StockMovementType::ProductionIn)
            ->and((float) $finishedMovement->quantity)->toBe(5.0)
            ->and($finishedMovement->store_id)->toBe($store->id);
    });

    $tenant->delete();
});

test('refining converts an input item into a different output item', function () {
    $tenant = provisionStockConversionTestTenant('stock-conversion-refining.tenant-test');

    $tenant->run(function () {
        stockConversionTestOpenFiscalYear();
        $actor = stockConversionTestActor();
        $raw = Item::factory()->create(['is_stockable' => true, 'name' => 'Raw Ore']);
        $refined = Item::factory()->create(['is_stockable' => true, 'name' => 'Refined Metal']);
        $store = Store::where('is_active', true)->orderBy('id')->firstOrFail();

        $raw->recordStockMovement(StockMovementType::Opening, 100, '2026-06-01', $store->id);

        $conversion = StockConversion::post(
            ['type' => 'refining', 'date' => '2026-06-02'],
            [['item_id' => $raw->id, 'quantity' => 100]],
            [['item_id' => $refined->id, 'quantity' => 40]],
            $actor,
        );

        expect($conversion->type->value)->toBe('refining')
            ->and($raw->fresh()->currentStock($store->id)->toString())->toBe('0.0000')
            ->and($refined->fresh()->currentStock($store->id)->toString())->toBe('40.0000');

        $movement = ItemStockMovement::where('item_id', $refined->id)->firstOrFail();
        expect($movement->movement_type)->toBe(StockMovementType::RefiningIn);
    });

    $tenant->delete();
});

test('repackaging converts input items into output items with dedicated repackaging movement types', function () {
    $tenant = provisionStockConversionTestTenant('stock-conversion-repackaging.tenant-test');

    $tenant->run(function () {
        stockConversionTestOpenFiscalYear();
        $actor = stockConversionTestActor();
        $bulk = Item::factory()->create(['is_stockable' => true, 'name' => 'Bulk Sack (50kg)']);
        $retail = Item::factory()->create(['is_stockable' => true, 'name' => 'Retail Bag (1kg)']);
        $store = Store::where('is_active', true)->orderBy('id')->firstOrFail();

        $bulk->recordStockMovement(StockMovementType::Opening, 10, '2026-06-01', $store->id);

        $conversion = StockConversion::post(
            ['type' => 'repackaging', 'date' => '2026-06-02', 'note' => 'Split bulk sack into retail bags'],
            [['item_id' => $bulk->id, 'quantity' => 10]],
            [['item_id' => $retail->id, 'quantity' => 8]],
            $actor,
        );

        expect($conversion->type->value)->toBe('repackaging')
            ->and($bulk->fresh()->currentStock($store->id)->toString())->toBe('0.0000')
            ->and($retail->fresh()->currentStock($store->id)->toString())->toBe('8.0000');

        $movements = ItemStockMovement::query()
            ->where('reference_type', (new StockConversionLine)->getMorphClass())
            ->whereIn('reference_id', $conversion->lines()->pluck('id'))
            ->get();

        $bulkMovement = $movements->firstWhere('item_id', $bulk->id);
        $retailMovement = $movements->firstWhere('item_id', $retail->id);

        expect($bulkMovement->movement_type)->toBe(StockMovementType::RepackagingOut)
            ->and($retailMovement->movement_type)->toBe(StockMovementType::RepackagingIn);
    });

    $tenant->delete();
});

test('consuming more of an input item than is currently in stock is rejected and nothing is posted', function () {
    $tenant = provisionStockConversionTestTenant('stock-conversion-oversell.tenant-test');

    $tenant->run(function () {
        stockConversionTestOpenFiscalYear();
        $actor = stockConversionTestActor();
        $raw = Item::factory()->create(['is_stockable' => true]);
        $finished = Item::factory()->create(['is_stockable' => true]);
        $store = Store::where('is_active', true)->orderBy('id')->firstOrFail();

        $raw->recordStockMovement(StockMovementType::Opening, 5, '2026-06-01', $store->id);

        expect(fn () => StockConversion::post(
            ['type' => 'production', 'date' => '2026-06-02'],
            [['item_id' => $raw->id, 'quantity' => 6]],
            [['item_id' => $finished->id, 'quantity' => 1]],
            $actor,
        ))->toThrow(InvalidArgumentException::class);

        expect($raw->fresh()->currentStock($store->id)->toString())->toBe('5.0000')
            ->and($finished->fresh()->currentStock($store->id)->toString())->toBe('0.0000')
            ->and(StockConversion::count())->toBe(0);
    });

    $tenant->delete();
});

test('an input item appearing on more than one line has its requested quantity aggregated before the stock check', function () {
    $tenant = provisionStockConversionTestTenant('stock-conversion-aggregate.tenant-test');

    $tenant->run(function () {
        stockConversionTestOpenFiscalYear();
        $actor = stockConversionTestActor();
        $raw = Item::factory()->create(['is_stockable' => true]);
        $finished = Item::factory()->create(['is_stockable' => true]);
        $store = Store::where('is_active', true)->orderBy('id')->firstOrFail();

        $raw->recordStockMovement(StockMovementType::Opening, 10, '2026-06-01', $store->id);

        // Two input lines for the same item totalling 11 must be rejected
        // even though neither line alone (6, then 5) exceeds the 10 on hand.
        expect(fn () => StockConversion::post(
            ['type' => 'production', 'date' => '2026-06-02'],
            [
                ['item_id' => $raw->id, 'quantity' => 6],
                ['item_id' => $raw->id, 'quantity' => 5],
            ],
            [['item_id' => $finished->id, 'quantity' => 1]],
            $actor,
        ))->toThrow(InvalidArgumentException::class);

        expect($raw->fresh()->currentStock($store->id)->toString())->toBe('10.0000');
    });

    $tenant->delete();
});

test('a conversion posted at a non-default store only checks and moves stock at that store', function () {
    $tenant = provisionStockConversionTestTenant('stock-conversion-store-scope.tenant-test');

    $tenant->run(function () {
        stockConversionTestOpenFiscalYear();
        $actor = stockConversionTestActor();
        $raw = Item::factory()->create(['is_stockable' => true]);
        $finished = Item::factory()->create(['is_stockable' => true]);
        $defaultStore = Store::where('is_active', true)->orderBy('id')->firstOrFail();
        $secondStore = Store::factory()->create(['is_active' => true]);

        // Stock exists at the default store, none at the second store.
        $raw->recordStockMovement(StockMovementType::Opening, 10, '2026-06-01', $defaultStore->id);

        expect(fn () => StockConversion::post(
            ['type' => 'production', 'date' => '2026-06-02', 'store_id' => $secondStore->id],
            [['item_id' => $raw->id, 'quantity' => 5]],
            [['item_id' => $finished->id, 'quantity' => 1]],
            $actor,
        ))->toThrow(InvalidArgumentException::class);

        expect($raw->fresh()->currentStock($defaultStore->id)->toString())->toBe('10.0000')
            ->and($raw->fresh()->currentStock($secondStore->id)->toString())->toBe('0.0000');
    });

    $tenant->delete();
});

test('at least one input and one output line are required', function () {
    $tenant = provisionStockConversionTestTenant('stock-conversion-required-lines.tenant-test');

    $tenant->run(function () {
        stockConversionTestOpenFiscalYear();
        $actor = stockConversionTestActor();
        $item = Item::factory()->create(['is_stockable' => true]);

        expect(fn () => StockConversion::post(
            ['type' => 'production', 'date' => '2026-06-01'],
            [],
            [['item_id' => $item->id, 'quantity' => 1]],
            $actor,
        ))->toThrow(InvalidArgumentException::class);

        expect(fn () => StockConversion::post(
            ['type' => 'production', 'date' => '2026-06-01'],
            [['item_id' => $item->id, 'quantity' => 1]],
            [],
            $actor,
        ))->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('cancelling reverts the stock impact of every line and rejects double-cancellation', function () {
    $tenant = provisionStockConversionTestTenant('stock-conversion-cancel.tenant-test');

    $tenant->run(function () {
        stockConversionTestOpenFiscalYear();
        $actor = stockConversionTestActor();
        $raw = Item::factory()->create(['is_stockable' => true]);
        $finished = Item::factory()->create(['is_stockable' => true]);
        $store = Store::where('is_active', true)->orderBy('id')->firstOrFail();

        $raw->recordStockMovement(StockMovementType::Opening, 10, '2026-06-01', $store->id);

        $conversion = StockConversion::post(
            ['type' => 'production', 'date' => '2026-06-02'],
            [['item_id' => $raw->id, 'quantity' => 10]],
            [['item_id' => $finished->id, 'quantity' => 4]],
            $actor,
        );

        expect($raw->fresh()->currentStock($store->id)->toString())->toBe('0.0000')
            ->and($finished->fresh()->currentStock($store->id)->toString())->toBe('4.0000');

        $conversion->cancel($actor, 'Recorded in error');

        expect($raw->fresh()->currentStock($store->id)->toString())->toBe('10.0000')
            ->and($finished->fresh()->currentStock($store->id)->toString())->toBe('0.0000')
            ->and($conversion->fresh()->status)->toBe('cancelled');

        expect(fn () => $conversion->cancel($actor, 'Again'))->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});
