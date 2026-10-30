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
use App\Models\StockAdjustment;
use App\Models\StockConversion;
use App\Models\StockTransfer;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Manufacturing\ManufacturingAccounts;
use App\Support\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * MC8 (stock documents): an adjustment of a raw material or finished good is
 * always valued - in at the entered rate, out at the moving average - and
 * posts one InventoryCost voucher against EXE23; the opening-stock import
 * debits perpetual lines to their class account instead of AS11; a transfer
 * moves cost between stores with both legs at the same cost_value and no
 * voucher; a stock conversion refuses perpetual items and the retired
 * `production` type. P1 holds after every step.
 */

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionPerpetualStockDocTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

/**
 * The open year, an owner and the main store.
 *
 * @return array{admin: User, store: Store}
 */
function perpetualStockDocSetup(): array
{
    FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);

    return [
        'admin' => User::factory()->create(),
        'store' => Store::where('is_active', true)->orderBy('id')->firstOrFail(),
    ];
}

/**
 * Buys a raw material through Purchase::post() (Dr AS12 / Cr supplier), so the
 * subledger and the GL start in step exactly the way production data will.
 */
function perpetualStockDocBuy(array $context, Item $item, string $quantity, string $rate, string $date = '2026-06-01'): Purchase
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

function perpetualStockDocGlBalance(int $accountId): Money
{
    $lines = JournalVoucherLine::where('account_id', $accountId)->get();

    return Money::sum($lines->pluck('debit'))->minus(Money::sum($lines->pluck('credit')));
}

/** Signed debit-minus-credit total of one account on one voucher. */
function perpetualStockDocVoucherNet(JournalVoucher $voucher, int $accountId): Money
{
    $lines = $voucher->lines()->where('account_id', $accountId)->get();

    return Money::sum($lines->pluck('debit'))->minus(Money::sum($lines->pluck('credit')));
}

function perpetualStockDocSubledger(Item $item): Money
{
    return Money::sum(
        ItemStockMovement::where('item_id', $item->id)->where('cancelled', false)->get()
            ->map(fn (ItemStockMovement $movement): Money => $movement->movement_type->direction() === 1
                ? Money::of($movement->cost_value)
                : Money::of($movement->cost_value)->negated())
    );
}

/** P1 for a test with one perpetual item per class account. */
function perpetualStockDocAssertP1(Item $item): void
{
    expect(perpetualStockDocGlBalance($item->inventoryAccountId())->toString())
        ->toBe(perpetualStockDocSubledger($item)->toString());
}

test('a perpetual adjustment out posts Dr EXE23 / Cr AS12 at the moving average, and cancelling reverses it', function () {
    provisionPerpetualStockDocTenant('perpetual-adjustment-out.tenant-test')->run(function () {
        $context = perpetualStockDocSetup();
        $raw = Item::factory()->rawMaterial()->create(['is_vatable' => false]);

        // 10 x 50 + 5 x 80 = 900 for 15 units: moving average 60.
        perpetualStockDocBuy($context, $raw, '10', '50', '2026-06-01');
        perpetualStockDocBuy($context, $raw, '5', '80', '2026-06-02');

        // A damage write-off is still valued for a perpetual item: the cost
        // really left the inventory account.
        $adjustment = StockAdjustment::post(
            ['date' => '2026-06-10', 'store_id' => $context['store']->id],
            [['item_id' => $raw->id, 'direction' => 'out', 'reason_type' => 'damage', 'quantity' => '3']],
            $context['admin'],
        );

        $voucher = $adjustment->fresh()->journalVoucher;
        $rawMaterialId = ManufacturingAccounts::id(ManufacturingAccounts::RAW_MATERIAL);
        $adjustmentId = ManufacturingAccounts::id(ManufacturingAccounts::ADJUSTMENT);

        expect($voucher)->not->toBeNull()
            ->and($voucher->voucher_type)->toBe(VoucherType::InventoryCost)
            ->and(perpetualStockDocVoucherNet($voucher, $adjustmentId)->toString())->toBe('180.00')
            ->and(perpetualStockDocVoucherNet($voucher, $rawMaterialId)->toString())->toBe('-180.00')
            ->and(Money::of($adjustment->lines()->sole()->line_value)->toString())->toBe('180.00')
            ->and(Money::of($adjustment->fresh()->total_value)->toString())->toBe('180.00');

        $movement = ItemStockMovement::where('item_id', $raw->id)
            ->where('movement_type', StockMovementType::AdjustmentOut)
            ->sole();

        expect(Money::of($movement->cost_value)->toString())->toBe('180.00')
            ->and(perpetualStockDocGlBalance($rawMaterialId)->toString())->toBe('720.00');

        perpetualStockDocAssertP1($raw);

        $adjustment->fresh()->cancel($context['admin'], 'Counted again');

        expect(perpetualStockDocGlBalance($rawMaterialId)->toString())->toBe('900.00')
            ->and(perpetualStockDocGlBalance($adjustmentId)->toString())->toBe('0.00');

        perpetualStockDocAssertP1($raw);
    });
});

test('a perpetual adjustment in posts Dr AS12 / Cr EXE23 at the entered rate, and is refused without one', function () {
    provisionPerpetualStockDocTenant('perpetual-adjustment-in.tenant-test')->run(function () {
        $context = perpetualStockDocSetup();
        $raw = Item::factory()->rawMaterial()->create(['is_vatable' => false]);

        expect(fn () => StockAdjustment::post(
            ['date' => '2026-06-10', 'store_id' => $context['store']->id],
            [['item_id' => $raw->id, 'direction' => 'in', 'reason_type' => 'found', 'quantity' => '5']],
            $context['admin'],
        ))->toThrow(InvalidArgumentException::class, 'unit cost rate greater than zero');

        expect(fn () => StockAdjustment::post(
            ['date' => '2026-06-10', 'store_id' => $context['store']->id],
            [['item_id' => $raw->id, 'direction' => 'in', 'reason_type' => 'found', 'quantity' => '5', 'unit_cost_rate' => '0']],
            $context['admin'],
        ))->toThrow(InvalidArgumentException::class, 'unit cost rate greater than zero');

        expect(StockAdjustment::count())->toBe(0);

        $adjustment = StockAdjustment::post(
            ['date' => '2026-06-10', 'store_id' => $context['store']->id],
            [['item_id' => $raw->id, 'direction' => 'in', 'reason_type' => 'found', 'quantity' => '5', 'unit_cost_rate' => '12']],
            $context['admin'],
        );

        $voucher = $adjustment->fresh()->journalVoucher;

        expect($voucher->voucher_type)->toBe(VoucherType::InventoryCost)
            ->and(perpetualStockDocVoucherNet($voucher, ManufacturingAccounts::id(ManufacturingAccounts::RAW_MATERIAL))->toString())->toBe('60.00')
            ->and(perpetualStockDocVoucherNet($voucher, ManufacturingAccounts::id(ManufacturingAccounts::ADJUSTMENT))->toString())->toBe('-60.00');

        perpetualStockDocAssertP1($raw);
    });
});

test('a trading-only adjustment still posts no voucher', function () {
    provisionPerpetualStockDocTenant('perpetual-adjustment-trading.tenant-test')->run(function () {
        $context = perpetualStockDocSetup();
        $trading = Item::factory()->create(['is_stockable' => true]);

        $adjustment = StockAdjustment::post(
            ['date' => '2026-06-10', 'store_id' => $context['store']->id],
            [['item_id' => $trading->id, 'direction' => 'in', 'reason_type' => 'found', 'quantity' => '5', 'unit_cost_rate' => '12']],
            $context['admin'],
        );

        expect($adjustment->fresh()->journal_voucher_id)->toBeNull()
            ->and(JournalVoucher::where('voucher_type', VoucherType::InventoryCost)->count())->toBe(0)
            ->and(ItemStockMovement::where('item_id', $trading->id)->sole()->cost_value)->toBeNull();
    });
});

test('importing opening stock of a finished good posts Dr AS14, the trading remainder staying on AS11', function () {
    provisionPerpetualStockDocTenant('perpetual-opening-import.tenant-test')->run(function () {
        $context = perpetualStockDocSetup();
        $trading = Item::factory()->create(['is_stockable' => true]);
        $finished = Item::factory()->finishedGood()->create();

        ['adjustment' => $adjustment] = StockAdjustment::postOpeningImport(
            ['date' => '2026-06-01', 'store_id' => $context['store']->id],
            [
                ['item_id' => $trading->id, 'direction' => 'in', 'reason_type' => 'opening', 'quantity' => '10', 'unit_cost_rate' => '5'],
                ['item_id' => $finished->id, 'direction' => 'in', 'reason_type' => 'opening', 'quantity' => '4', 'unit_cost_rate' => '25'],
            ],
            $context['admin'],
        );

        $voucher = $adjustment->fresh()->journalVoucher;
        $equityId = Account::where('name', 'Profit & Loss')->firstOrFail()->id;

        expect(JournalVoucher::count())->toBe(1)
            ->and(perpetualStockDocVoucherNet($voucher, ManufacturingAccounts::id(ManufacturingAccounts::FINISHED_GOODS))->toString())->toBe('100.00')
            ->and(perpetualStockDocVoucherNet($voucher, Account::where('code', 'AS11')->firstOrFail()->id)->toString())->toBe('50.00')
            ->and(perpetualStockDocVoucherNet($voucher, $equityId)->toString())->toBe('-150.00');

        $movement = ItemStockMovement::where('item_id', $finished->id)->sole();

        expect($movement->movement_type)->toBe(StockMovementType::Opening)
            ->and(Money::of($movement->cost_value)->toString())->toBe('100.00');

        perpetualStockDocAssertP1($finished);
    });
});

test('a transfer of a raw material gives both legs the same cost_value and leaves AS12 and the subledger unchanged', function () {
    provisionPerpetualStockDocTenant('perpetual-transfer.tenant-test')->run(function () {
        $context = perpetualStockDocSetup();
        $branch = Store::create(['name' => 'Branch', 'is_active' => true]);
        $raw = Item::factory()->rawMaterial()->create(['is_vatable' => false]);
        $rawMaterialId = ManufacturingAccounts::id(ManufacturingAccounts::RAW_MATERIAL);

        perpetualStockDocBuy($context, $raw, '10', '50');
        $vouchersBefore = JournalVoucher::count();

        StockTransfer::post(
            ['date' => '2026-06-05', 'from_store_id' => $context['store']->id, 'to_store_id' => $branch->id],
            [['item_id' => $raw->id, 'quantity' => '4']],
            $context['admin'],
        );

        $out = ItemStockMovement::where('item_id', $raw->id)->where('movement_type', StockMovementType::TransferOut)->sole();
        $in = ItemStockMovement::where('item_id', $raw->id)->where('movement_type', StockMovementType::TransferIn)->sole();

        expect(Money::of($out->cost_value)->toString())->toBe('200.00')
            ->and(Money::of($in->cost_value)->toString())->toBe('200.00')
            ->and(JournalVoucher::count())->toBe($vouchersBefore)
            ->and(perpetualStockDocGlBalance($rawMaterialId)->toString())->toBe('500.00')
            ->and(perpetualStockDocSubledger($raw)->toString())->toBe('500.00');

        perpetualStockDocAssertP1($raw);
    });
});

test('a stock conversion rejects a raw material', function () {
    provisionPerpetualStockDocTenant('perpetual-conversion-raw.tenant-test')->run(function () {
        $context = perpetualStockDocSetup();
        $raw = Item::factory()->rawMaterial()->create(['is_vatable' => false]);
        $trading = Item::factory()->create(['is_stockable' => true]);

        perpetualStockDocBuy($context, $raw, '10', '50');

        expect(fn () => StockConversion::post(
            ['type' => 'refining', 'date' => '2026-06-05', 'store_id' => $context['store']->id],
            [['item_id' => $raw->id, 'quantity' => '4']],
            [['item_id' => $trading->id, 'quantity' => '4']],
            $context['admin'],
        ))->toThrow(InvalidArgumentException::class, 'cannot be used in a stock conversion');

        expect(StockConversion::count())->toBe(0)
            ->and($raw->fresh()->currentStock($context['store']->id)->toString())->toBe('10.0000');

        perpetualStockDocAssertP1($raw);
    });
});

test('a stock conversion rejects the retired production type for new entries', function () {
    provisionPerpetualStockDocTenant('perpetual-conversion-production.tenant-test')->run(function () {
        $context = perpetualStockDocSetup();
        $input = Item::factory()->create(['is_stockable' => true]);
        $output = Item::factory()->create(['is_stockable' => true]);

        $input->recordStockMovement(StockMovementType::Opening, 10, '2026-06-01', $context['store']->id);

        expect(fn () => StockConversion::post(
            ['type' => 'production', 'date' => '2026-06-05', 'store_id' => $context['store']->id],
            [['item_id' => $input->id, 'quantity' => '4']],
            [['item_id' => $output->id, 'quantity' => '4']],
            $context['admin'],
        ))->toThrow(InvalidArgumentException::class, 'Production is no longer posted as a stock conversion');

        expect(StockConversion::count())->toBe(0);
    });
});

test('the stock conversion form no longer accepts the production type', function () {
    $tenant = provisionPerpetualStockDocTenant('perpetual-conversion-http.tenant-test');
    $domain = 'perpetual-conversion-http.tenant-test';

    $tenant->run(function () {
        perpetualStockDocSetup();
        User::factory()->create(['email' => 'owner@example.com']);
    });

    $this->post("http://{$domain}/login", ['email' => 'owner@example.com', 'password' => 'password']);

    $this->post("http://{$domain}/stock-conversions", [
        'type' => 'production',
        'date' => '2026-06-05',
        'input_lines' => [['item_id' => 1, 'quantity' => '1']],
        'output_lines' => [['item_id' => 1, 'quantity' => '1']],
    ])
        ->assertSessionHasErrors('type');
});
