<?php

use App\Enums\FiscalYearStatus;
use App\Enums\StockMovementType;
use App\Enums\VoucherType;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\ItemStockMovement;
use App\Models\JournalVoucher;
use App\Models\JournalVoucherLine;
use App\Models\Purchase;
use App\Models\PurchaseLine;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnLine;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Manufacturing\ManufacturingAccounts;
use App\Support\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * MC8 (purchase side): a raw material or finished good line on a purchase
 * debits its class account (AS12 / AS14) instead of item.account_id / EXE8,
 * inside the bill's own voucher, and its stock movement carries the same net
 * value as cost_value. A purchase return credits the class account at the
 * original line's cost share; cancelling either document mirrors it. P1 -
 * the item's cost_value balance equals its class account's GL balance -
 * holds after every step. Trading lines are untouched.
 */

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionPerpetualPurchaseTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

/**
 * @return array{admin: User, supplier: Supplier, store: Store}
 */
function perpetualPurchaseSetup(): array
{
    FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);

    return [
        'admin' => User::factory()->create(),
        'supplier' => Supplier::factory()->create(),
        'store' => Store::where('is_active', true)->orderBy('id')->firstOrFail(),
    ];
}

/**
 * A non-VAT, credit purchase, so the voucher is only the line debits against
 * the supplier and the figures stay readable.
 *
 * @param  array{admin: User, supplier: Supplier, store: Store}  $context
 * @param  array<int, array<string, mixed>>  $lines
 */
function perpetualPurchasePost(array $context, array $lines, string $date = '2026-06-01'): Purchase
{
    return Purchase::post(
        [
            'supplier_id' => $context['supplier']->id,
            'date' => $date,
            'payment_mode' => 'credit',
            'store_id' => $context['store']->id,
            'force_non_taxable' => true,
        ],
        $lines,
        $context['admin'],
    );
}

function perpetualPurchaseGlBalance(int $accountId): Money
{
    $lines = JournalVoucherLine::where('account_id', $accountId)->get();

    return Money::sum($lines->pluck('debit'))->minus(Money::sum($lines->pluck('credit')));
}

/** Signed debit-minus-credit total of one account on one voucher. */
function perpetualPurchaseVoucherNet(JournalVoucher $voucher, int $accountId): Money
{
    $lines = $voucher->lines()->where('account_id', $accountId)->get();

    return Money::sum($lines->pluck('debit'))->minus(Money::sum($lines->pluck('credit')));
}

function perpetualPurchaseSubledger(Item $item): Money
{
    return Money::sum(
        ItemStockMovement::where('item_id', $item->id)->where('cancelled', false)->get()
            ->map(fn (ItemStockMovement $movement): Money => $movement->movement_type->direction() === 1
                ? Money::of($movement->cost_value)
                : Money::of($movement->cost_value)->negated())
    );
}

/** P1 for a test with one perpetual item: its subledger is the whole class account. */
function perpetualPurchaseAssertP1(Item $item): void
{
    expect(perpetualPurchaseGlBalance($item->inventoryAccountId())->toString())
        ->toBe(perpetualPurchaseSubledger($item)->toString());
}

function perpetualPurchaseExe8(): int
{
    return Account::where('code', 'EXE8')->firstOrFail()->id;
}

test('a raw material purchase debits AS12, not EXE8, and its movement carries the net value as cost_value', function () {
    provisionPerpetualPurchaseTenant('perpetual-purchase-raw.tenant-test')->run(function () {
        $context = perpetualPurchaseSetup();
        $raw = Item::factory()->rawMaterial()->create(['is_vatable' => false]);

        $purchase = perpetualPurchasePost($context, [['item_id' => $raw->id, 'quantity' => 10, 'rate' => 50]]);
        $voucher = $purchase->journalVoucher;
        $rawMaterialId = ManufacturingAccounts::id(ManufacturingAccounts::RAW_MATERIAL);

        expect(perpetualPurchaseVoucherNet($voucher, $rawMaterialId)->toString())->toBe('500.00')
            ->and($voucher->lines()->where('account_id', perpetualPurchaseExe8())->exists())->toBeFalse()
            ->and($purchase->lines()->firstOrFail()->account_id)->toBe($rawMaterialId);

        $movement = ItemStockMovement::where('item_id', $raw->id)->sole();

        expect($movement->movement_type)->toBe(StockMovementType::Purchase)
            ->and(Money::of($movement->cost_value)->toString())->toBe('500.00')
            ->and(Money::of($movement->value)->toString())->toBe('500.00');

        perpetualPurchaseAssertP1($raw);
    });
});

test('a finished good purchase debits AS14', function () {
    provisionPerpetualPurchaseTenant('perpetual-purchase-fg.tenant-test')->run(function () {
        $context = perpetualPurchaseSetup();
        $finished = Item::factory()->finishedGood()->create(['is_vatable' => false]);

        $purchase = perpetualPurchasePost($context, [['item_id' => $finished->id, 'quantity' => 4, 'rate' => 25]]);

        expect(perpetualPurchaseVoucherNet($purchase->journalVoucher, ManufacturingAccounts::id(ManufacturingAccounts::FINISHED_GOODS))->toString())
            ->toBe('100.00');

        perpetualPurchaseAssertP1($finished);
    });
});

test('a bill mixing a trading and a raw line posts one balanced voucher and leaves the trading line exactly as before', function () {
    provisionPerpetualPurchaseTenant('perpetual-purchase-mixed.tenant-test')->run(function () {
        $context = perpetualPurchaseSetup();
        $trading = Item::factory()->create(['is_stockable' => true, 'is_vatable' => false]);
        $raw = Item::factory()->rawMaterial()->create(['is_vatable' => false]);

        $purchase = perpetualPurchasePost($context, [
            ['item_id' => $trading->id, 'quantity' => 2, 'rate' => 40],
            ['item_id' => $raw->id, 'quantity' => 3, 'rate' => 20],
        ]);

        expect(JournalVoucher::where('voucher_type', VoucherType::Purchase)->count())->toBe(1);

        $voucher = $purchase->journalVoucher;
        $lines = $voucher->lines()->get();

        expect(Money::sum($lines->pluck('debit'))->toString())->toBe(Money::sum($lines->pluck('credit'))->toString())
            ->and(perpetualPurchaseVoucherNet($voucher, perpetualPurchaseExe8())->toString())->toBe('80.00')
            ->and(perpetualPurchaseVoucherNet($voucher, ManufacturingAccounts::id(ManufacturingAccounts::RAW_MATERIAL))->toString())->toBe('60.00')
            ->and(perpetualPurchaseVoucherNet($voucher, $context['supplier']->fresh()->account_id)->toString())->toBe('-140.00');

        $tradingMovement = ItemStockMovement::where('item_id', $trading->id)->sole();

        expect($tradingMovement->cost_value)->toBeNull()
            ->and(Money::of($tradingMovement->value)->toString())->toBe('80.00');

        perpetualPurchaseAssertP1($raw);
    });
});

test('cancelling a raw purchase reverses AS12 and cancels the movement, restoring P1', function () {
    provisionPerpetualPurchaseTenant('perpetual-purchase-cancel.tenant-test')->run(function () {
        $context = perpetualPurchaseSetup();
        $raw = Item::factory()->rawMaterial()->create(['is_vatable' => false]);

        $purchase = perpetualPurchasePost($context, [['item_id' => $raw->id, 'quantity' => 10, 'rate' => 50]]);
        perpetualPurchaseAssertP1($raw);

        $purchase->cancel($context['admin'], 'Wrong supplier');

        expect(perpetualPurchaseGlBalance(ManufacturingAccounts::id(ManufacturingAccounts::RAW_MATERIAL))->toString())->toBe('0.00')
            ->and(perpetualPurchaseSubledger($raw)->toString())->toBe('0.00');

        perpetualPurchaseAssertP1($raw);
    });
});

test('a purchase return credits AS12 at the original line cost share, the final return taking the exact remainder', function () {
    provisionPerpetualPurchaseTenant('perpetual-purchase-return.tenant-test')->run(function () {
        $context = perpetualPurchaseSetup();
        $raw = Item::factory()->rawMaterial()->create(['is_vatable' => false]);
        $rawMaterialId = ManufacturingAccounts::id(ManufacturingAccounts::RAW_MATERIAL);

        // 3 x 33.33 = 99.99: one third is 33.33, the last two-thirds 66.66.
        $purchase = perpetualPurchasePost($context, [['item_id' => $raw->id, 'quantity' => 3, 'rate' => '33.33']]);
        $lineId = $purchase->lines()->firstOrFail()->id;

        $first = PurchaseReturn::post(
            ['purchase_id' => $purchase->id, 'date' => '2026-06-05'],
            [['purchase_line_id' => $lineId, 'quantity' => 1]],
            $context['admin'],
        );

        expect(perpetualPurchaseVoucherNet($first->journalVoucher, $rawMaterialId)->toString())->toBe('-33.33')
            ->and($first->journalVoucher->lines()->where('account_id', perpetualPurchaseExe8())->exists())->toBeFalse();

        $firstMovement = ItemStockMovement::where('reference_type', (new PurchaseReturnLine)->getMorphClass())
            ->where('reference_id', $first->lines()->firstOrFail()->id)
            ->sole();

        expect($firstMovement->movement_type)->toBe(StockMovementType::PurchaseReturn)
            ->and(Money::of($firstMovement->cost_value)->toString())->toBe('33.33');

        perpetualPurchaseAssertP1($raw);

        $second = PurchaseReturn::post(
            ['purchase_id' => $purchase->id, 'date' => '2026-06-06'],
            [['purchase_line_id' => $lineId, 'quantity' => 2]],
            $context['admin'],
        );

        expect(perpetualPurchaseVoucherNet($second->journalVoucher, $rawMaterialId)->toString())->toBe('-66.66')
            ->and(perpetualPurchaseGlBalance($rawMaterialId)->toString())->toBe('0.00');

        perpetualPurchaseAssertP1($raw);
    });
});

test('cancelling a raw purchase return puts the cost back into AS12 and restores P1', function () {
    provisionPerpetualPurchaseTenant('perpetual-purchase-return-cancel.tenant-test')->run(function () {
        $context = perpetualPurchaseSetup();
        $raw = Item::factory()->rawMaterial()->create(['is_vatable' => false]);

        $purchase = perpetualPurchasePost($context, [['item_id' => $raw->id, 'quantity' => 10, 'rate' => 50]]);

        $return = PurchaseReturn::post(
            ['purchase_id' => $purchase->id, 'date' => '2026-06-05'],
            [['purchase_line_id' => $purchase->lines()->firstOrFail()->id, 'quantity' => 4]],
            $context['admin'],
        );

        expect(perpetualPurchaseGlBalance(ManufacturingAccounts::id(ManufacturingAccounts::RAW_MATERIAL))->toString())->toBe('300.00');
        perpetualPurchaseAssertP1($raw);

        $return->cancel($context['admin'], 'Supplier refused the goods');

        expect(perpetualPurchaseGlBalance(ManufacturingAccounts::id(ManufacturingAccounts::RAW_MATERIAL))->toString())->toBe('500.00');
        perpetualPurchaseAssertP1($raw);
    });
});

test('an unlinked return of a raw material with no rate is valued at the moving average and credits AS12', function () {
    provisionPerpetualPurchaseTenant('perpetual-purchase-unlinked.tenant-test')->run(function () {
        $context = perpetualPurchaseSetup();
        $raw = Item::factory()->rawMaterial()->create(['is_vatable' => false]);
        $rawMaterialId = ManufacturingAccounts::id(ManufacturingAccounts::RAW_MATERIAL);

        perpetualPurchasePost($context, [['item_id' => $raw->id, 'quantity' => 10, 'rate' => 50]]);

        $return = PurchaseReturn::postUnlinked(
            ['date' => '2026-06-05', 'payment_mode' => 'cash', 'vat_rate' => '0', 'store_id' => $context['store']->id],
            [['item_id' => $raw->id, 'quantity' => 4]],
            $context['admin'],
        );

        expect(Money::of($return->total)->toString())->toBe('200.00')
            ->and(perpetualPurchaseVoucherNet($return->journalVoucher, $rawMaterialId)->toString())->toBe('-200.00')
            ->and($return->journalVoucher->lines()->where('account_id', perpetualPurchaseExe8())->exists())->toBeFalse();

        perpetualPurchaseAssertP1($raw);
    });
});

test('a trading purchase still debits EXE8 and writes no cost_value', function () {
    provisionPerpetualPurchaseTenant('perpetual-purchase-trading.tenant-test')->run(function () {
        $context = perpetualPurchaseSetup();
        $trading = Item::factory()->create(['is_stockable' => true, 'is_vatable' => false]);

        $purchase = perpetualPurchasePost($context, [['item_id' => $trading->id, 'quantity' => 10, 'rate' => 50]]);

        expect(perpetualPurchaseVoucherNet($purchase->journalVoucher, perpetualPurchaseExe8())->toString())->toBe('500.00')
            ->and(PurchaseLine::where('purchase_id', $purchase->id)->value('account_id'))->toBe(perpetualPurchaseExe8())
            ->and(ItemStockMovement::where('item_id', $trading->id)->sole()->cost_value)->toBeNull()
            ->and(perpetualPurchaseGlBalance(ManufacturingAccounts::id(ManufacturingAccounts::RAW_MATERIAL))->toString())->toBe('0.00');
    });
});
