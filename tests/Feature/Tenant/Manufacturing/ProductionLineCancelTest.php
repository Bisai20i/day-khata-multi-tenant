<?php

use App\Enums\FiscalYearStatus;
use App\Enums\StockMovementType;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\ItemStockMovement;
use App\Models\JournalVoucher;
use App\Models\JournalVoucherLine;
use App\Models\MaterialIssueNote;
use App\Models\ProductionCompletion;
use App\Models\ProductionEntry;
use App\Models\ProductionWipEntry;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Inventory\PerpetualCosting;
use App\Support\Manufacturing\ManufacturingAccounts;
use App\Support\Manufacturing\WipLedger;
use App\Support\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

/**
 * M08a: cancelling the latest completion or material return of a production
 * entry. Each cancel must put the GL (AS12/AS13/AS14), the WIP subledger and
 * the perpetual stock subledgers back exactly where they were before the
 * row, so the next posting costs as if the cancelled one never happened.
 */
uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function lineCancelTenant(): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => 'line-cancel-'.Str::lower(Str::random(8)).'.tenant-test']);

    $tenant->run(function () {
        FiscalYear::create(['name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
    });

    return $tenant;
}

function lineCancelStoreId(): int
{
    return (int) Store::where('is_active', true)->orderBy('id')->value('id');
}

function lineCancelGl(string $code): string
{
    $lines = JournalVoucherLine::where('account_id', Account::where('code', $code)->value('id'))->get();

    return Money::sum($lines->pluck('debit'))->minus(Money::sum($lines->pluck('credit')))->toString();
}

/**
 * An in-progress entry planned for $planned units: 100 of raw material
 * bought at 50.00 (Dr AS12 5,000.00, so the RM subledger and GL agree), 10
 * issued (WIP 500.00) and a 101.00 labour cost (WIP 601.00), so a partial
 * completion splits across a material and a non-material bucket.
 *
 * @return array{entry: ProductionEntry, raw: Item, finished: Item, actor: User}
 */
function lineCancelStarted(string $planned = '7'): array
{
    $actor = User::factory()->create();
    $storeId = lineCancelStoreId();
    $cashId = (int) Account::where('code', 'AS1')->value('id');

    $raw = Item::factory()->rawMaterial()->create();
    $finished = Item::factory()->finishedGood()->create();

    $raw->recordStockMovement(StockMovementType::Purchase, '100', '2026-01-10', $storeId, null, '50.0000', '5000.00', costValue: '5000.00');
    JournalVoucher::post(['date' => '2026-01-10', 'narration' => 'RM purchase fixture'], [
        ['account_id' => ManufacturingAccounts::id(ManufacturingAccounts::RAW_MATERIAL), 'debit' => '5000.00', 'credit' => '0.00'],
        ['account_id' => $cashId, 'debit' => '0.00', 'credit' => '5000.00'],
    ], $actor);

    $note = MaterialIssueNote::post(['date' => '2026-06-01', 'store_id' => $storeId], [['item_id' => $raw->id, 'quantity' => '10']], $actor);

    $entry = ProductionEntry::create(
        ['date' => '2026-06-01', 'store_id' => $storeId, 'finished_item_id' => $finished->id, 'planned_quantity' => $planned],
        [$note->id],
        $actor,
    );

    $entry->addDirectCost(['date' => '2026-06-02', 'cost_type' => 'labor', 'amount' => '101.00', 'credit_account_id' => $cashId], $actor);

    return ['entry' => $entry->fresh(), 'raw' => $raw, 'finished' => $finished, 'actor' => $actor];
}

/**
 * Everything a completion or return moves, as comparable strings: the three
 * class accounts, the entry's WIP (total, per item quantity and value), the
 * stock and perpetual value of both items, and the remaining planned
 * quantity.
 *
 * @return array<string, mixed>
 */
function lineCancelState(ProductionEntry $entry, Item $raw, Item $finished): array
{
    $entry = $entry->fresh();
    $stock = Item::currentStockByItem([$raw->id, $finished->id], lineCancelStoreId());

    return [
        'as12' => lineCancelGl(ManufacturingAccounts::RAW_MATERIAL),
        'as13' => lineCancelGl(ManufacturingAccounts::WIP),
        'as14' => lineCancelGl(ManufacturingAccounts::FINISHED_GOODS),
        'wip' => WipLedger::balance($entry)->toString(),
        'wip_quantity' => array_map(fn ($quantity) => $quantity->toString(), WipLedger::quantityByItem($entry)),
        'wip_value' => array_map(fn ($value) => $value->toString(), WipLedger::valueByItem($entry)),
        'raw_stock' => $stock[$raw->id]->toString(),
        'raw_value' => PerpetualCosting::valueBalance($raw)->toString(),
        'finished_stock' => $stock[$finished->id]->toString(),
        'finished_value' => PerpetualCosting::valueBalance($finished)->toString(),
        'remaining_planned' => $entry->remainingPlannedQuantity()->toString(),
    ];
}

/**
 * @return list<string> "item|quantity|value" per WIP row the completion wrote
 */
function lineCancelCompletionRows(ProductionCompletion $completion): array
{
    return ProductionWipEntry::query()
        ->where('source_type', $completion->getMorphClass())
        ->where('source_id', $completion->getKey())
        ->orderBy('id')
        ->get()
        ->map(fn (ProductionWipEntry $row) => ($row->item_id ?? '-').'|'.($row->quantity ?? '-').'|'.Money::of($row->value)->toString())
        ->all();
}

/**
 * @return list<JournalVoucher> the entry's material return vouchers, oldest first
 */
function lineCancelReturnVouchers(ProductionEntry $entry): array
{
    return ProductionWipEntry::query()
        ->where('production_entry_id', $entry->id)
        ->where('kind', ProductionWipEntry::KIND_MATERIAL_OUT)
        ->orderBy('id')
        ->pluck('source_id')
        ->map(fn ($id) => JournalVoucher::query()->findOrFail($id))
        ->all();
}

test('cancelling the latest partial completion restores the books and the next completion costs the same', function () {
    $tenant = lineCancelTenant();

    $tenant->run(function () {
        ['entry' => $entry, 'raw' => $raw, 'finished' => $finished, 'actor' => $actor] = lineCancelStarted();

        $before = lineCancelState($entry, $raw, $finished);
        expect($before['as13'])->toBe('601.00')->and($before['wip'])->toBe('601.00');

        $completion = $entry->complete('2026-06-05', '3', false, $actor);
        $cancelledRows = lineCancelCompletionRows($completion);

        expect(lineCancelState($entry, $raw, $finished))->not->toBe($before);

        $entry->cancelCompletion($completion, $actor, 'Output keyed twice.');

        expect(lineCancelState($entry, $raw, $finished))->toBe($before);

        $completion->refresh();
        expect($completion->cancelled_at)->not->toBeNull()
            ->and($completion->cancel_reason)->toBe('Output keyed twice.')
            ->and(ItemStockMovement::query()->findOrFail($completion->stock_movement_id)->cancelled)->toBeTrue()
            ->and(JournalVoucher::query()->findOrFail($completion->journal_voucher_id)->status)->toBe('cancelled')
            ->and(JournalVoucher::where('reversal_of_id', $completion->journal_voucher_id)->exists())->toBeTrue()
            ->and(ProductionWipEntry::where('source_type', $completion->getMorphClass())->where('source_id', $completion->id)->where('cancelled', false)->exists())->toBeFalse()
            ->and($entry->fresh()->status)->toBe(ProductionEntry::STATUS_IN_PROGRESS);

        // Posted again, the same completion takes exactly the same cost and
        // the same per-bucket split as the cancelled one did.
        $again = $entry->fresh()->complete('2026-06-05', '3', false, $actor);

        expect(Money::of($again->cost)->toString())->toBe(Money::of($completion->cost)->toString())
            ->and(lineCancelCompletionRows($again))->toBe($cancelledRows);
    });

    $tenant->delete();
});

test('only the latest completion can be cancelled, and cancelling the final one reopens the entry', function () {
    $tenant = lineCancelTenant();

    $tenant->run(function () {
        ['entry' => $entry, 'raw' => $raw, 'finished' => $finished, 'actor' => $actor] = lineCancelStarted('10');

        $first = $entry->complete('2026-06-03', '2', false, $actor);
        $second = $entry->complete('2026-06-04', '2', false, $actor);

        expect(fn () => $entry->cancelCompletion($first, $actor, 'Wrong one.'))
            ->toThrow(InvalidArgumentException::class, 'Only the latest completion');
        expect($first->fresh()->cancelled_at)->toBeNull();

        $beforeFinal = lineCancelState($entry, $raw, $finished);

        $final = $entry->complete('2026-06-05', '5', true, $actor);
        expect($entry->fresh()->status)->toBe(ProductionEntry::STATUS_COMPLETED)
            ->and(WipLedger::balance($entry->fresh())->toString())->toBe('0.00');

        // A partial completion cannot be undone once the entry is completed.
        expect(fn () => $entry->cancelCompletion($second, $actor, 'Wrong one.'))
            ->toThrow(InvalidArgumentException::class, 'is completed');

        $entry->cancelCompletion($final, $actor, 'Final count was wrong.');

        $fresh = $entry->fresh();
        expect($fresh->status)->toBe(ProductionEntry::STATUS_IN_PROGRESS)
            ->and($fresh->completed_at)->toBeNull()
            ->and($final->fresh()->cancelled_at)->not->toBeNull()
            ->and(lineCancelState($entry, $raw, $finished))->toBe($beforeFinal);

        // The entry can be finished again and takes the same remainder.
        $refinal = $fresh->complete('2026-06-06', '6', true, $actor);

        expect(Money::of($refinal->cost)->toString())->toBe(Money::of($final->cost)->toString())
            ->and($entry->fresh()->status)->toBe(ProductionEntry::STATUS_COMPLETED)
            ->and(lineCancelGl(ManufacturingAccounts::WIP))->toBe('0.00');
    });

    $tenant->delete();
});

test('a completion whose goods were sold cannot be cancelled', function () {
    $tenant = lineCancelTenant();

    $tenant->run(function () {
        ['entry' => $entry, 'finished' => $finished, 'actor' => $actor] = lineCancelStarted();

        $completion = $entry->complete('2026-06-05', '4', false, $actor);

        // 3 of the 4 units leave the store afterwards.
        $finished->recordStockMovement(StockMovementType::Sale, '3', '2026-06-10', lineCancelStoreId(), null, null, '900.00');

        expect(fn () => $entry->cancelCompletion($completion, $actor, 'Too late.'))
            ->toThrow(InvalidArgumentException::class, 'short by 3');

        expect($completion->fresh()->cancelled_at)->toBeNull()
            ->and(ItemStockMovement::query()->findOrFail($completion->stock_movement_id)->cancelled)->toBeFalse()
            ->and(JournalVoucher::query()->findOrFail($completion->journal_voucher_id)->status)->not->toBe('cancelled');
    });

    $tenant->delete();
});

test('cancelling material returns restores WIP, latest first only', function () {
    $tenant = lineCancelTenant();

    $tenant->run(function () {
        ['entry' => $entry, 'raw' => $raw, 'finished' => $finished, 'actor' => $actor] = lineCancelStarted();

        $original = lineCancelState($entry, $raw, $finished);

        $entry->returnMaterial($raw->id, '2', '2026-06-03', $actor);
        $afterFirst = lineCancelState($entry, $raw, $finished);

        $entry->returnMaterial($raw->id, '3', '2026-06-04', $actor);

        [$firstVoucher, $secondVoucher] = lineCancelReturnVouchers($entry);

        expect(fn () => $entry->cancelMaterialReturn($firstVoucher, $actor, 'Wrong bag.'))
            ->toThrow(InvalidArgumentException::class, 'Only the latest material return');

        $entry->cancelMaterialReturn($secondVoucher, $actor, 'Wrong bag.');

        expect(lineCancelState($entry, $raw, $finished))->toBe($afterFirst)
            ->and($secondVoucher->fresh()->status)->toBe('cancelled')
            ->and(JournalVoucher::where('reversal_of_id', $secondVoucher->id)->exists())->toBeTrue();

        expect(fn () => $entry->cancelMaterialReturn($secondVoucher, $actor, 'Again.'))
            ->toThrow(InvalidArgumentException::class, 'already been cancelled');

        $entry->cancelMaterialReturn($firstVoucher, $actor, 'Wrong bag.');

        expect(lineCancelState($entry, $raw, $finished))->toBe($original)
            ->and(ItemStockMovement::query()
                ->where('movement_type', StockMovementType::MaterialReturn->value)
                ->where('cancelled', true)
                ->count())->toBe(2);
    });

    $tenant->delete();
});

test('a material return whose stock was issued again cannot be cancelled', function () {
    $tenant = lineCancelTenant();

    $tenant->run(function () {
        ['entry' => $entry, 'raw' => $raw, 'actor' => $actor] = lineCancelStarted();

        $entry->returnMaterial($raw->id, '4', '2026-06-03', $actor);

        // The store held 90 after the issue, 94 after the return; all of it
        // goes out again on an unattached note.
        MaterialIssueNote::post(['date' => '2026-06-05', 'store_id' => lineCancelStoreId()], [['item_id' => $raw->id, 'quantity' => '94']], $actor);

        [$voucher] = lineCancelReturnVouchers($entry);

        expect(fn () => $entry->cancelMaterialReturn($voucher, $actor, 'Too late.'))
            ->toThrow(InvalidArgumentException::class, 'short by 4');

        expect($voucher->fresh()->status)->not->toBe('cancelled');
    });

    $tenant->delete();
});
