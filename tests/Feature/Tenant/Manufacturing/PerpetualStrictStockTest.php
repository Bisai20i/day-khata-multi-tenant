<?php

use App\Enums\FiscalYearStatus;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\MaterialIssueNote;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\StockAdjustment;
use App\Models\StockTransfer;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * M08b: raw material and finished goods always enforce stock on every
 * stock-out path, ignoring company_settings.allow_negative_stock - their
 * moving average is value / quantity on hand, meaningless at zero or below.
 * Every test here runs with the setting ON, so a refusal can only come from
 * the perpetual rule; trading items keep overselling as before.
 */

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionStrictStockTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

/**
 * The open year, an owner, a customer, the main store, and negative stock
 * allowed for the whole tenant.
 *
 * @return array{admin: User, customer: Customer, store: Store}
 */
function strictStockSetup(): array
{
    FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
    CompanySetting::current()->update(['allow_negative_stock' => true]);

    return [
        'admin' => User::factory()->create(),
        'customer' => Customer::factory()->create(),
        'store' => Store::where('is_active', true)->orderBy('id')->firstOrFail(),
    ];
}

function strictStockBuy(array $context, Item $item, string $quantity, string $rate = '50', string $date = '2026-06-01'): Purchase
{
    return Purchase::post(
        [
            'supplier_id' => Supplier::factory()->create()->id,
            'date' => $date,
            'payment_mode' => 'credit',
            'store_id' => $context['store']->id,
            'force_non_taxable' => true,
        ],
        [['item_id' => $item->id, 'quantity' => $quantity, 'rate' => $rate]],
        $context['admin'],
    );
}

/**
 * @param  array<int, array<string, mixed>>  $lines
 */
function strictStockSell(array $context, array $lines, string $date = '2026-06-10'): Sale
{
    return Sale::post(
        ['customer_id' => $context['customer']->id, 'invoice_type' => 'full', 'date' => $date, 'payment_mode' => 'credit', 'store_id' => $context['store']->id],
        $lines,
        $context['admin'],
    );
}

test('overselling a finished good is refused with the shortfall, while overselling a trading item still succeeds', function () {
    provisionStrictStockTenant('strict-stock-sale.tenant-test')->run(function () {
        $context = strictStockSetup();
        $finished = Item::factory()->finishedGood()->create(['name' => 'Packed Tea', 'is_vatable' => false]);
        $trading = Item::factory()->create(['is_stockable' => true, 'is_vatable' => false]);

        strictStockBuy($context, $finished, '5');

        expect(fn () => strictStockSell($context, [['item_id' => $finished->id, 'quantity' => 6, 'rate' => 100, 'discount' => 0]]))
            ->toThrow(InvalidArgumentException::class, 'Insufficient stock for "Packed Tea" at this store (available 5, required 6, short by 1)');

        expect(Sale::count())->toBe(0)
            ->and($finished->currentStock($context['store']->id)->formatQuantity())->toBe('5');

        // A trading item with nothing on hand still oversells: the tenant's
        // setting is honoured exactly as before.
        $sale = strictStockSell($context, [['item_id' => $trading->id, 'quantity' => 3, 'rate' => 100, 'discount' => 0]]);

        expect($sale->exists)->toBeTrue()
            ->and($trading->currentStock($context['store']->id)->formatQuantity())->toBe('-3');
    });
});

test('a backdated sale of a finished good cannot reach before the stock arrived', function () {
    provisionStrictStockTenant('strict-stock-backdated-sale.tenant-test')->run(function () {
        $context = strictStockSetup();
        $finished = Item::factory()->finishedGood()->create(['is_vatable' => false]);

        strictStockBuy($context, $finished, '5', '50', '2026-06-05');

        expect(fn () => strictStockSell($context, [['item_id' => $finished->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]], '2026-06-01'))
            ->toThrow(InvalidArgumentException::class, 'short by 1');
    });
});

test('two lines of the same finished good are aggregated against the stock', function () {
    provisionStrictStockTenant('strict-stock-aggregate.tenant-test')->run(function () {
        $context = strictStockSetup();
        $finished = Item::factory()->finishedGood()->create(['is_vatable' => false]);

        strictStockBuy($context, $finished, '5');

        // Each line alone (3 of 5) would pass; together they need 6.
        expect(fn () => strictStockSell($context, [
            ['item_id' => $finished->id, 'quantity' => 3, 'rate' => 100, 'discount' => 0],
            ['item_id' => $finished->id, 'quantity' => 3, 'rate' => 100, 'discount' => 0],
        ]))->toThrow(InvalidArgumentException::class, '(available 5, required 6, short by 1)');

        expect(Sale::count())->toBe(0);
    });
});

test('cancelling a raw-material purchase whose stock was already issued is refused, a trading one still cancels', function () {
    provisionStrictStockTenant('strict-stock-purchase-cancel.tenant-test')->run(function () {
        $context = strictStockSetup();
        $raw = Item::factory()->rawMaterial()->create(['is_vatable' => false]);
        $trading = Item::factory()->create(['is_stockable' => true, 'is_vatable' => false]);

        $rawPurchase = strictStockBuy($context, $raw, '10');

        MaterialIssueNote::post(
            ['date' => '2026-06-05', 'store_id' => $context['store']->id],
            [['item_id' => $raw->id, 'quantity' => '4']],
            $context['admin'],
        );

        expect(fn () => $rawPurchase->cancel($context['admin'], 'Wrong supplier'))
            ->toThrow(InvalidArgumentException::class, 'Cannot cancel this purchase: Insufficient stock for');

        expect($rawPurchase->fresh()->status)->not->toBe('cancelled')
            ->and($raw->currentStock($context['store']->id)->formatQuantity())->toBe('6');

        // The trading bill's stock has been sold on, but the setting still
        // lets it be cancelled, exactly as before.
        $tradingPurchase = strictStockBuy($context, $trading, '2');
        strictStockSell($context, [['item_id' => $trading->id, 'quantity' => 2, 'rate' => 100, 'discount' => 0]]);

        $tradingPurchase->cancel($context['admin'], 'Wrong supplier');

        expect($tradingPurchase->fresh()->status)->toBe('cancelled')
            ->and($trading->currentStock($context['store']->id)->formatQuantity())->toBe('-2');
    });
});

test('a perpetual adjustment out beyond stock is refused, also when backdated before the receipt', function () {
    provisionStrictStockTenant('strict-stock-adjustment.tenant-test')->run(function () {
        $context = strictStockSetup();
        $raw = Item::factory()->rawMaterial()->create(['name' => 'Raw Leaf', 'is_vatable' => false]);

        strictStockBuy($context, $raw, '5', '50', '2026-06-05');

        expect(fn () => StockAdjustment::post(
            ['date' => '2026-06-10', 'store_id' => $context['store']->id],
            [['item_id' => $raw->id, 'direction' => 'out', 'reason_type' => 'damage', 'quantity' => '6']],
            $context['admin'],
        ))->toThrow(InvalidArgumentException::class, 'Insufficient stock for "Raw Leaf" at this store (available 5, required 6, short by 1)');

        // 2 of 5 is on hand today, but not on 1 June: the old check against
        // today's stock let this through.
        expect(fn () => StockAdjustment::post(
            ['date' => '2026-06-01', 'store_id' => $context['store']->id],
            [['item_id' => $raw->id, 'direction' => 'out', 'reason_type' => 'damage', 'quantity' => '2']],
            $context['admin'],
        ))->toThrow(InvalidArgumentException::class, 'short by 2');

        expect(StockAdjustment::count())->toBe(0);
    });
});

test('cancelling a perpetual adjustment in whose stock was already used is refused', function () {
    provisionStrictStockTenant('strict-stock-adjustment-cancel.tenant-test')->run(function () {
        $context = strictStockSetup();
        $raw = Item::factory()->rawMaterial()->create(['is_vatable' => false]);

        $found = StockAdjustment::post(
            ['date' => '2026-06-01', 'store_id' => $context['store']->id],
            [['item_id' => $raw->id, 'direction' => 'in', 'reason_type' => 'found', 'quantity' => '5', 'unit_cost_rate' => '10']],
            $context['admin'],
        );

        StockAdjustment::post(
            ['date' => '2026-06-05', 'store_id' => $context['store']->id],
            [['item_id' => $raw->id, 'direction' => 'out', 'reason_type' => 'damage', 'quantity' => '3']],
            $context['admin'],
        );

        expect(fn () => $found->fresh()->cancel($context['admin'], 'Counted again'))
            ->toThrow(InvalidArgumentException::class, 'Cannot cancel this stock adjustment: Insufficient stock for');

        expect($found->fresh()->status)->not->toBe('cancelled');
    });
});

test('a transfer of a perpetual item beyond the source store stock is refused', function () {
    provisionStrictStockTenant('strict-stock-transfer.tenant-test')->run(function () {
        $context = strictStockSetup();
        $branch = Store::create(['name' => 'Branch', 'is_active' => true]);
        $raw = Item::factory()->rawMaterial()->create(['is_vatable' => false]);

        strictStockBuy($context, $raw, '5');

        expect(fn () => StockTransfer::post(
            ['date' => '2026-06-05', 'from_store_id' => $context['store']->id, 'to_store_id' => $branch->id],
            [['item_id' => $raw->id, 'quantity' => '4'], ['item_id' => $raw->id, 'quantity' => '2']],
            $context['admin'],
        ))->toThrow(InvalidArgumentException::class, 'short by 1');

        expect(StockTransfer::count())->toBe(0);
    });
});

test('cancelling a transfer whose perpetual stock was used at the destination is refused', function () {
    provisionStrictStockTenant('strict-stock-transfer-cancel.tenant-test')->run(function () {
        $context = strictStockSetup();
        $branch = Store::create(['name' => 'Branch', 'is_active' => true]);
        $raw = Item::factory()->rawMaterial()->create(['is_vatable' => false]);

        strictStockBuy($context, $raw, '10');

        $transfer = StockTransfer::post(
            ['date' => '2026-06-05', 'from_store_id' => $context['store']->id, 'to_store_id' => $branch->id],
            [['item_id' => $raw->id, 'quantity' => '4']],
            $context['admin'],
        );

        StockAdjustment::post(
            ['date' => '2026-06-06', 'store_id' => $branch->id],
            [['item_id' => $raw->id, 'direction' => 'out', 'reason_type' => 'damage', 'quantity' => '3']],
            $context['admin'],
        );

        expect(fn () => $transfer->fresh()->cancel($context['admin'], 'Wrong store'))
            ->toThrow(InvalidArgumentException::class, 'Cannot cancel this stock transfer: Insufficient stock for');

        expect($transfer->fresh()->status)->not->toBe('cancelled');
    });
});
