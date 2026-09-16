<?php

use App\Enums\FiscalYearStatus;
use App\Enums\StockMovementType;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\ItemStockMovement;
use App\Models\ItemUnit;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnLine;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Billing\BillingException;
use App\Support\Inventory\StockCosting;
use App\Support\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * T13 item 4: a purchase return with no Purchase row to point at - goods from
 * opening stock, or bought before this system went live. Valued at the item's
 * current weighted average cost (CONTRACTS C10) or at an entered rate, VAT at
 * the company's own rate, refunded cash/bank exact to the paisa (C3), and
 * numbered from the same debit-note series as any other return (C7).
 */
uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionUnlinkedReturnTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function unlinkedReturnOpenFiscalYear(): void
{
    FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
}

/**
 * 10 pieces at 101.11 on the books, so the item's weighted average cost is
 * exactly 101.11 per base unit and the return below has a basis to price
 * itself against.
 *
 * @return array{0: Item, 1: Purchase}
 */
function unlinkedReturnStockedItem(User $actor, Account $itemAccount): array
{
    $item = Item::factory()->create([
        'is_vatable' => true,
        'is_stockable' => true,
        'account_id' => $itemAccount->id,
    ]);

    $purchase = Purchase::post(
        ['supplier_id' => Supplier::factory()->create()->id, 'date' => '2026-06-01', 'payment_mode' => 'credit'],
        [['item_id' => $item->id, 'quantity' => '10', 'rate' => '101.11']],
        $actor,
    );

    return [$item->fresh(), $purchase];
}

test('an unlinked return values its lines at the current average cost and refunds a cash plus bank split exactly', function () {
    $tenant = provisionUnlinkedReturnTenant('unlinked-purchase-return-average.tenant-test');

    $tenant->run(function () {
        unlinkedReturnOpenFiscalYear();
        $actor = User::factory()->create();
        $itemAccount = Account::factory()->create();
        [$item, $purchase] = unlinkedReturnStockedItem($actor, $itemAccount);
        $supplier = Supplier::factory()->create();
        $bank = Account::factory()->create();

        // 3 pieces at the 101.11 average = 303.33, VAT 13% = 39.43 (303.33 x
        // 13 / 100 = 39.4329, one HalfUp rounding), total 342.76.
        $return = PurchaseReturn::postUnlinked(
            [
                'date' => '2026-06-10',
                'supplier_id' => $supplier->id,
                'store_id' => $purchase->store_id,
                'reason' => 'Opening stock returned',
                'payment_mode' => 'partial',
                'bank_account_id' => $bank->id,
                'cash_amount' => '142.76',
                'bank_amount' => '200.00',
            ],
            [['item_id' => $item->id, 'quantity' => '3']],
            $actor,
        );

        expect($return->is_unlinked)->toBeTrue()
            ->and($return->purchase_id)->toBeNull()
            ->and($return->supplier_id)->toEqual($supplier->id)
            ->and($return->taxable_amount)->toBe('303.33')
            ->and($return->nontaxable_amount)->toBe('0.00')
            ->and($return->vat_amount)->toBe('39.43')
            ->and($return->total)->toBe('342.76')
            ->and($return->tds_amount)->toBe('0.00')
            ->and($return->cash_amount)->toBe('142.76')
            ->and($return->bank_amount)->toBe('200.00')
            ->and($return->bank_account_id)->toEqual($bank->id)
            // Debit notes are numbered from the same stored series as a
            // linked return (CONTRACTS C7), never re-derived at display time.
            ->and($return->debit_note_number)->not->toBeNull()
            ->and($return->fiscal_year_id)->not->toBeNull()
            ->and($return->status)->toBe('posted');

        $voucher = $return->journalVoucher()->with('lines')->firstOrFail();
        $debits = Money::sum($voucher->lines->map(fn ($line): Money => Money::of($line->debit)));
        $credits = Money::sum($voucher->lines->map(fn ($line): Money => Money::of($line->credit)));

        expect($debits->toString())->toBe('342.76')
            ->and($credits->toString())->toBe('342.76');

        // The goods come off the item's own purchase account, the input VAT
        // claimed on them is given back, and the money comes in as cash + bank.
        expect($voucher->lines->where('account_id', $itemAccount->id)->sum('credit'))->toEqual('303.33')
            ->and($voucher->lines->where('account_id', $bank->id)->sum('debit'))->toEqual('200.00');

        $vatAccount = Account::where('code', 'ASA23')->firstOrFail();
        expect($voucher->lines->where('account_id', $vatAccount->id)->sum('credit'))->toEqual('39.43');
    });

    $tenant->delete();
});

test('an unlinked return at average cost takes stock out and leaves the average cost unchanged', function () {
    $tenant = provisionUnlinkedReturnTenant('unlinked-purchase-return-costing.tenant-test');

    $tenant->run(function () {
        unlinkedReturnOpenFiscalYear();
        $actor = User::factory()->create();
        $itemAccount = Account::factory()->create();
        [$item, $purchase] = unlinkedReturnStockedItem($actor, $itemAccount);

        $return = PurchaseReturn::postUnlinked(
            [
                'date' => '2026-06-10',
                'store_id' => $purchase->store_id,
                'payment_mode' => 'cash',
            ],
            [['item_id' => $item->id, 'quantity' => '3']],
            $actor,
        );

        /** @var PurchaseReturnLine $line */
        $line = $return->lines()->firstOrFail();

        expect($line->purchase_line_id)->toBeNull()
            ->and($line->item_id)->toEqual($item->id)
            ->and($line->unit_conversion_factor)->toBe('1.0000')
            ->and($line->quantity)->toBe('3.0000')
            ->and($line->net_value)->toBe('303.33')
            ->and($line->vat_amount)->toBe('39.43')
            ->and($return->cash_amount)->toBe('342.76')
            ->and($return->bank_amount)->toBe('0.00');

        $movement = ItemStockMovement::where('reference_type', (new PurchaseReturnLine)->getMorphClass())
            ->where('reference_id', $line->id)
            ->firstOrFail();

        expect($movement->movement_type)->toBe(StockMovementType::PurchaseReturn)
            ->and($movement->quantity)->toBe('3.0000')
            ->and($movement->value)->toBe('303.33')
            ->and($movement->unit_cost_rate)->toBe('101.1100');

        // 7 pieces left, and (1011.10 - 303.33) / 7 is still 101.11: sending
        // goods back at what they cost cannot move the average (C10).
        expect($item->fresh()->currentStock()->toString())->toBe('7.0000')
            ->and((string) StockCosting::averageCost($item->fresh(), '2026-06-30')->toScale(4))->toBe('101.1100')
            ->and(StockCosting::closingValue($item->fresh(), '2026-06-30')->toString())->toBe('707.77');
    });

    $tenant->delete();
});

test('an entered rate overrides the average cost and an alternate unit converts to base units', function () {
    $tenant = provisionUnlinkedReturnTenant('unlinked-purchase-return-rate.tenant-test');

    $tenant->run(function () {
        unlinkedReturnOpenFiscalYear();
        $actor = User::factory()->create();
        $itemAccount = Account::factory()->create();
        [$item, $purchase] = unlinkedReturnStockedItem($actor, $itemAccount);
        $box = ItemUnit::factory()->create(['item_id' => $item->id, 'name' => 'Box', 'conversion_factor' => 5]);

        // 1 Box of 5 at an agreed 400.00 per Box: 400.00 credited, 5 pieces
        // out of stock - the rate is per entered unit, the stock is in base
        // units.
        $return = PurchaseReturn::postUnlinked(
            [
                'date' => '2026-06-10',
                'store_id' => $purchase->store_id,
                'payment_mode' => 'cash',
                'expected_total' => '452.00',
            ],
            [['item_id' => $item->id, 'item_unit_id' => $box->id, 'quantity' => '1', 'rate' => '400']],
            $actor,
        );

        $line = $return->lines()->firstOrFail();

        expect($return->taxable_amount)->toBe('400.00')
            ->and($return->vat_amount)->toBe('52.00')
            ->and($return->total)->toBe('452.00')
            ->and($line->rate)->toBe('400.0000')
            ->and($line->unit_conversion_factor)->toBe('5.0000')
            ->and($item->fresh()->currentStock()->toString())->toBe('5.0000');
    });

    $tenant->delete();
});

test('a cash plus bank split that misses the total by a paisa is refused', function () {
    $tenant = provisionUnlinkedReturnTenant('unlinked-purchase-return-split.tenant-test');

    $tenant->run(function () {
        unlinkedReturnOpenFiscalYear();
        $actor = User::factory()->create();
        $itemAccount = Account::factory()->create();
        [$item, $purchase] = unlinkedReturnStockedItem($actor, $itemAccount);
        $bank = Account::factory()->create();

        // 342.76 is due; 142.75 + 200.00 is 342.75. No tolerance (C3).
        expect(fn () => PurchaseReturn::postUnlinked(
            [
                'date' => '2026-06-10',
                'store_id' => $purchase->store_id,
                'payment_mode' => 'partial',
                'bank_account_id' => $bank->id,
                'cash_amount' => '142.75',
                'bank_amount' => '200.00',
            ],
            [['item_id' => $item->id, 'quantity' => '3']],
            $actor,
        ))->toThrow(BillingException::class);

        // Nothing was posted: the stock is untouched and no note exists.
        expect($item->fresh()->currentStock()->toString())->toBe('10.0000')
            ->and(PurchaseReturn::count())->toBe(0);
    });

    $tenant->delete();
});

test('an unlinked return cannot send back more than the store holds', function () {
    $tenant = provisionUnlinkedReturnTenant('unlinked-purchase-return-stock.tenant-test');

    $tenant->run(function () {
        unlinkedReturnOpenFiscalYear();
        $actor = User::factory()->create();
        $itemAccount = Account::factory()->create();
        [$item, $purchase] = unlinkedReturnStockedItem($actor, $itemAccount);

        expect(fn () => PurchaseReturn::postUnlinked(
            [
                'date' => '2026-06-10',
                'store_id' => $purchase->store_id,
                'payment_mode' => 'cash',
            ],
            [['item_id' => $item->id, 'quantity' => '11']],
            $actor,
        ))->toThrow(InvalidArgumentException::class, 'remain in this store');
    });

    $tenant->delete();
});

test('a cancelled unlinked return reverses its voucher and puts the stock back', function () {
    $tenant = provisionUnlinkedReturnTenant('unlinked-purchase-return-cancel.tenant-test');

    $tenant->run(function () {
        unlinkedReturnOpenFiscalYear();
        $actor = User::factory()->create();
        $itemAccount = Account::factory()->create();
        [$item, $purchase] = unlinkedReturnStockedItem($actor, $itemAccount);

        $return = PurchaseReturn::postUnlinked(
            [
                'date' => '2026-06-10',
                'store_id' => $purchase->store_id,
                'payment_mode' => 'cash',
            ],
            [['item_id' => $item->id, 'quantity' => '3']],
            $actor,
        );

        $return->cancel($actor, 'Entered against the wrong item');

        expect($return->fresh()->status)->toBe('cancelled')
            ->and($return->fresh()->reversal_journal_voucher_id)->not->toBeNull()
            ->and($item->fresh()->currentStock()->toString())->toBe('10.0000');
    });

    $tenant->delete();
});
