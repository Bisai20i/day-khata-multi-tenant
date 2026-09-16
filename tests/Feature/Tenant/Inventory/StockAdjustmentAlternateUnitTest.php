<?php

use App\Enums\FiscalYearStatus;
use App\Enums\StockMovementType;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\ItemStockMovement;
use App\Models\ItemUnit;
use App\Models\StockAdjustment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

/*
|--------------------------------------------------------------------------
| Stock adjustment alternate-unit entry (T13 item 7)
|--------------------------------------------------------------------------
|
| A clerk counting "3 Box" should not have to convert to pieces by hand.
| StockAdjustment::post()/resolveItemUnit() mirrors Purchase/Sale's own
| alternate-unit handling exactly: the stored line keeps the as-entered
| quantity and unit, while every stock-side effect (the ItemStockMovement,
| the negative-stock guard, current stock) uses the BASE quantity
| (quantity x conversion_factor), computed once (CONTRACTS C10).
|
*/

function stockAdjustmentAltUnitOpenFiscalYear(): FiscalYear
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

function provisionStockAdjustmentAltUnitTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function stockAdjustmentAltUnitActor(): User
{
    return User::factory()->create();
}

test('an "in" adjustment entered in an alternate unit records the as-entered line and a base-quantity stock movement', function () {
    $tenant = provisionStockAdjustmentAltUnitTenant('stock-adjustment-alt-unit-in.tenant-test');

    $tenant->run(function () {
        stockAdjustmentAltUnitOpenFiscalYear();
        $actor = stockAdjustmentAltUnitActor();
        $item = Item::factory()->create(['unit' => 'pcs', 'is_stockable' => true]);
        $box = ItemUnit::factory()->create(['item_id' => $item->id, 'name' => 'Box', 'conversion_factor' => 12]);

        $adjustment = StockAdjustment::post(
            ['date' => '2026-06-01', 'note' => 'Counted extra stock in boxes'],
            [['item_id' => $item->id, 'item_unit_id' => $box->id, 'direction' => 'in', 'reason_type' => 'found', 'quantity' => 3, 'unit_cost_rate' => 100]],
            $actor,
        );

        $line = $adjustment->lines()->firstOrFail();

        // As-entered quantity/unit are exactly what was submitted.
        expect((float) $line->quantity)->toBe(3.0)
            ->and($line->item_unit_id)->toBe($box->id)
            ->and((float) $line->unit_conversion_factor)->toBe(12.0)
            // The value is priced against the BASE quantity: 3 Box * 12 = 36 pcs @ 100 = 3,600.00.
            ->and((float) $line->line_value)->toBe(3600.0)
            ->and((float) $adjustment->total_value)->toBe(3600.0);

        $movement = ItemStockMovement::where('item_id', $item->id)
            ->where('movement_type', StockMovementType::AdjustmentIn)
            ->firstOrFail();
        expect((float) $movement->quantity)->toBe(36.0)
            ->and($item->fresh()->currentStock()->toString())->toBe('36.0000');
    });

    $tenant->delete();
});

test('an "out" adjustment entered in an alternate unit is capped by base-unit stock on hand', function () {
    $tenant = provisionStockAdjustmentAltUnitTenant('stock-adjustment-alt-unit-out.tenant-test');

    $tenant->run(function () {
        stockAdjustmentAltUnitOpenFiscalYear();
        $actor = stockAdjustmentAltUnitActor();
        $item = Item::factory()->create(['unit' => 'pcs', 'is_stockable' => true]);
        $box = ItemUnit::factory()->create(['item_id' => $item->id, 'name' => 'Box', 'conversion_factor' => 12]);

        // Stock 2 Box = 24 pcs.
        StockAdjustment::post(
            ['date' => '2026-06-01'],
            [['item_id' => $item->id, 'item_unit_id' => $box->id, 'direction' => 'in', 'reason_type' => 'found', 'quantity' => 2, 'unit_cost_rate' => 100]],
            $actor,
        );
        expect($item->fresh()->currentStock()->toString())->toBe('24.0000');

        // Removing 3 Box (36 pcs) would go below zero at 24 pcs on hand.
        StockAdjustment::post(
            ['date' => '2026-06-02'],
            [['item_id' => $item->id, 'item_unit_id' => $box->id, 'direction' => 'out', 'reason_type' => 'damage', 'quantity' => 3]],
            $actor,
        );
    });
})->throws(InvalidArgumentException::class);

test('an item_unit_id belonging to a different item is rejected', function () {
    $tenant = provisionStockAdjustmentAltUnitTenant('stock-adjustment-alt-unit-cross-item.tenant-test');

    $tenant->run(function () {
        stockAdjustmentAltUnitOpenFiscalYear();
        $actor = stockAdjustmentAltUnitActor();
        $item = Item::factory()->create(['unit' => 'pcs', 'is_stockable' => true]);
        $otherItem = Item::factory()->create(['unit' => 'pcs', 'is_stockable' => true]);
        $otherBox = ItemUnit::factory()->create(['item_id' => $otherItem->id, 'name' => 'Box', 'conversion_factor' => 12]);

        StockAdjustment::post(
            ['date' => '2026-06-01'],
            [['item_id' => $item->id, 'item_unit_id' => $otherBox->id, 'direction' => 'in', 'reason_type' => 'found', 'quantity' => 1, 'unit_cost_rate' => 100]],
            $actor,
        );
    });
})->throws(InvalidArgumentException::class);
