<?php

use App\Enums\StockMovementType;
use App\Models\Item;
use App\Models\ItemStockMovement;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentLine;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionStockAdjustmentTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function stockAdjustmentTestActor(): User
{
    return User::factory()->create();
}

test('an "in" adjustment increases current stock', function () {
    $tenant = provisionStockAdjustmentTestTenant('stock-adjustment-in.tenant-test');

    $tenant->run(function () {
        $actor = stockAdjustmentTestActor();
        $item = Item::factory()->create(['is_stockable' => true]);

        $adjustment = StockAdjustment::post(
            ['date' => '2026-06-01', 'note' => 'Found extra stock'],
            [['item_id' => $item->id, 'direction' => 'in', 'reason_type' => 'found', 'quantity' => 5, 'unit_cost_rate' => 10]],
            $actor,
        );

        expect($item->fresh()->currentStock())->toBe(5.0)
            ->and((float) $adjustment->total_value)->toBe(50.0);
    });

    $tenant->delete();
});

test('an "out" adjustment decreases current stock', function () {
    $tenant = provisionStockAdjustmentTestTenant('stock-adjustment-out.tenant-test');

    $tenant->run(function () {
        $actor = stockAdjustmentTestActor();
        $item = Item::factory()->create(['is_stockable' => true]);

        StockAdjustment::post(
            ['date' => '2026-06-01'],
            [['item_id' => $item->id, 'direction' => 'in', 'reason_type' => 'opening', 'quantity' => 10]],
            $actor,
        );

        StockAdjustment::post(
            ['date' => '2026-06-02'],
            [['item_id' => $item->id, 'direction' => 'out', 'reason_type' => 'correction', 'quantity' => 4]],
            $actor,
        );

        expect($item->fresh()->currentStock())->toBe(6.0);
    });

    $tenant->delete();
});

test('an "out" line exceeding current stock is rejected', function () {
    $tenant = provisionStockAdjustmentTestTenant('stock-adjustment-oversell.tenant-test');

    $tenant->run(function () {
        $actor = stockAdjustmentTestActor();
        $item = Item::factory()->create(['is_stockable' => true]);

        StockAdjustment::post(
            ['date' => '2026-06-01'],
            [['item_id' => $item->id, 'direction' => 'in', 'reason_type' => 'opening', 'quantity' => 3]],
            $actor,
        );

        expect(fn () => StockAdjustment::post(
            ['date' => '2026-06-02'],
            [['item_id' => $item->id, 'direction' => 'out', 'reason_type' => 'correction', 'quantity' => 4]],
            $actor,
        ))->toThrow(InvalidArgumentException::class);

        expect($item->fresh()->currentStock())->toBe(3.0);
    });

    $tenant->delete();
});

test('damage and lost reasons are always zero-valued regardless of a supplied unit cost rate', function () {
    $tenant = provisionStockAdjustmentTestTenant('stock-adjustment-zero-value.tenant-test');

    $tenant->run(function () {
        $actor = stockAdjustmentTestActor();
        $item = Item::factory()->create(['is_stockable' => true]);

        StockAdjustment::post(
            ['date' => '2026-06-01'],
            [['item_id' => $item->id, 'direction' => 'in', 'reason_type' => 'opening', 'quantity' => 10]],
            $actor,
        );

        $adjustment = StockAdjustment::post(
            ['date' => '2026-06-02'],
            [['item_id' => $item->id, 'direction' => 'out', 'reason_type' => 'damage', 'quantity' => 2, 'unit_cost_rate' => 999]],
            $actor,
        );

        $line = $adjustment->lines->first();
        expect((float) $line->line_value)->toBe(0.0)
            ->and((float) $line->unit_cost_rate)->toBe(0.0)
            ->and((float) $adjustment->total_value)->toBe(0.0);
    });

    $tenant->delete();
});

test('reason_type opening forces direction to "in" even if "out" was passed', function () {
    $tenant = provisionStockAdjustmentTestTenant('stock-adjustment-opening-forces-in.tenant-test');

    $tenant->run(function () {
        $actor = stockAdjustmentTestActor();
        $item = Item::factory()->create(['is_stockable' => true]);

        $adjustment = StockAdjustment::post(
            ['date' => '2026-06-01'],
            [['item_id' => $item->id, 'direction' => 'out', 'reason_type' => 'opening', 'quantity' => 7]],
            $actor,
        );

        expect($adjustment->lines->first()->direction)->toBe('in')
            ->and($item->fresh()->currentStock())->toBe(7.0);

        $movement = ItemStockMovement::where('item_id', $item->id)->firstOrFail();
        expect($movement->movement_type)->toBe(StockMovementType::Opening);
    });

    $tenant->delete();
});

test('a zero or negative quantity is rejected at the model layer', function () {
    $tenant = provisionStockAdjustmentTestTenant('stock-adjustment-bad-quantity.tenant-test');

    $tenant->run(function () {
        $actor = stockAdjustmentTestActor();
        $item = Item::factory()->create(['is_stockable' => true]);

        expect(fn () => StockAdjustment::post(
            ['date' => '2026-06-01'],
            [['item_id' => $item->id, 'direction' => 'in', 'reason_type' => 'correction', 'quantity' => 0]],
            $actor,
        ))->toThrow(InvalidArgumentException::class);

        expect(fn () => StockAdjustment::post(
            ['date' => '2026-06-01'],
            [['item_id' => $item->id, 'direction' => 'in', 'reason_type' => 'correction', 'quantity' => -5]],
            $actor,
        ))->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('cancelling reverts the stock impact and rejects double-cancellation', function () {
    $tenant = provisionStockAdjustmentTestTenant('stock-adjustment-cancel.tenant-test');

    $tenant->run(function () {
        $actor = stockAdjustmentTestActor();
        $item = Item::factory()->create(['is_stockable' => true]);

        $adjustment = StockAdjustment::post(
            ['date' => '2026-06-01'],
            [['item_id' => $item->id, 'direction' => 'in', 'reason_type' => 'opening', 'quantity' => 8]],
            $actor,
        );

        expect($item->fresh()->currentStock())->toBe(8.0);

        $adjustment->cancel($actor, 'Recorded in error');

        $lineIds = $adjustment->lines()->pluck('id');
        expect(ItemStockMovement::query()
            ->where('reference_type', (new StockAdjustmentLine)->getMorphClass())
            ->whereIn('reference_id', $lineIds)
            ->where('cancelled', false)
            ->count())->toBe(0)
            ->and($item->fresh()->currentStock())->toBe(0.0)
            ->and($adjustment->fresh()->status)->toBe('cancelled');

        expect(fn () => $adjustment->cancel($actor, 'Again'))->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});
