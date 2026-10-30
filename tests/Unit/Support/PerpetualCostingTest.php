<?php

use App\Enums\InventoryClass;
use App\Enums\StockMovementType;
use App\Models\Item;
use App\Models\ItemStockMovement;
use App\Models\Store;
use App\Models\Tenant;
use App\Support\Inventory\PerpetualCosting;
use App\Support\Inventory\StockCosting;
use App\Support\Money\Money;
use App\Support\Money\Quantity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Perpetual costing (manufacturing MC3)
|--------------------------------------------------------------------------
|
| The moving weighted average behind raw material and finished good items.
| It needs a tenant database (the balances are summed in SQL), so this file
| opts in to TestCase and RefreshDatabase like the Permissions unit tests
| opt in to TestCase. Movements are written straight through
| Item::recordStockMovement(), the single writer every document uses, so
| these tests pin the engine without depending on any document's posting.
|
*/

uses(TestCase::class, RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionPerpetualCostingTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function perpetualCostingStoreId(): int
{
    return (int) Store::where('is_active', true)->orderBy('id')->value('id');
}

/**
 * Raw cost_value of a movement as read back from the database, as a Money
 * string. Read fresh so the assertion is about what was stored, not about
 * the in-memory attribute.
 */
function storedCostValue(ItemStockMovement $movement): ?string
{
    $raw = ItemStockMovement::query()->whereKey($movement->getKey())->toBase()->value('cost_value');

    return $raw === null ? null : Money::of($raw)->toString();
}

test('buying 10 at 100 and 10 at 130 then issuing 15 costs 1725.00 and leaves 575.00', function () {
    $tenant = provisionPerpetualCostingTenant('perpetual-moving-average.tenant-test');

    $tenant->run(function () {
        $store = perpetualCostingStoreId();
        $item = Item::factory()->rawMaterial()->create();

        $item->recordStockMovement(StockMovementType::Purchase, '10', '2026-06-01', $store, value: '1000.00');
        $item->recordStockMovement(StockMovementType::Purchase, '10', '2026-06-02', $store, value: '1300.00');

        expect(PerpetualCosting::movingAverage($item, '2026-06-02')->toString())->toBe('115.000000000000');

        $issue = $item->recordStockMovement(StockMovementType::MaterialIssue, '15', '2026-06-03', $store);

        expect($issue->cost_value)->toBe('1725.00')
            ->and(storedCostValue($issue))->toBe('1725.00')
            ->and(PerpetualCosting::valueBalance($item)->toString())->toBe('575.00');

        $last = $item->recordStockMovement(StockMovementType::MaterialIssue, '5', '2026-06-04', $store);

        expect($last->cost_value)->toBe('575.00')
            ->and(PerpetualCosting::valueBalance($item)->toString())->toBe('0.00')
            ->and($item->currentStock()->toString())->toBe('0.0000');
    });

    $tenant->delete();
});

test('issuing everything after purchases at awkward rates leaves exactly 0.00, with no residue', function () {
    $tenant = provisionPerpetualCostingTenant('perpetual-no-residue.tenant-test');

    $tenant->run(function () {
        $store = perpetualCostingStoreId();
        $item = Item::factory()->rawMaterial()->create();

        $item->recordStockMovement(StockMovementType::Purchase, '3', '2026-06-01', $store, value: '10.00');
        $item->recordStockMovement(StockMovementType::Purchase, '7', '2026-06-02', $store, value: '23.33');
        $item->recordStockMovement(StockMovementType::Purchase, '13', '2026-06-03', $store, value: '47.11');

        $costs = [];
        // 80.44 / 23 = 3.497391304348 -> 5 x that = 17.49
        $costs[] = $item->recordStockMovement(StockMovementType::MaterialIssue, '5', '2026-06-04', $store)->cost_value;
        // 62.95 / 18 = 3.497222222222 -> 7 x that = 24.48
        $costs[] = $item->recordStockMovement(StockMovementType::MaterialIssue, '7', '2026-06-05', $store)->cost_value;
        // The whole remaining 11 takes the whole remaining 38.47.
        $costs[] = $item->recordStockMovement(StockMovementType::MaterialIssue, '11', '2026-06-06', $store)->cost_value;

        expect($costs)->toBe(['17.49', '24.48', '38.47'])
            ->and(Money::sum($costs)->toString())->toBe('80.44')
            ->and(PerpetualCosting::valueBalance($item)->toString())->toBe('0.00')
            ->and(PerpetualCosting::movingAverage($item, '2026-06-06'))->toBeNull();
    });

    $tenant->delete();
});

test('a backdated purchase does not re-cost an issue already posted after it', function () {
    $tenant = provisionPerpetualCostingTenant('perpetual-backdated.tenant-test');

    $tenant->run(function () {
        $store = perpetualCostingStoreId();
        $item = Item::factory()->rawMaterial()->create();

        $item->recordStockMovement(StockMovementType::Purchase, '10', '2026-06-01', $store, value: '1000.00');
        $earlierIssue = $item->recordStockMovement(StockMovementType::MaterialIssue, '5', '2026-06-10', $store);

        // Entered later, dated before the issue.
        $item->recordStockMovement(StockMovementType::Purchase, '10', '2026-06-05', $store, value: '2000.00');

        expect(storedCostValue($earlierIssue))->toBe('500.00');

        // As of 2026-06-15: 15 units worth 1000 + 2000 - 500 = 2500.
        $nextIssue = $item->recordStockMovement(StockMovementType::MaterialIssue, '5', '2026-06-15', $store);

        expect($nextIssue->cost_value)->toBe('833.33')
            ->and(PerpetualCosting::valueBalance($item)->toString())->toBe('1666.67');

        $rest = $item->recordStockMovement(StockMovementType::MaterialIssue, '10', '2026-06-16', $store);

        expect($rest->cost_value)->toBe('1666.67')
            ->and(PerpetualCosting::valueBalance($item)->toString())->toBe('0.00');
    });

    $tenant->delete();
});

test('with no moving average the cost falls back to the last priced stock-in, then to purchase_rate', function () {
    $tenant = provisionPerpetualCostingTenant('perpetual-fallbacks.tenant-test');

    $tenant->run(function () {
        $store = perpetualCostingStoreId();

        // Nothing has ever come in: the catalog purchase rate is the basis.
        $fresh = Item::factory()->rawMaterial()->create(['purchase_rate' => '12.5000']);

        expect(PerpetualCosting::movingAverage($fresh, '2026-06-01'))->toBeNull()
            ->and(PerpetualCosting::issueCost($fresh, Quantity::of('4'), '2026-06-01')->toString())->toBe('50.00');

        $adjusted = $fresh->recordStockMovement(StockMovementType::AdjustmentOut, '4', '2026-06-01', $store);
        expect($adjusted->cost_value)->toBe('50.00');

        // Stock went to zero, then negative: no average, so the last priced
        // in-movement's own rate (1000 / 10 = 100) wins over purchase_rate.
        $used = Item::factory()->rawMaterial()->create(['purchase_rate' => '7.0000']);
        $used->recordStockMovement(StockMovementType::Purchase, '10', '2026-06-01', $store, value: '1000.00');
        $used->recordStockMovement(StockMovementType::MaterialIssue, '10', '2026-06-02', $store);

        expect(PerpetualCosting::issueCost($used, Quantity::of('2'), '2026-06-03')->toString())->toBe('200.00');
    });

    $tenant->delete();
});

test('an issue with no cost basis at all throws', function () {
    $tenant = provisionPerpetualCostingTenant('perpetual-no-basis.tenant-test');

    $tenant->run(function () {
        $item = Item::factory()->rawMaterial()->create(['purchase_rate' => null]);

        expect(fn () => PerpetualCosting::issueCost($item, Quantity::of('1'), '2026-06-01'))
            ->toThrow(InvalidArgumentException::class, 'no cost basis');

        expect(fn () => $item->recordStockMovement(StockMovementType::MaterialIssue, '1', '2026-06-01', perpetualCostingStoreId()))
            ->toThrow(InvalidArgumentException::class);

        expect(ItemStockMovement::query()->where('item_id', $item->id)->count())->toBe(0);
    });

    $tenant->delete();
});

test('an unpriced stock-in of a perpetual item throws and writes nothing', function () {
    $tenant = provisionPerpetualCostingTenant('perpetual-unpriced-in.tenant-test');

    $tenant->run(function () {
        $item = Item::factory()->finishedGood()->create(['purchase_rate' => '50.0000']);

        expect(fn () => $item->recordStockMovement(StockMovementType::AdjustmentIn, '5', '2026-06-01', perpetualCostingStoreId()))
            ->toThrow(InvalidArgumentException::class, 'must carry its cost');

        expect(ItemStockMovement::query()->where('item_id', $item->id)->count())->toBe(0);
    });

    $tenant->delete();
});

test('an explicit cost value wins over the value for an in-movement', function () {
    $tenant = provisionPerpetualCostingTenant('perpetual-explicit-cost.tenant-test');

    $tenant->run(function () {
        $item = Item::factory()->finishedGood()->create();

        $receipt = $item->recordStockMovement(StockMovementType::ProductionReceipt, '4', '2026-06-01', perpetualCostingStoreId(), costValue: '1234.56');

        expect($receipt->cost_value)->toBe('1234.56')
            ->and($receipt->value)->toBeNull()
            ->and(PerpetualCosting::valueBalance($item)->toString())->toBe('1234.56');
    });

    $tenant->delete();
});

test('transfer legs carry the same cost and net to zero across stores', function () {
    $tenant = provisionPerpetualCostingTenant('perpetual-transfer.tenant-test');

    $tenant->run(function () {
        $main = perpetualCostingStoreId();
        $branch = Store::factory()->create()->id;
        $item = Item::factory()->rawMaterial()->create();

        $item->recordStockMovement(StockMovementType::Purchase, '10', '2026-06-01', $main, value: '1000.00');

        $out = $item->recordStockMovement(StockMovementType::TransferOut, '4', '2026-06-02', $main);
        $in = $item->recordStockMovement(StockMovementType::TransferIn, '4', '2026-06-02', $branch, costValue: $out->cost_value);

        expect($out->cost_value)->toBe('400.00')
            ->and($in->cost_value)->toBe('400.00')
            ->and(PerpetualCosting::valueBalance($item)->toString())->toBe('1000.00')
            ->and(PerpetualCosting::valueBalance($item, null, $main)->toString())->toBe('600.00')
            ->and(PerpetualCosting::valueBalance($item, null, $branch)->toString())->toBe('400.00')
            ->and(PerpetualCosting::movingAverage($item, '2026-06-02')->toString())->toBe('100.000000000000');
    });

    $tenant->delete();
});

test('cancelled movements and trading items are outside the perpetual balances', function () {
    $tenant = provisionPerpetualCostingTenant('perpetual-cancelled-trading.tenant-test');

    $tenant->run(function () {
        $store = perpetualCostingStoreId();
        $raw = Item::factory()->rawMaterial()->create();
        $finished = Item::factory()->finishedGood()->create();
        $trading = Item::factory()->create(['is_stockable' => true]);

        $raw->recordStockMovement(StockMovementType::Purchase, '10', '2026-06-01', $store, value: '1000.00');
        $cancelled = $raw->recordStockMovement(StockMovementType::Purchase, '10', '2026-06-01', $store, value: '9999.00');
        $cancelled->update(['cancelled' => true]);
        $finished->recordStockMovement(StockMovementType::ProductionReceipt, '2', '2026-06-01', $store, costValue: '300.00');
        $tradingMovement = $trading->recordStockMovement(StockMovementType::Purchase, '5', '2026-06-01', $store, value: '250.00');

        expect($tradingMovement->cost_value)->toBeNull()
            ->and(storedCostValue($tradingMovement))->toBeNull()
            ->and(array_map(
                fn (Money $value) => $value->toString(),
                PerpetualCosting::valueBalanceByItem([$raw->id, $finished->id, $trading->id], '2026-06-30'),
            ))->toBe([$raw->id => '1000.00', $finished->id => '300.00', $trading->id => '0.00'])
            ->and(PerpetualCosting::classValue(InventoryClass::RawMaterial, '2026-06-30')->toString())->toBe('1000.00')
            ->and(PerpetualCosting::classValue(InventoryClass::FinishedGood, '2026-06-30')->toString())->toBe('300.00')
            ->and(PerpetualCosting::classValue(InventoryClass::RawMaterial, '2026-05-31')->toString())->toBe('0.00');
    });

    $tenant->delete();
});

test('an item created without the class loaded still gets its movements costed', function () {
    $tenant = provisionPerpetualCostingTenant('perpetual-partial-columns.tenant-test');

    $tenant->run(function () {
        $store = perpetualCostingStoreId();
        $created = Item::factory()->rawMaterial()->create();

        // A subset-of-columns load, as a list query might do.
        $partial = Item::query()->whereKey($created->id)->first(['id', 'name', 'purchase_rate']);

        expect(fn () => $partial->recordStockMovement(StockMovementType::Purchase, '1', '2026-06-01', $store))
            ->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('the periodic closing total excludes perpetual items while the valuation rows keep them', function () {
    $tenant = provisionPerpetualCostingTenant('perpetual-periodic-split.tenant-test');

    $tenant->run(function () {
        $store = perpetualCostingStoreId();
        $trading = Item::factory()->create(['is_stockable' => true, 'name' => 'A Trading Item']);
        $raw = Item::factory()->rawMaterial()->create(['name' => 'B Raw Item']);

        $trading->recordStockMovement(StockMovementType::Opening, '10', '2026-06-01', $store, value: '1000.00');
        $raw->recordStockMovement(StockMovementType::Purchase, '10', '2026-06-01', $store, value: '1000.00');
        $raw->recordStockMovement(StockMovementType::MaterialIssue, '3', '2026-06-02', $store);

        expect(StockCosting::totalPeriodicClosingValue('2026-06-30')->toString())->toBe('1000.00')
            ->and(StockCosting::totalClosingValue('2026-06-30')->toString())->toBe('1700.00');

        $rows = StockCosting::valuationRows('2026-06-30')->keyBy('item_id');

        expect($rows[$trading->id]['inventory_class'])->toBe('trading')
            ->and($rows[$trading->id]['value']->toString())->toBe('1000.00')
            ->and($rows[$raw->id]['inventory_class'])->toBe('raw_material')
            ->and($rows[$raw->id]['quantity']->toString())->toBe('7.0000')
            ->and($rows[$raw->id]['value']->toString())->toBe('700.00')
            ->and($rows[$raw->id]['average_cost'])->toBe('100.0000');
    });

    $tenant->delete();
});
