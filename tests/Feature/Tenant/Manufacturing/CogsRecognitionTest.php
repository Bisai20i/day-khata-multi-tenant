<?php

use App\Enums\FiscalYearStatus;
use App\Enums\StockMovementType;
use App\Enums\VoucherType;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\ItemStockMovement;
use App\Models\JournalVoucher;
use App\Models\JournalVoucherLine;
use App\Models\Sale;
use App\Models\SalesReturn;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Manufacturing\ManufacturingAccounts;
use App\Support\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * MC8 (sales side): a perpetual line relieves inventory at cost inside the
 * sale's own voucher (Dr EXE10 / Cr AS14), a return puts the cost back
 * (Dr AS14 / Cr EXE10), cancel mirrors both, and P1 - the subledger's
 * cost_value balance equals the class account's GL balance - holds after
 * every step. Trading lines post no COGS at all.
 */

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionCogsTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

/**
 * The open year, an owner, a customer, the main store and a finished good
 * with 100 units produced for 1,560.00 (moving average 15.60). The
 * completion is written the way MC6 does it: a ProductionReceipt movement
 * carrying the cost, and Dr AS14 / Cr AS13 for the same amount.
 *
 * @return array{admin: User, customer: Customer, store: Store, item: Item}
 */
function cogsTestProducedFinishedGood(): array
{
    FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);

    $admin = User::factory()->create();
    $customer = Customer::factory()->create();
    $store = Store::where('is_active', true)->orderBy('id')->firstOrFail();
    $item = Item::factory()->finishedGood()->create(['is_vatable' => false]);

    JournalVoucher::post(
        ['voucher_type' => VoucherType::Production->value, 'date' => '2026-06-01', 'narration' => 'Production completion'],
        [
            ['account_id' => ManufacturingAccounts::id(ManufacturingAccounts::FINISHED_GOODS), 'debit' => '1560.00', 'credit' => '0'],
            ['account_id' => ManufacturingAccounts::id(ManufacturingAccounts::WIP), 'debit' => '0', 'credit' => '1560.00'],
        ],
        $admin,
    );

    $item->recordStockMovement(StockMovementType::ProductionReceipt, '100', '2026-06-01', $store->id, costValue: '1560.00');

    return ['admin' => $admin, 'customer' => $customer, 'store' => $store, 'item' => $item->fresh()];
}

/**
 * @param  array<int, array<string, mixed>>  $lines
 */
function cogsTestSell(array $context, array $lines, string $date = '2026-06-10'): Sale
{
    return Sale::post(
        ['customer_id' => $context['customer']->id, 'invoice_type' => 'full', 'date' => $date, 'payment_mode' => 'credit', 'store_id' => $context['store']->id],
        $lines,
        $context['admin'],
    );
}

/** Debits minus credits on one account, across every voucher. */
function cogsTestGlBalance(int $accountId): Money
{
    $lines = JournalVoucherLine::where('account_id', $accountId)->get();

    return Money::sum($lines->pluck('debit'))->minus(Money::sum($lines->pluck('credit')));
}

/** Signed debit-minus-credit total of one account on one voucher. */
function cogsTestVoucherNet(JournalVoucher $voucher, int $accountId): Money
{
    $lines = $voucher->lines()->where('account_id', $accountId)->get();

    return Money::sum($lines->pluck('debit'))->minus(Money::sum($lines->pluck('credit')));
}

function cogsTestAssertBalanced(JournalVoucher $voucher): void
{
    $lines = $voucher->lines()->get();

    expect(Money::sum($lines->pluck('debit'))->toString())->toBe(Money::sum($lines->pluck('credit'))->toString());
}

/**
 * P1: the item's live cost_value balance equals its class account balance
 * (one perpetual item per test, so its share is the whole account).
 */
function cogsTestAssertP1(Item $item): void
{
    $subledger = Money::sum(
        ItemStockMovement::where('item_id', $item->id)->where('cancelled', false)->get()
            ->map(fn (ItemStockMovement $movement): Money => $movement->movement_type->direction() === 1
                ? Money::of($movement->cost_value)
                : Money::of($movement->cost_value)->negated())
    );

    expect(cogsTestGlBalance($item->inventoryAccountId())->toString())->toBe($subledger->toString());
}

test('selling 5 of 100 finished goods produced for 1560.00 posts Dr EXE10 78.00 / Cr AS14 78.00 in the sale voucher', function () {
    provisionCogsTestTenant('cogs-sale.tenant-test')->run(function () {
        $context = cogsTestProducedFinishedGood();
        $item = $context['item'];
        cogsTestAssertP1($item);

        $sale = cogsTestSell($context, [['item_id' => $item->id, 'quantity' => 5, 'rate' => 25]]);
        $voucher = $sale->journalVoucher;

        $cogsId = ManufacturingAccounts::id(ManufacturingAccounts::COGS);
        $finishedGoodsId = ManufacturingAccounts::id(ManufacturingAccounts::FINISHED_GOODS);

        expect(cogsTestVoucherNet($voucher, $cogsId)->toString())->toBe('78.00')
            ->and(cogsTestVoucherNet($voucher, $finishedGoodsId)->toString())->toBe('-78.00');
        cogsTestAssertBalanced($voucher);

        $movement = ItemStockMovement::where('item_id', $item->id)->where('movement_type', StockMovementType::Sale->value)->sole();
        expect(Money::of($movement->cost_value)->toString())->toBe('78.00');

        expect(cogsTestGlBalance($finishedGoodsId)->toString())->toBe('1482.00');
        cogsTestAssertP1($item);
    });
});

test('a mixed trading and finished-good invoice balances and its trading part posts no COGS', function () {
    provisionCogsTestTenant('cogs-mixed.tenant-test')->run(function () {
        $context = cogsTestProducedFinishedGood();
        $item = $context['item'];
        $tradingItem = Item::factory()->create(['is_vatable' => false, 'is_stockable' => true]);
        $tradingItem->recordStockMovement(StockMovementType::Opening, '50', '2026-06-01', $context['store']->id, value: '500.00');

        $sale = cogsTestSell($context, [
            ['item_id' => $tradingItem->id, 'quantity' => 10, 'rate' => 30],
            ['item_id' => $item->id, 'quantity' => 5, 'rate' => 25],
        ]);
        $voucher = $sale->journalVoucher;
        $cogsId = ManufacturingAccounts::id(ManufacturingAccounts::COGS);

        cogsTestAssertBalanced($voucher);
        expect(cogsTestVoucherNet($voucher, $cogsId)->toString())->toBe('78.00')
            ->and($voucher->lines()->where('account_id', $cogsId)->count())->toBe(1);

        $tradingMovement = ItemStockMovement::where('item_id', $tradingItem->id)->where('movement_type', StockMovementType::Sale->value)->sole();
        expect($tradingMovement->cost_value)->toBeNull();

        // A trading-only bill gets no inventory line whatsoever.
        $tradingOnly = cogsTestSell($context, [['item_id' => $tradingItem->id, 'quantity' => 2, 'rate' => 30]]);
        $tradingAccounts = $tradingOnly->journalVoucher->lines()->pluck('account_id')->all();

        expect($tradingAccounts)->not->toContain($cogsId)
            ->and($tradingAccounts)->not->toContain(ManufacturingAccounts::id(ManufacturingAccounts::FINISHED_GOODS));

        cogsTestAssertP1($item);
    });
});

test('two lines of the same finished good are costed together and sum to the item cost', function () {
    provisionCogsTestTenant('cogs-two-lines.tenant-test')->run(function () {
        $context = cogsTestProducedFinishedGood();
        $item = $context['item'];

        $sale = cogsTestSell($context, [
            ['item_id' => $item->id, 'quantity' => 60, 'rate' => 25],
            ['item_id' => $item->id, 'quantity' => 40, 'rate' => 20],
        ]);

        // The two lines together empty the shelf: the whole 1560.00 leaves,
        // with no paisa stranded against zero quantity.
        expect(cogsTestVoucherNet($sale->journalVoucher, ManufacturingAccounts::id(ManufacturingAccounts::COGS))->toString())->toBe('1560.00');
        expect(cogsTestGlBalance(ManufacturingAccounts::id(ManufacturingAccounts::FINISHED_GOODS))->toString())->toBe('0.00');
        cogsTestAssertP1($item);
    });
});

test('cancelling the sale nets EXE10 back to zero and restores AS14', function () {
    provisionCogsTestTenant('cogs-cancel.tenant-test')->run(function () {
        $context = cogsTestProducedFinishedGood();
        $item = $context['item'];

        $sale = cogsTestSell($context, [['item_id' => $item->id, 'quantity' => 5, 'rate' => 25]]);
        cogsTestAssertP1($item);

        $sale->cancel($context['admin'], 'Wrong customer');

        $reversal = JournalVoucher::findOrFail($sale->fresh()->reversal_journal_voucher_id);
        cogsTestAssertBalanced($reversal);

        expect(cogsTestGlBalance(ManufacturingAccounts::id(ManufacturingAccounts::COGS))->toString())->toBe('0.00')
            ->and(cogsTestGlBalance(ManufacturingAccounts::id(ManufacturingAccounts::FINISHED_GOODS))->toString())->toBe('1560.00');
        cogsTestAssertP1($item);
    });
});

test('a linked return of 2 credits EXE10 31.20 and debits AS14, and the last return takes the remainder', function () {
    provisionCogsTestTenant('cogs-return.tenant-test')->run(function () {
        $context = cogsTestProducedFinishedGood();
        $item = $context['item'];

        $sale = cogsTestSell($context, [['item_id' => $item->id, 'quantity' => 5, 'rate' => 25]]);
        $saleLine = $sale->lines()->sole();

        $return = SalesReturn::post(
            ['sale_id' => $sale->id, 'date' => '2026-06-12', 'reason' => 'Damaged'],
            [['sale_line_id' => $saleLine->id, 'quantity' => 2]],
            $context['admin'],
        );

        $cogsId = ManufacturingAccounts::id(ManufacturingAccounts::COGS);
        $finishedGoodsId = ManufacturingAccounts::id(ManufacturingAccounts::FINISHED_GOODS);

        expect(cogsTestVoucherNet($return->journalVoucher, $cogsId)->toString())->toBe('-31.20')
            ->and(cogsTestVoucherNet($return->journalVoucher, $finishedGoodsId)->toString())->toBe('31.20');
        cogsTestAssertBalanced($return->journalVoucher);

        $movement = ItemStockMovement::where('item_id', $item->id)->where('movement_type', StockMovementType::SaleReturn->value)->sole();
        expect(Money::of($movement->cost_value)->toString())->toBe('31.20');
        cogsTestAssertP1($item);

        $last = SalesReturn::post(
            ['sale_id' => $sale->id, 'date' => '2026-06-13', 'reason' => 'Rest'],
            [['sale_line_id' => $saleLine->id, 'quantity' => 3]],
            $context['admin'],
        );

        expect(cogsTestVoucherNet($last->journalVoucher, $cogsId)->toString())->toBe('-46.80')
            ->and(cogsTestGlBalance($cogsId)->toString())->toBe('0.00')
            ->and(cogsTestGlBalance($finishedGoodsId)->toString())->toBe('1560.00');
        cogsTestAssertP1($item);

        $last->cancel($context['admin'], 'Entered twice');

        expect(cogsTestGlBalance($cogsId)->toString())->toBe('46.80');
        cogsTestAssertP1($item);
    });
});

test('a pending return request is costed when it is approved', function () {
    provisionCogsTestTenant('cogs-pending-return.tenant-test')->run(function () {
        $context = cogsTestProducedFinishedGood();
        $item = $context['item'];

        $sale = cogsTestSell($context, [['item_id' => $item->id, 'quantity' => 5, 'rate' => 25]]);
        $saleLine = $sale->lines()->sole();

        $request = SalesReturn::request(
            ['sale_id' => $sale->id, 'date' => '2026-06-12', 'reason' => 'Damaged'],
            [['sale_line_id' => $saleLine->id, 'quantity' => 2]],
            $context['admin'],
        );

        // Nothing posts while pending: COGS still shows the full sale.
        expect(cogsTestGlBalance(ManufacturingAccounts::id(ManufacturingAccounts::COGS))->toString())->toBe('78.00');
        cogsTestAssertP1($item);

        $approved = $request->approve($context['admin']);

        expect(cogsTestVoucherNet($approved->journalVoucher, ManufacturingAccounts::id(ManufacturingAccounts::COGS))->toString())->toBe('-31.20');
        cogsTestAssertBalanced($approved->journalVoucher);
        cogsTestAssertP1($item);
    });
});

test('an unlinked return of a finished good is costed at the moving average', function () {
    provisionCogsTestTenant('cogs-unlinked-return.tenant-test')->run(function () {
        $context = cogsTestProducedFinishedGood();
        $item = $context['item'];

        cogsTestSell($context, [['item_id' => $item->id, 'quantity' => 5, 'rate' => 25]]);

        $return = SalesReturn::postUnlinked(
            ['customer_id' => $context['customer']->id, 'date' => '2026-06-12', 'store_id' => $context['store']->id, 'reason' => 'Old bill'],
            [['item_id' => $item->id, 'quantity' => 3, 'rate' => 25]],
            $context['admin'],
        );

        // (1560.00 - 78.00) / 95 = 15.60 a unit, so 3 units come back at 46.80.
        expect(cogsTestVoucherNet($return->journalVoucher, ManufacturingAccounts::id(ManufacturingAccounts::COGS))->toString())->toBe('-46.80')
            ->and(cogsTestVoucherNet($return->journalVoucher, ManufacturingAccounts::id(ManufacturingAccounts::FINISHED_GOODS))->toString())->toBe('46.80');
        cogsTestAssertBalanced($return->journalVoucher);
        cogsTestAssertP1($item);
    });
});
