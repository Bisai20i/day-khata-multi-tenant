<?php

use App\Enums\FiscalYearStatus;
use App\Enums\StockMovementType;
use App\Enums\VoucherType;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\FiscalYearInventorySnapshot;
use App\Models\Item;
use App\Models\ItemStockMovement;
use App\Models\JournalVoucher;
use App\Models\JournalVoucherLine;
use App\Models\ProductionEntry;
use App\Models\ProductionWipEntry;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Manufacturing\InventoryReconciliation;
use App\Support\Manufacturing\ManufacturingAccounts;
use App\Support\Money\Money;
use App\Support\Money\Quantity;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

/**
 * FY1 ends 2026-12-31, after the suite's clock, so every close here is an
 * early close: owner plus a written reason (T11 task 7).
 */
const MFG_CLOSE_REASON = 'Closed early by the manufacturing close test.';

function provisionMfgCloseTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

/**
 * Net (debit - credit) for one account code inside one fiscal year, as a
 * Money string, summed exactly (scaled integers).
 */
function mfgCloseNet(string $code, FiscalYear $fiscalYear): string
{
    $accountId = Account::where('code', $code)->value('id');
    $cast = (new JournalVoucherLine)->getConnection()->getDriverName() === 'sqlite' ? 'INTEGER' : 'SIGNED';

    $net = JournalVoucherLine::query()
        ->where('account_id', $accountId)
        ->whereHas('journalVoucher', fn ($query) => $query->where('fiscal_year_id', $fiscalYear->id))
        ->selectRaw("COALESCE(SUM(CAST(ROUND(debit * 100) AS {$cast})), 0) - COALESCE(SUM(CAST(ROUND(credit * 100) AS {$cast})), 0) as net_scaled")
        ->value('net_scaled');

    return Money::of(BigDecimal::of((int) $net)->dividedBy(100, 2, RoundingMode::Unnecessary))->toString();
}

/**
 * A two-line journal (Dr $debitCode / Cr $creditCode) posted into the open
 * year, or into $fiscalYear with a correction reason when given.
 */
function mfgClosePost(string $date, string $debitCode, string $creditCode, string $amount, User $actor, ?FiscalYear $fiscalYear = null): void
{
    $header = ['date' => $date, 'narration' => "Fixture {$debitCode}/{$creditCode}"];

    if ($fiscalYear !== null) {
        $header['fiscal_year_id'] = $fiscalYear->id;
        $header['reason'] = 'Correction posted by the manufacturing close test.';
    }

    JournalVoucher::post(
        $header,
        [
            ['account_id' => Account::where('code', $debitCode)->value('id'), 'debit' => $amount, 'credit' => '0.00'],
            ['account_id' => Account::where('code', $creditCode)->value('id'), 'debit' => '0.00', 'credit' => $amount],
        ],
        $actor,
    );
}

/**
 * One year with both trading and manufacturing activity, every perpetual
 * figure posted to the GL and its subledger from the same amount, the way
 * the real documents do it (MC3 P1, MC7 P2):
 *
 * - Trading item T: 100 bought at 10.00 (1,000.00), 40 sold. Periodic
 *   closing stock 60 x 10.00 = 600.00.
 * - Raw material R: 200 bought at 5.00 (Dr AS12 1,000.00). 120 issued to
 *   entry E1 (600.00) and 40 to entry E2 (200.00): Dr AS13 / Cr AS12.
 *   Closing RM: 40 units, 200.00.
 * - E1 (in progress): materials 600.00 + labour 50.00 = WIP 650.00.
 * - E2 (completed): 200.00 of WIP into 10 units of finished good F
 *   (Dr AS14 / Cr AS13). 4 of F sold at cost 80.00 (Dr EXE10 / Cr AS14).
 *   Closing FG: 6 units, 120.00.
 *
 * @return array{fy1: FiscalYear, fy2: FiscalYear, actor: User, store: Store, trading: Item, raw: Item, finished: Item, inProgress: ProductionEntry, completed: ProductionEntry, rawPurchase: ItemStockMovement}
 */
function seedMfgCloseYear(): array
{
    $fy1 = FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
    $fy2 = FiscalYear::create(['name' => 'FY2', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'status' => FiscalYearStatus::Closed]);
    $actor = User::factory()->create(['email' => 'mfg-closer@example.com']);
    $store = Store::factory()->create();

    $trading = Item::factory()->create(['is_stockable' => true, 'purchase_rate' => '10.00']);
    $raw = Item::factory()->rawMaterial()->create(['purchase_rate' => '5.00']);
    $finished = Item::factory()->finishedGood()->create(['purchase_rate' => '20.00']);

    // Trading: periodic, no journal for the stock itself.
    $trading->recordStockMovement(StockMovementType::Purchase, '100', '2026-02-01', $store->id, null, '10.0000', '1000.00');
    mfgClosePost('2026-02-01', 'EXE8', 'AS1', '1000.00', $actor);
    $trading->recordStockMovement(StockMovementType::Sale, '40', '2026-05-01', $store->id, null, null, '700.00');
    mfgClosePost('2026-05-01', 'AS1', 'INI20', '700.00', $actor);

    // Raw material purchase.
    $rawPurchase = $raw->recordStockMovement(StockMovementType::Purchase, '200', '2026-02-10', $store->id, null, '5.0000', '1000.00', costValue: '1000.00');
    mfgClosePost('2026-02-10', ManufacturingAccounts::RAW_MATERIAL, 'AS1', '1000.00', $actor);

    $inProgress = ProductionEntry::factory()->create([
        'fiscal_year_id' => $fy1->id,
        'date' => '2026-03-01',
        'store_id' => $store->id,
        'finished_item_id' => $finished->id,
        'planned_quantity' => '50.0000',
        'status' => ProductionEntry::STATUS_IN_PROGRESS,
        'created_by' => $actor->id,
    ]);

    $completed = ProductionEntry::factory()->create([
        'fiscal_year_id' => $fy1->id,
        'date' => '2026-04-01',
        'store_id' => $store->id,
        'finished_item_id' => $finished->id,
        'planned_quantity' => '10.0000',
        'status' => ProductionEntry::STATUS_COMPLETED,
        'created_by' => $actor->id,
    ]);

    // E1: 120 of R issued (600.00) plus 50.00 labour, still in progress.
    $raw->recordStockMovement(StockMovementType::MaterialIssue, '120', '2026-03-01', $store->id, null, null, null, costValue: '600.00');
    mfgClosePost('2026-03-01', ManufacturingAccounts::WIP, ManufacturingAccounts::RAW_MATERIAL, '600.00', $actor);
    ProductionWipEntry::factory()->create([
        'production_entry_id' => $inProgress->id, 'date' => '2026-03-01', 'kind' => ProductionWipEntry::KIND_MATERIAL_IN,
        'item_id' => $raw->id, 'quantity' => '120.0000', 'value' => '600.00', 'direction' => 1,
    ]);

    mfgClosePost('2026-03-05', ManufacturingAccounts::WIP, 'AS1', '50.00', $actor);
    ProductionWipEntry::factory()->create([
        'production_entry_id' => $inProgress->id, 'date' => '2026-03-05', 'kind' => ProductionWipEntry::KIND_DIRECT_COST,
        'value' => '50.00', 'direction' => 1,
    ]);

    // E2: 40 of R issued (200.00), completed into 10 F at 200.00.
    $raw->recordStockMovement(StockMovementType::MaterialIssue, '40', '2026-04-01', $store->id, null, null, null, costValue: '200.00');
    mfgClosePost('2026-04-01', ManufacturingAccounts::WIP, ManufacturingAccounts::RAW_MATERIAL, '200.00', $actor);
    ProductionWipEntry::factory()->create([
        'production_entry_id' => $completed->id, 'date' => '2026-04-01', 'kind' => ProductionWipEntry::KIND_MATERIAL_IN,
        'item_id' => $raw->id, 'quantity' => '40.0000', 'value' => '200.00', 'direction' => 1,
    ]);

    $finished->recordStockMovement(StockMovementType::ProductionReceipt, '10', '2026-04-10', $store->id, null, null, null, costValue: '200.00');
    mfgClosePost('2026-04-10', ManufacturingAccounts::FINISHED_GOODS, ManufacturingAccounts::WIP, '200.00', $actor);
    ProductionWipEntry::factory()->create([
        'production_entry_id' => $completed->id, 'date' => '2026-04-10', 'kind' => ProductionWipEntry::KIND_COMPLETION,
        'item_id' => $raw->id, 'quantity' => '40.0000', 'value' => '200.00', 'direction' => -1,
    ]);

    // 4 F sold: selling value 150.00, cost 80.00.
    $finished->recordStockMovement(StockMovementType::Sale, '4', '2026-06-01', $store->id, null, null, '150.00', costValue: '80.00');
    mfgClosePost('2026-06-01', 'AS1', 'INI20', '150.00', $actor);
    mfgClosePost('2026-06-01', ManufacturingAccounts::COGS, ManufacturingAccounts::FINISHED_GOODS, '80.00', $actor);

    return compact('fy1', 'fy2', 'actor', 'store', 'trading', 'raw', 'finished', 'inProgress', 'completed', 'rawPurchase');
}

/**
 * @return array<string, string> "class:item_id|production_entry_id" => "quantity@value"
 */
function mfgCloseSnapshotMap(FiscalYear $fiscalYear): array
{
    return FiscalYearInventorySnapshot::query()
        ->where('fiscal_year_id', $fiscalYear->id)
        ->get()
        ->mapWithKeys(fn (FiscalYearInventorySnapshot $row) => [
            $row->inventory_class.':'.($row->item_id ?? 'pe'.$row->production_entry_id) => Quantity::of($row->quantity)->toString().'@'.Money::of($row->value)->toString(),
        ])
        ->all();
}

test('a year with trading and manufacturing activity reconciles and closes', function () {
    $tenant = provisionMfgCloseTenant('mfg-close-mixed.tenant-test');

    $tenant->run(function () {
        $fixture = seedMfgCloseYear();

        $check = InventoryReconciliation::check($fixture['fy1'], '2026-12-31');

        expect(array_keys($check))->toBe(['raw_material', 'wip', 'finished_good'])
            ->and($check['raw_material']['gl']->toString())->toBe('200.00')
            ->and($check['raw_material']['subledger']->toString())->toBe('200.00')
            ->and($check['raw_material']['difference']->isZero())->toBeTrue()
            ->and($check['wip']['gl']->toString())->toBe('650.00')
            ->and($check['wip']['subledger']->toString())->toBe('650.00')
            ->and($check['finished_good']['gl']->toString())->toBe('120.00')
            ->and($check['finished_good']['subledger']->toString())->toBe('120.00');

        $fixture['fy1']->close($fixture['fy2'], $fixture['actor'], MFG_CLOSE_REASON);

        expect($fixture['fy1']->fresh()->status)->toBe(FiscalYearStatus::Closed)
            ->and($fixture['fy2']->fresh()->status)->toBe(FiscalYearStatus::Open);
    });

    $tenant->delete();
});

test('the trading closing entry values trading stock only, never the perpetual classes', function () {
    $tenant = provisionMfgCloseTenant('mfg-close-as11.tenant-test');

    $tenant->run(function () {
        $fixture = seedMfgCloseYear();

        $fixture['fy1']->close($fixture['fy2'], $fixture['actor'], MFG_CLOSE_REASON);

        // 600.00 of trading stock, not 600 + 200 RM + 120 FG.
        expect(mfgCloseNet(FiscalYear::STOCK_IN_HAND_CODE, $fixture['fy1']))->toBe('600.00');

        $closingStockVoucher = JournalVoucher::where('fiscal_year_id', $fixture['fy1']->id)
            ->where('voucher_type', VoucherType::ClosingEntry)
            ->whereHas('lines', fn ($query) => $query->where('account_id', Account::where('code', FiscalYear::CLOSING_STOCK_CODE)->value('id')))
            ->with('lines')
            ->sole();

        $closingLine = $closingStockVoucher->lines->firstWhere('account_id', Account::where('code', FiscalYear::CLOSING_STOCK_CODE)->value('id'));
        expect(Money::of($closingLine->credit)->toString())->toBe('600.00');

        // Nothing in the closing entries touches the perpetual accounts.
        $perpetualIds = Account::whereIn('code', [ManufacturingAccounts::RAW_MATERIAL, ManufacturingAccounts::WIP, ManufacturingAccounts::FINISHED_GOODS])->pluck('id');
        $touched = JournalVoucherLine::query()
            ->whereIn('account_id', $perpetualIds)
            ->whereHas('journalVoucher', fn ($query) => $query->where('fiscal_year_id', $fixture['fy1']->id)->where('voucher_type', VoucherType::ClosingEntry))
            ->exists();
        expect($touched)->toBeFalse();
    });

    $tenant->delete();
});

test('raw material, WIP and finished goods carry forward with the same balances', function () {
    $tenant = provisionMfgCloseTenant('mfg-close-carry.tenant-test');

    $tenant->run(function () {
        $fixture = seedMfgCloseYear();

        $fixture['fy1']->close($fixture['fy2'], $fixture['actor'], MFG_CLOSE_REASON);

        $opening = JournalVoucher::where('fiscal_year_id', $fixture['fy2']->id)
            ->where('voucher_type', VoucherType::OpeningBalance)
            ->with('lines')
            ->sole();
        $lines = $opening->lines->keyBy('account_id');

        foreach ([ManufacturingAccounts::RAW_MATERIAL => '200.00', ManufacturingAccounts::WIP => '650.00', ManufacturingAccounts::FINISHED_GOODS => '120.00'] as $code => $expected) {
            $accountId = Account::where('code', $code)->value('id');

            expect(mfgCloseNet($code, $fixture['fy1']))->toBe($expected)
                ->and(Money::of($lines[$accountId]->debit)->toString())->toBe($expected)
                ->and(Money::of($lines[$accountId]->credit)->toString())->toBe('0.00')
                ->and(mfgCloseNet($code, $fixture['fy2']))->toBe($expected);
        }

        // COGS was swept like any expense.
        expect(mfgCloseNet(ManufacturingAccounts::COGS, $fixture['fy1']))->toBe('0.00');

        // The new year reconciles on its first day exactly as the old one closed.
        $check = InventoryReconciliation::check($fixture['fy2'], '2027-01-01');
        expect($check['raw_material']['difference']->isZero())->toBeTrue()
            ->and($check['wip']['difference']->isZero())->toBeTrue()
            ->and($check['finished_good']['difference']->isZero())->toBeTrue();
    });

    $tenant->delete();
});

test('the snapshots equal the reconciled figures', function () {
    $tenant = provisionMfgCloseTenant('mfg-close-snapshots.tenant-test');

    $tenant->run(function () {
        $fixture = seedMfgCloseYear();
        $check = InventoryReconciliation::check($fixture['fy1'], '2026-12-31');

        $fixture['fy1']->close($fixture['fy2'], $fixture['actor'], MFG_CLOSE_REASON);

        $expected = [
            'raw_material:'.$fixture['raw']->id => '40.0000@200.00',
            'finished_good:'.$fixture['finished']->id => '6.0000@120.00',
            // Only the in-progress entry: the completed one has no WIP left.
            'wip:pe'.$fixture['inProgress']->id => '0.0000@650.00',
            'trading:'.$fixture['trading']->id => '60.0000@600.00',
        ];
        $actual = mfgCloseSnapshotMap($fixture['fy1']);
        ksort($expected);
        ksort($actual);

        expect($actual)->toBe($expected);

        foreach (['raw_material', 'wip', 'finished_good'] as $class) {
            $total = Money::sum(
                FiscalYearInventorySnapshot::where('fiscal_year_id', $fixture['fy1']->id)
                    ->where('inventory_class', $class)
                    ->pluck('value')
                    ->map(fn ($value) => Money::of($value))
            );

            expect($total->toString())->toBe($check[$class]['gl']->toString());
        }
    });

    $tenant->delete();
});

test('a corrupted cost_value blocks the close and names the class', function () {
    $tenant = provisionMfgCloseTenant('mfg-close-corrupt.tenant-test');

    $tenant->run(function () {
        $fixture = seedMfgCloseYear();

        // Someone hand-edits the purchase's cost: the subledger now says
        // 1,050.00 went in while AS12 only received 1,000.00.
        ItemStockMovement::whereKey($fixture['rawPurchase']->id)->update(['cost_value' => '1050.00']);

        expect(fn () => $fixture['fy1']->close($fixture['fy2'], $fixture['actor'], MFG_CLOSE_REASON))
            ->toThrow(InvalidArgumentException::class, 'Raw Material');

        try {
            $fixture['fy1']->close($fixture['fy2'], $fixture['actor'], MFG_CLOSE_REASON);
        } catch (InvalidArgumentException $exception) {
            expect($exception->getMessage())->toContain('-50.00')
                ->and($exception->getMessage())->not->toContain('Finished Good')
                ->and($exception->getMessage())->not->toContain('Work in Process');
        }

        // Nothing was posted or written.
        expect($fixture['fy1']->fresh()->status)->toBe(FiscalYearStatus::Open)
            ->and(JournalVoucher::where('voucher_type', VoucherType::ClosingEntry)->exists())->toBeFalse()
            ->and(JournalVoucher::where('fiscal_year_id', $fixture['fy2']->id)->exists())->toBeFalse()
            ->and(FiscalYearInventorySnapshot::count())->toBe(0);
    });

    $tenant->delete();
});

test('reopening keeps the snapshots and relocking rewrites them from the corrected figures', function () {
    $tenant = provisionMfgCloseTenant('mfg-close-reclose.tenant-test');

    $tenant->run(function () {
        $fixture = seedMfgCloseYear();
        $fixture['fy1']->close($fixture['fy2'], $fixture['actor'], MFG_CLOSE_REASON);
        $before = mfgCloseSnapshotMap($fixture['fy1']);

        $fy1 = $fixture['fy1']->fresh();
        $fy1->reopen($fixture['actor'], 'Missed raw material purchase.');

        // A correction: 20 more of R at 5.00, posted into FY1.
        $fixture['raw']->recordStockMovement(StockMovementType::Purchase, '20', '2026-12-20', $fixture['store']->id, null, '5.0000', '100.00', costValue: '100.00');
        mfgClosePost('2026-12-20', ManufacturingAccounts::RAW_MATERIAL, 'AS1', '100.00', $fixture['actor'], $fy1);

        expect(mfgCloseSnapshotMap($fy1))->toBe($before);

        $fy1->fresh()->relock($fixture['actor']);

        $after = mfgCloseSnapshotMap($fy1);
        expect($after['raw_material:'.$fixture['raw']->id])->toBe('60.0000@300.00')
            ->and($after['wip:pe'.$fixture['inProgress']->id])->toBe('0.0000@650.00')
            ->and(FiscalYearInventorySnapshot::where('fiscal_year_id', $fy1->id)->where('inventory_class', 'raw_material')->count())->toBe(1);
    });

    $tenant->delete();
});

test('in-progress WIP survives into the new year and can be completed there', function () {
    $tenant = provisionMfgCloseTenant('mfg-close-wip-forward.tenant-test');

    $tenant->run(function () {
        $fixture = seedMfgCloseYear();
        $fixture['fy1']->close($fixture['fy2'], $fixture['actor'], MFG_CLOSE_REASON);
        $fy2 = $fixture['fy2']->fresh();

        $entry = $fixture['inProgress']->fresh();
        expect($entry->status)->toBe(ProductionEntry::STATUS_IN_PROGRESS);

        $entry->complete('2027-02-15', '50', true, $fixture['actor']);

        $receipt = ItemStockMovement::query()
            ->where('item_id', $fixture['finished']->id)
            ->where('movement_type', StockMovementType::ProductionReceipt->value)
            ->whereDate('date', '2027-02-15')
            ->sole();

        // The whole 650.00 carried in from FY1 moves to finished goods in FY2.
        expect(Money::of($receipt->cost_value)->toString())->toBe('650.00')
            ->and($entry->fresh()->status)->toBe(ProductionEntry::STATUS_COMPLETED)
            ->and(mfgCloseNet(ManufacturingAccounts::WIP, $fy2))->toBe('0.00')
            ->and(mfgCloseNet(ManufacturingAccounts::FINISHED_GOODS, $fy2))->toBe('770.00');

        $check = InventoryReconciliation::check($fy2, '2027-02-15');
        expect($check['wip']['difference']->isZero())->toBeTrue()
            ->and($check['finished_good']['difference']->isZero())->toBeTrue();
    });

    $tenant->delete();
});
