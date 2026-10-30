<?php

use App\Enums\FiscalYearStatus;
use App\Enums\InventoryClass;
use App\Enums\StockMovementType;
use App\Enums\VoucherType;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\ItemStockMovement;
use App\Models\JournalVoucher;
use App\Models\JournalVoucherLine;
use App\Models\MaterialIssueNote;
use App\Models\ProductionEntry;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Manufacturing\ManufacturingAccounts;
use App\Support\Manufacturing\WipLedger;
use App\Support\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

/**
 * The MC6 costing scenario, one step at a time:
 *
 *  1. issue RM-A 10 @ 50 and RM-B 4 @ 125: WIP 1,000.00;
 *  2. add labour 300 and power 200: WIP 1,500.00;
 *  3. plan 100 units, complete 40 (non-final): FG 600.00, WIP 900.00;
 *  4. add 60 more cost: WIP 960.00;
 *  5. final completion of 58 units: FG 960.00 more, WIP 0.00,
 *     cost per unit = 1,560.00 / 98.
 *
 * After every step the AS13 GL balance equals WipLedger::balance() (P2) and
 * the AS14 GL balance equals the finished-goods subledger (P1).
 */
uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionProductionScenarioTenant(): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => 'prod-scenario-'.Str::lower(Str::random(8)).'.tenant-test']);

    $tenant->run(function () {
        FiscalYear::create(['name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
    });

    return $tenant;
}

function productionScenarioStoreId(): int
{
    return (int) Store::where('is_active', true)->orderBy('id')->value('id');
}

/**
 * GL balance (debit - credit) of a system account over every posted line,
 * reversals included, exactly as the ledger reports read it.
 */
function productionScenarioGl(string $code): string
{
    $lines = JournalVoucherLine::where('account_id', ManufacturingAccounts::id($code))->get();

    return Money::sum($lines->pluck('debit'))->minus(Money::sum($lines->pluck('credit')))->toString();
}

/**
 * The finished-goods subledger: sum(direction x cost_value) over live
 * movements of finished-good items (P1).
 */
function productionScenarioFgSubledger(): string
{
    $itemIds = Item::where('inventory_class', InventoryClass::FinishedGood->value)->pluck('id');

    $movements = ItemStockMovement::query()->where('cancelled', false)->whereIn('item_id', $itemIds)->get();

    return Money::sum($movements->map(function (ItemStockMovement $movement) {
        $cost = Money::of($movement->cost_value);

        return $movement->movement_type->direction() === 1 ? $cost : $cost->negated();
    }))->toString();
}

function assertProductionScenarioInvariants(string $expectedWip, string $expectedFg): void
{
    expect(productionScenarioGl(ManufacturingAccounts::WIP))->toBe($expectedWip)
        ->and(WipLedger::balance()->toString())->toBe($expectedWip)
        ->and(productionScenarioGl(ManufacturingAccounts::FINISHED_GOODS))->toBe($expectedFg)
        ->and(productionScenarioFgSubledger())->toBe($expectedFg);
}

test('the step-by-step costing scenario keeps WIP and FG equal to their subledgers and ends with no orphaned WIP', function () {
    $tenant = provisionProductionScenarioTenant();

    $tenant->run(function () {
        $actor = User::factory()->create();
        $storeId = productionScenarioStoreId();
        $cashId = (int) Account::where('code', 'AS1')->value('id');

        $rmA = Item::factory()->rawMaterial()->create(['name' => 'RM-A']);
        $rmB = Item::factory()->rawMaterial()->create(['name' => 'RM-B']);
        $fg = Item::factory()->finishedGood()->create(['name' => 'Widget']);

        $rmA->recordStockMovement(StockMovementType::Opening, '20', '2026-01-10', $storeId, null, null, Money::of('1000.00'));
        $rmB->recordStockMovement(StockMovementType::Opening, '10', '2026-01-10', $storeId, null, null, Money::of('1250.00'));

        // Step 1: issue RM-A 10 @ 50 and RM-B 4 @ 125.
        $note = MaterialIssueNote::post(
            ['date' => '2026-03-01', 'store_id' => $storeId],
            [['item_id' => $rmA->id, 'quantity' => '10'], ['item_id' => $rmB->id, 'quantity' => '4']],
            $actor,
        );

        expect((string) $note->total_cost)->toBe('1000.00');
        assertProductionScenarioInvariants('1000.00', '0.00');

        $entry = ProductionEntry::create(
            ['date' => '2026-03-01', 'store_id' => $storeId, 'finished_item_id' => $fg->id, 'planned_quantity' => '100'],
            [$note->id],
            $actor,
        );

        expect($entry->number)->toBe(1)
            ->and($entry->status)->toBe(ProductionEntry::STATUS_IN_PROGRESS)
            ->and(WipLedger::balance($entry)->toString())->toBe('1000.00')
            ->and($note->fresh()->production_entry_id)->toBe($entry->id);
        assertProductionScenarioInvariants('1000.00', '0.00');

        // Step 2: labour 300 and power 200.
        $labour = $entry->addDirectCost(['date' => '2026-03-02', 'cost_type' => 'labor', 'description' => 'Labour', 'amount' => '300', 'credit_account_id' => $cashId], $actor);
        $entry->addDirectCost(['date' => '2026-03-02', 'cost_type' => 'utilities', 'description' => 'Power', 'amount' => '200', 'credit_account_id' => $cashId], $actor);

        $voucher = JournalVoucher::findOrFail($labour->journal_voucher_id);
        expect($voucher->voucher_type)->toBe(VoucherType::Production);
        expect(WipLedger::balance($entry)->toString())->toBe('1500.00');
        assertProductionScenarioInvariants('1500.00', '0.00');

        // Step 3: 40 of the planned 100, non-final: 1500 x 40 / 100.
        $first = $entry->complete('2026-03-05', '40', false, $actor);

        expect((string) $first->cost)->toBe('600.00')
            ->and((string) $first->unit_cost)->toBe('15.0000')
            ->and($first->is_final)->toBeFalse()
            ->and($entry->fresh()->status)->toBe(ProductionEntry::STATUS_IN_PROGRESS)
            ->and($entry->remainingPlannedQuantity()->toString())->toBe('60.0000');

        $movement = ItemStockMovement::findOrFail($first->stock_movement_id);
        expect($movement->movement_type)->toBe(StockMovementType::ProductionReceipt)
            ->and((string) $movement->cost_value)->toBe('600.00')
            ->and($movement->item_id)->toBe($fg->id);

        // Materials are consumed in the same 40% proportion.
        $quantities = WipLedger::quantityByItem($entry);
        $values = WipLedger::valueByItem($entry);
        expect($quantities[$rmA->id]->toString())->toBe('6.0000')
            ->and($quantities[$rmB->id]->toString())->toBe('2.4000')
            ->and($values[$rmA->id]->toString())->toBe('300.00')
            ->and($values[$rmB->id]->toString())->toBe('300.00');

        assertProductionScenarioInvariants('900.00', '600.00');

        // Step 4: 60 more direct cost.
        $entry->addDirectCost(['date' => '2026-03-06', 'cost_type' => 'machine', 'description' => 'Machine hire', 'amount' => '60', 'credit_account_id' => $cashId], $actor);
        assertProductionScenarioInvariants('960.00', '600.00');

        // Step 5: final completion of 58 units takes the whole remaining WIP.
        $final = $entry->complete('2026-03-10', '58', true, $actor);

        expect((string) $final->cost)->toBe('960.00')
            ->and((string) $final->unit_cost)->toBe('16.5517')
            ->and($final->is_final)->toBeTrue()
            ->and($entry->fresh()->status)->toBe(ProductionEntry::STATUS_COMPLETED)
            ->and($entry->fresh()->completed_at)->not->toBeNull()
            ->and(WipLedger::balance($entry)->toString())->toBe('0.00');

        foreach (WipLedger::quantityByItem($entry) as $quantity) {
            expect($quantity->toString())->toBe('0.0000');
        }

        assertProductionScenarioInvariants('0.00', '1560.00');
        expect($fg->fresh()->currentStock($storeId)->toString())->toBe('98.0000');

        $sheet = $entry->fresh()->costSheet();

        expect($sheet['materials_total'])->toBe('1000.00')
            ->and($sheet['direct_costs_total'])->toBe('560.00')
            ->and($sheet['total_production_cost'])->toBe('1560.00')
            ->and($sheet['transferred_to_finished_goods'])->toBe('1560.00')
            ->and($sheet['completed_quantity'])->toBe('98.0000')
            // 1560 / 98 = 15.918367..., HalfUp at 4 decimals.
            ->and($sheet['cost_per_unit'])->toBe('15.9184')
            ->and($sheet['wip_balance'])->toBe('0.00')
            ->and($sheet['abandoned_value'])->toBe('0.00')
            ->and(collect($sheet['direct_costs'])->pluck('amount', 'cost_type')->all())
            ->toBe(['labor' => '300.00', 'utilities' => '200.00', 'machine' => '60.00']);

        $materials = collect($sheet['materials'])->keyBy('item_id');
        expect($materials[$rmA->id]['issued_quantity'])->toBe('10.0000')
            ->and($materials[$rmA->id]['net_cost'])->toBe('500.00')
            ->and($materials[$rmB->id]['issued_quantity'])->toBe('4.0000')
            ->and($materials[$rmB->id]['net_cost'])->toBe('500.00');
    });

    $tenant->delete();
});
