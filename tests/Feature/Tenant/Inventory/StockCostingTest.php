<?php

use App\Enums\FiscalYearStatus;
use App\Enums\StockMovementType;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\ItemUnit;
use App\Models\Purchase;
use App\Models\StockAdjustment;
use App\Models\StockTransfer;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Inventory\StockCosting;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

/*
|--------------------------------------------------------------------------
| Stock costing and stock reads (CONTRACTS C10, audit P0-17)
|--------------------------------------------------------------------------
|
| The three defects these tests pin down:
|
| 1. Quantities were summed in PHP floats, so 0.1 + 0.2 - 0.3 left a
|    residue and taking the last 0.2 out of a 0.3 line was refused with a
|    message reading "0.19999999999999998".
| 2. A purchase stored the entered-unit GROSS rate against the BASE
|    quantity, so 2 Box of 12 at Rs 1,200 valued every piece at Rs 1,200 and
|    discounts never reached the valuation at all.
| 3. The weighted average was rounded to 4 decimals BEFORE being multiplied
|    back out, so 100,000 units at Rs 1 plus 200,000 at Rs 2 reported
|    Rs 500,010.00 of stock instead of Rs 500,000.00, and transfer-in rows
|    were counted as fresh purchases in the all-stores valuation.
|
*/

function provisionStockCostingTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function stockCostingActor(): User
{
    return User::factory()->create();
}

function stockCostingOpenFiscalYear(): FiscalYear
{
    return FiscalYear::firstOrCreate(
        ['name' => '2026'],
        ['start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open],
    );
}

function stockCostingDefaultStoreId(): int
{
    return (int) Store::where('is_active', true)->orderBy('id')->value('id');
}

test('current stock is exact after 0.1 + 0.2 - 0.3 movements', function () {
    $tenant = provisionStockCostingTenant('stock-costing-exact-quantity.tenant-test');

    $tenant->run(function () {
        stockCostingOpenFiscalYear();
        $actor = stockCostingActor();
        $item = Item::factory()->create(['is_stockable' => true]);

        StockAdjustment::post(
            ['date' => '2026-06-01'],
            [
                ['item_id' => $item->id, 'direction' => 'in', 'reason_type' => 'found', 'quantity' => '0.1'],
                ['item_id' => $item->id, 'direction' => 'in', 'reason_type' => 'found', 'quantity' => '0.2'],
            ],
            $actor,
        );

        expect($item->fresh()->currentStock()->toString())->toBe('0.3000');

        // The float sum made this 0.30000000000000004, so the guard below
        // refused to take the 0.3 back out and told the user its available
        // stock was "0.19999999999999998". It has to pass exactly.
        StockAdjustment::post(
            ['date' => '2026-06-02'],
            [['item_id' => $item->id, 'direction' => 'out', 'reason_type' => 'correction', 'quantity' => '0.3']],
            $actor,
        );

        expect($item->fresh()->currentStock()->toString())->toBe('0.0000');
    });

    $tenant->delete();
});

test('a Box purchase with line and header discounts is valued per base unit', function () {
    $tenant = provisionStockCostingTenant('stock-costing-box-discount.tenant-test');

    $tenant->run(function () {
        stockCostingOpenFiscalYear();
        $actor = stockCostingActor();
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => true, 'purchase_rate' => '999']);
        $box = ItemUnit::factory()->create(['item_id' => $item->id, 'name' => 'Box', 'conversion_factor' => 12]);

        // 2 Box at 1,200 = 2,400 gross, 10% off the line = 2,160, then 160
        // off the header = 2,000 net for 24 pieces.
        Purchase::post(
            [
                'supplier_id' => $supplier->id,
                'date' => '2026-06-01',
                'payment_mode' => 'credit',
                'discount' => '160',
                'discount_type' => 'flat',
            ],
            [[
                'item_id' => $item->id,
                'item_unit_id' => $box->id,
                'quantity' => '2',
                'rate' => '1200',
                'discount' => '10',
                'discount_type' => 'percentage',
            ]],
            $actor,
        );

        $item = $item->fresh();

        expect($item->currentStock()->toString())->toBe('24.0000');

        // 2,000 / 24, carried well past 4 decimals so the single rounding
        // below lands on the rupee value actually paid.
        expect((string) StockCosting::averageCost($item, '2026-12-31'))->toBe('83.333333333333');

        // Never 1,200 a piece (the entered-unit rate), never 2,160 (before
        // the header discount), never 2,260 (VAT included).
        expect(StockCosting::closingValue($item, '2026-12-31')->toString())->toBe('2000.00');
    });

    $tenant->delete();
});

test('100,000 units at 1 plus 200,000 at 2 are valued at exactly 500,000.00', function () {
    $tenant = provisionStockCostingTenant('stock-costing-large-average.tenant-test');

    $tenant->run(function () {
        stockCostingOpenFiscalYear();
        $actor = stockCostingActor();
        $item = Item::factory()->create(['is_stockable' => true]);

        StockAdjustment::post(
            ['date' => '2026-06-01'],
            [
                ['item_id' => $item->id, 'direction' => 'in', 'reason_type' => 'found', 'quantity' => '100000', 'unit_cost_rate' => '1'],
                ['item_id' => $item->id, 'direction' => 'in', 'reason_type' => 'found', 'quantity' => '200000', 'unit_cost_rate' => '2'],
            ],
            $actor,
        );

        $item = $item->fresh();

        expect($item->currentStock()->toString())->toBe('300000.0000');

        // The audit's worked example. Rounding the average to 4 decimals
        // first (1.6667) and multiplying afterwards gave 500,010.00.
        expect(StockCosting::closingValue($item, '2026-12-31')->toString())->toBe('500000.00')
            ->and(StockCosting::totalClosingValue('2026-12-31')->toString())->toBe('500000.00');
    });

    $tenant->delete();
});

test('a transfer between stores leaves the all-stores valuation unchanged', function () {
    $tenant = provisionStockCostingTenant('stock-costing-transfer-neutral.tenant-test');

    $tenant->run(function () {
        stockCostingOpenFiscalYear();
        $actor = stockCostingActor();
        $item = Item::factory()->create(['is_stockable' => true]);
        $source = Store::find(stockCostingDefaultStoreId());
        $destination = Store::factory()->create(['is_active' => true]);

        StockAdjustment::post(
            ['date' => '2026-06-01', 'store_id' => $source->id],
            [['item_id' => $item->id, 'direction' => 'in', 'reason_type' => 'found', 'quantity' => '10', 'unit_cost_rate' => '100']],
            $actor,
        );

        expect(StockCosting::totalClosingValue('2026-12-31')->toString())->toBe('1000.00');

        StockTransfer::post(
            [
                'date' => '2026-06-02',
                'from_store_id' => $source->id,
                'to_store_id' => $destination->id,
            ],
            [['item_id' => $item->id, 'quantity' => '4', 'unit_cost_rate' => '100']],
            $actor,
        );

        // The transfer-in row used to be counted as another 4 units of
        // purchased stock, so the all-stores total climbed to 1,400.00
        // (audit P0-17). Relocating your own goods changes nothing.
        expect(StockCosting::totalClosingValue('2026-12-31')->toString())->toBe('1000.00')
            ->and(StockCosting::totalClosingValue('2026-12-31', $source->id)->toString())->toBe('600.00')
            ->and(StockCosting::totalClosingValue('2026-12-31', $destination->id)->toString())->toBe('400.00');

        // One cost for the item, whichever store it is sitting in.
        expect((string) StockCosting::averageCost($item->fresh(), '2026-12-31'))->toBe('100.000000000000');
    });

    $tenant->delete();
});

test('an item with no priced movement falls back to its catalog purchase rate', function () {
    $tenant = provisionStockCostingTenant('stock-costing-fallback-rate.tenant-test');

    $tenant->run(function () {
        stockCostingOpenFiscalYear();
        $actor = stockCostingActor();
        $item = Item::factory()->create(['is_stockable' => true, 'purchase_rate' => '12.5']);

        // No rate on the line at all: the movement carries no value, so
        // there is no basis to average.
        StockAdjustment::post(
            ['date' => '2026-06-01'],
            [['item_id' => $item->id, 'direction' => 'in', 'reason_type' => 'found', 'quantity' => '8']],
            $actor,
        );

        $item = $item->fresh();

        expect(StockCosting::averageCost($item, '2026-12-31'))->toBeNull()
            ->and(StockCosting::closingValue($item, '2026-12-31')->toString())->toBe('100.00');

        $rows = StockCosting::valuationRows('2026-12-31');

        expect($rows)->toHaveCount(1)
            ->and($rows->first()['quantity']->toString())->toBe('8.0000')
            ->and($rows->first()['average_cost'])->toBe('12.5000')
            ->and($rows->first()['value']->toString())->toBe('100.00');
    });

    $tenant->delete();
});

test('an unpriced stock-in adds quantity but no cost basis', function () {
    $tenant = provisionStockCostingTenant('stock-costing-unpriced-in.tenant-test');

    $tenant->run(function () {
        stockCostingOpenFiscalYear();
        $actor = stockCostingActor();
        $item = Item::factory()->create(['is_stockable' => true, 'purchase_rate' => '1']);

        StockAdjustment::post(
            ['date' => '2026-06-01'],
            [['item_id' => $item->id, 'direction' => 'in', 'reason_type' => 'found', 'quantity' => '10', 'unit_cost_rate' => '50']],
            $actor,
        );

        // No rate given. Treating that as a cost of zero would halve the
        // average and value the whole 20 units at 25 a piece - hence the
        // null `value`, which keeps the movement out of the basis entirely.
        StockAdjustment::post(
            ['date' => '2026-06-02'],
            [['item_id' => $item->id, 'direction' => 'in', 'reason_type' => 'found', 'quantity' => '10']],
            $actor,
        );

        $item = $item->fresh();

        expect($item->currentStock()->toString())->toBe('20.0000')
            ->and((string) StockCosting::averageCost($item, '2026-12-31'))->toBe('50.000000000000')
            ->and(StockCosting::closingValue($item, '2026-12-31')->toString())->toBe('1000.00');
    });

    $tenant->delete();
});

test('a purchase return takes its value and quantity back out of the cost basis', function () {
    $tenant = provisionStockCostingTenant('stock-costing-purchase-return.tenant-test');

    $tenant->run(function () {
        stockCostingOpenFiscalYear();
        $item = Item::factory()->create(['is_stockable' => true, 'purchase_rate' => '1']);
        $storeId = stockCostingDefaultStoreId();

        // Recorded directly rather than through Purchase/PurchaseReturn:
        // this test is about what StockCosting does with the two movement
        // types, not about how the purchase module produces them.
        $item->recordStockMovement(StockMovementType::Purchase, '10', '2026-06-01', $storeId, null, null, '1000.00');
        $item->recordStockMovement(StockMovementType::PurchaseReturn, '4', '2026-06-02', $storeId, null, null, '400.00');

        $item = $item->fresh();

        expect($item->currentStock()->toString())->toBe('6.0000')
            ->and((string) StockCosting::averageCost($item, '2026-12-31'))->toBe('100.000000000000')
            ->and(StockCosting::closingValue($item, '2026-12-31')->toString())->toBe('600.00');
    });

    $tenant->delete();
});

test('valuation is bounded by the as-of date', function () {
    $tenant = provisionStockCostingTenant('stock-costing-as-of.tenant-test');

    $tenant->run(function () {
        stockCostingOpenFiscalYear();
        $actor = stockCostingActor();
        $item = Item::factory()->create(['is_stockable' => true]);

        StockAdjustment::post(
            ['date' => '2026-06-01'],
            [['item_id' => $item->id, 'direction' => 'in', 'reason_type' => 'found', 'quantity' => '10', 'unit_cost_rate' => '100']],
            $actor,
        );

        StockAdjustment::post(
            ['date' => '2026-07-01'],
            [['item_id' => $item->id, 'direction' => 'in', 'reason_type' => 'found', 'quantity' => '10', 'unit_cost_rate' => '200']],
            $actor,
        );

        $item = $item->fresh();

        expect($item->currentStock(null, '2026-06-30')->toString())->toBe('10.0000')
            ->and((string) StockCosting::averageCost($item, '2026-06-30'))->toBe('100.000000000000')
            ->and(StockCosting::closingValue($item, '2026-06-30')->toString())->toBe('1000.00')
            ->and(StockCosting::closingValue($item, '2026-12-31')->toString())->toBe('3000.00');
    });

    $tenant->delete();
});

test('a cancelled adjustment leaves nothing behind in the valuation', function () {
    $tenant = provisionStockCostingTenant('stock-costing-cancelled.tenant-test');

    $tenant->run(function () {
        stockCostingOpenFiscalYear();
        $actor = stockCostingActor();
        $item = Item::factory()->create(['is_stockable' => true, 'purchase_rate' => null]);

        $adjustment = StockAdjustment::post(
            ['date' => '2026-06-01'],
            [['item_id' => $item->id, 'direction' => 'in', 'reason_type' => 'found', 'quantity' => '10', 'unit_cost_rate' => '100']],
            $actor,
        );

        $adjustment->cancel($actor, 'Recorded in error');

        $item = $item->fresh();

        expect($item->currentStock()->toString())->toBe('0.0000')
            ->and(StockCosting::averageCost($item, '2026-12-31'))->toBeNull()
            ->and(StockCosting::closingValue($item, '2026-12-31')->toString())->toBe('0.00')
            ->and(StockCosting::valuationRows('2026-12-31'))->toHaveCount(0);
    });

    $tenant->delete();
});
