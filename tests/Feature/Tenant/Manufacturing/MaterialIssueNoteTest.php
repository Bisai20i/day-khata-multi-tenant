<?php

use App\Enums\FiscalYearStatus;
use App\Enums\StockMovementType;
use App\Enums\VoucherType;
use App\Models\Account;
use App\Models\CompanySetting;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\ItemStockMovement;
use App\Models\JournalVoucher;
use App\Models\MaterialIssueNote;
use App\Models\ProductionEntry;
use App\Models\ProductionWipEntry;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Manufacturing\WipLedger;
use App\Support\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

/**
 * A tenant with one open year (2026), the default store, an owner and a raw
 * material holding 100 kg bought for Rs 5,000 (average Rs 50 per kg).
 *
 * @return array{0: Tenant, 1: string}
 */
function provisionMaterialIssueTenant(): array
{
    $domain = 'mat-issue-'.Str::lower(Str::random(8)).'.tenant-test';
    // Manufacturing is not a default module, so the tenant is entitled to it
    // explicitly or every manufacturing route answers 403.
    $tenant = Tenant::create([
        'company_name' => 'Acme Co',
        'enabled_modules' => [...config('permissions.default_modules'), 'manufacturing'],
    ]);
    $tenant->domains()->create(['domain' => $domain]);

    $tenant->run(function () {
        FiscalYear::create(['name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
    });

    return [$tenant, $domain];
}

function materialIssueStore(): Store
{
    return Store::where('is_active', true)->orderBy('id')->firstOrFail();
}

function materialIssueRawItem(string $name = 'Flour', string $quantity = '100', string $value = '5000.00'): Item
{
    $item = Item::factory()->rawMaterial()->create(['name' => $name]);
    $item->recordStockMovement(StockMovementType::Opening, $quantity, '2026-01-10', materialIssueStore()->id, null, null, Money::of($value));

    return $item;
}

function postMaterialIssue(Item $item, string $quantity, string $date = '2026-06-15'): MaterialIssueNote
{
    return MaterialIssueNote::post(
        ['date' => $date, 'store_id' => materialIssueStore()->id],
        [['item_id' => $item->id, 'quantity' => $quantity]],
        User::factory()->create(),
    );
}

test('an issue posts one balanced Dr AS13 / Cr AS12 voucher equal to the movement cost, plus WIP rows', function () {
    [$tenant] = provisionMaterialIssueTenant();

    $tenant->run(function () {
        $item = materialIssueRawItem();

        $note = postMaterialIssue($item, '40');

        $movement = ItemStockMovement::findOrFail($note->items->first()->stock_movement_id);
        expect($movement->movement_type)->toBe(StockMovementType::MaterialIssue);
        expect((string) $movement->cost_value)->toBe('2000.00');

        $voucher = JournalVoucher::findOrFail($note->journal_voucher_id);
        expect($voucher->voucher_type)->toBe(VoucherType::MaterialIssue);
        expect($voucher->voucher_number)->toBe($note->number);

        $lines = $voucher->lines;
        expect($lines)->toHaveCount(2);

        $wip = $lines->firstWhere('account_id', Account::where('code', 'AS13')->value('id'));
        $raw = $lines->firstWhere('account_id', Account::where('code', 'AS12')->value('id'));
        expect((string) $wip->debit)->toBe('2000.00');
        expect((string) $raw->credit)->toBe('2000.00');
        expect((string) $note->total_cost)->toBe('2000.00');

        $wipRows = ProductionWipEntry::where('material_issue_note_id', $note->id)->get();
        expect($wipRows)->toHaveCount(1);
        expect($wipRows->first()->kind)->toBe(ProductionWipEntry::KIND_MATERIAL_IN);
        expect((string) $wipRows->first()->value)->toBe('2000.00');
        expect((string) $wipRows->first()->quantity)->toBe('40.0000');
        expect(WipLedger::balance()->toString())->toBe('2000.00');
    });
});

test('issuing the whole balance relieves the whole value with no residue', function () {
    [$tenant] = provisionMaterialIssueTenant();

    $tenant->run(function () {
        $item = materialIssueRawItem('Sugar', '3', '100.00');

        $first = postMaterialIssue($item, '1');
        $second = postMaterialIssue($item, '2');

        expect((string) $first->total_cost)->toBe('33.33');
        expect((string) $second->total_cost)->toBe('66.67');
    });
});

test('a trading item or a finished good is rejected', function () {
    [$tenant] = provisionMaterialIssueTenant();

    $tenant->run(function () {
        $trading = Item::factory()->create(['is_stockable' => true]);
        $trading->recordStockMovement(StockMovementType::Opening, 10, '2026-01-10', materialIssueStore()->id, null, null, Money::of('100.00'));
        $finished = Item::factory()->finishedGood()->create();
        $finished->recordStockMovement(StockMovementType::Opening, 10, '2026-01-10', materialIssueStore()->id, null, null, Money::of('100.00'));

        expect(fn () => postMaterialIssue($trading, '1'))->toThrow(InvalidArgumentException::class, 'not a raw material');
        expect(fn () => postMaterialIssue($finished, '1'))->toThrow(InvalidArgumentException::class, 'not a raw material');
        expect(MaterialIssueNote::count())->toBe(0);
    });
});

test('a shortfall is rejected even when negative stock is allowed', function () {
    [$tenant] = provisionMaterialIssueTenant();

    $tenant->run(function () {
        CompanySetting::current()->update(['allow_negative_stock' => true]);
        $item = materialIssueRawItem('Salt', '10', '100.00');

        expect(fn () => postMaterialIssue($item, '10.5'))->toThrow(InvalidArgumentException::class, 'Insufficient stock for "Salt"');
        expect(MaterialIssueNote::count())->toBe(0);
        expect(ProductionWipEntry::count())->toBe(0);
    });
});

test('the stock check is aggregated per item across lines', function () {
    [$tenant] = provisionMaterialIssueTenant();

    $tenant->run(function () {
        $item = materialIssueRawItem('Oil', '10', '100.00');

        expect(fn () => MaterialIssueNote::post(
            ['date' => '2026-06-15', 'store_id' => materialIssueStore()->id],
            [['item_id' => $item->id, 'quantity' => '6'], ['item_id' => $item->id, 'quantity' => '6']],
            User::factory()->create(),
        ))->toThrow(InvalidArgumentException::class, 'Insufficient stock');
    });
});

test('a date in a closed fiscal year is rejected', function () {
    [$tenant] = provisionMaterialIssueTenant();

    $tenant->run(function () {
        $item = materialIssueRawItem();
        FiscalYear::query()->update(['status' => FiscalYearStatus::Closed]);

        expect(fn () => postMaterialIssue($item, '1'))->toThrow(InvalidArgumentException::class);
        expect(MaterialIssueNote::count())->toBe(0);
    });
});

test('cancelling an unattached note reverses the voucher, the movements and the WIP rows', function () {
    [$tenant] = provisionMaterialIssueTenant();

    $tenant->run(function () {
        $item = materialIssueRawItem();
        $note = postMaterialIssue($item, '40', now()->setTimezone('Asia/Kathmandu')->toDateString());
        expect($item->currentStock()->toString())->toBe('60.0000');

        $note->cancel(User::factory()->create(), 'Issued by mistake');

        $note->refresh();
        expect($note->status)->toBe('cancelled');
        expect($note->reversal_journal_voucher_id)->not->toBeNull();
        expect(JournalVoucher::findOrFail($note->journal_voucher_id)->status)->toBe('cancelled');
        expect(JournalVoucher::findOrFail($note->reversal_journal_voucher_id)->voucher_type)->toBe(VoucherType::Reversal);
        expect(ItemStockMovement::findOrFail($note->items->first()->stock_movement_id)->cancelled)->toBeTrue();
        expect($item->currentStock()->toString())->toBe('100.0000');
        expect(ProductionWipEntry::where('material_issue_note_id', $note->id)->where('cancelled', false)->count())->toBe(0);
        expect(WipLedger::balance()->toString())->toBe('0.00');
    });
});

test('cancelling a note attached to a production entry is rejected', function () {
    [$tenant] = provisionMaterialIssueTenant();

    $tenant->run(function () {
        $item = materialIssueRawItem();
        $note = postMaterialIssue($item, '40', now()->setTimezone('Asia/Kathmandu')->toDateString());
        $entry = ProductionEntry::factory()->create(['store_id' => $note->store_id]);
        $note->update(['production_entry_id' => $entry->id]);

        expect(fn () => $note->cancel(User::factory()->create(), 'Too late'))
            ->toThrow(InvalidArgumentException::class, 'attached to a production entry');
        expect($note->fresh()->status)->toBe('posted');
    });
});

test('WipLedger balance and per-item figures follow non-cancelled rows', function () {
    [$tenant] = provisionMaterialIssueTenant();

    $tenant->run(function () {
        $item = materialIssueRawItem();
        $entry = ProductionEntry::factory()->create(['store_id' => materialIssueStore()->id]);

        $note = MaterialIssueNote::post(
            ['date' => '2026-06-15', 'store_id' => materialIssueStore()->id, 'production_entry_id' => $entry->id],
            [['item_id' => $item->id, 'quantity' => '10']],
            User::factory()->create(),
        );

        expect(WipLedger::balance($entry)->toString())->toBe('500.00');
        expect(WipLedger::balance($entry, '2026-06-14')->toString())->toBe('0.00');
        expect(WipLedger::quantityByItem($entry)[$item->id]->toString())->toBe('10.0000');
        expect(WipLedger::valueByItem($entry)[$item->id]->toString())->toBe('500.00');

        ProductionWipEntry::where('material_issue_note_id', $note->id)->update(['cancelled' => true]);
        expect(WipLedger::balance($entry)->toString())->toBe('0.00');
    });
});

test('a user without material_issues.create gets 403 on store', function () {
    [$tenant, $domain] = provisionMaterialIssueTenant();

    $user = $tenant->run(fn () => userWithPermissions(['material_issues.view']));
    test()->actingAs($user, 'web');
    tenancy()->end();

    test()->post("http://{$domain}/material-issues", [
        'date' => '2026-06-15',
        'lines' => [['item_id' => 1, 'quantity' => '1']],
    ])->assertForbidden();
});

test('a user with material_issues.view can open the list', function () {
    [$tenant, $domain] = provisionMaterialIssueTenant();

    $user = $tenant->run(fn () => userWithPermissions(['material_issues.view']));
    test()->actingAs($user, 'web');
    tenancy()->end();

    test()->get("http://{$domain}/material-issues")->assertOk();
});
