<?php

use App\Enums\FiscalYearStatus;
use App\Enums\StockMovementType;
use App\Enums\VoucherType;
use App\Models\Account;
use App\Models\CompanySetting;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\ItemStockMovement;
use App\Models\ItemUnit;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\PurchaseLine;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnLine;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Billing\BillingException;
use App\Support\Billing\DocumentCalculator;
use App\Support\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionPurchaseCostingTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function purchaseCostingActor(): User
{
    return User::factory()->create();
}

function purchaseCostingOpenFiscalYear(): void
{
    FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
}

test('a purchase stores exactly what DocumentCalculator says, to the paisa', function () {
    $tenant = provisionPurchaseCostingTenant('purchase-costing-golden.tenant-test');

    $tenant->run(function () {
        purchaseCostingOpenFiscalYear();
        $actor = purchaseCostingActor();
        $supplier = Supplier::factory()->create();
        $vatItem = Item::factory()->create(['is_vatable' => true, 'is_stockable' => true]);
        $exemptItem = Item::factory()->create(['is_vatable' => false, 'is_stockable' => true]);

        $lines = [
            ['item_id' => $vatItem->id, 'quantity' => '1.5', 'rate' => '33.33'],
            ['item_id' => $exemptItem->id, 'quantity' => '3', 'rate' => '111.11'],
        ];

        $purchase = Purchase::post(
            [
                'supplier_id' => $supplier->id,
                'date' => '2026-06-01',
                'payment_mode' => 'credit',
                'vat_rate' => '13',
                'discount' => '10',
                'discount_type' => 'percentage',
            ],
            $lines,
            $actor,
        );

        $expected = DocumentCalculator::calculate(
            [
                ['quantity' => '1.5', 'rate' => '33.33', 'vatable' => true],
                ['quantity' => '3', 'rate' => '111.11', 'vatable' => false],
            ],
            ['vat_rate' => '13', 'discount' => '10', 'discount_type' => 'percentage'],
        );

        expect($purchase->taxable_amount)->toBe($expected->taxableAmount->toString())
            ->and($purchase->nontaxable_amount)->toBe($expected->nontaxableAmount->toString())
            ->and($purchase->vat_amount)->toBe($expected->vatAmount->toString())
            ->and($purchase->total)->toBe($expected->total->toString());

        // The header discount reached the lines, so the net values add back up
        // to the taxable plus non-taxable amounts with nothing left over.
        $net = Money::sum($purchase->lines->map(fn (PurchaseLine $line): Money => Money::of($line->net_value)));
        expect($net->toString())->toBe($expected->taxableAmount->plus($expected->nontaxableAmount)->toString());
    });

    $tenant->delete();
});

test('a header discount split over three expense accounts loses no paisa', function () {
    $tenant = provisionPurchaseCostingTenant('purchase-costing-threeway.tenant-test');

    $tenant->run(function () {
        purchaseCostingOpenFiscalYear();
        $actor = purchaseCostingActor();
        $supplier = Supplier::factory()->create();

        $accounts = collect(range(1, 3))->map(fn () => Account::factory()->create());
        $items = $accounts->map(fn (Account $account) => Item::factory()->create([
            'is_vatable' => true,
            'is_stockable' => false,
            'account_id' => $account->id,
        ]));

        // 100.00 off a 1,000.01 subtotal split three ways is the classic
        // largest-remainder case: a proportional float split would lose a paisa.
        $purchase = Purchase::post(
            [
                'supplier_id' => $supplier->id,
                'date' => '2026-06-01',
                'payment_mode' => 'credit',
                'discount' => '100',
                'discount_type' => 'flat',
            ],
            [
                ['item_id' => $items[0]->id, 'quantity' => '1', 'rate' => '333.33'],
                ['item_id' => $items[1]->id, 'quantity' => '1', 'rate' => '333.34'],
                ['item_id' => $items[2]->id, 'quantity' => '1', 'rate' => '333.34'],
            ],
            $actor,
        );

        $voucher = $purchase->journalVoucher()->with('lines')->firstOrFail();

        $debits = Money::sum($voucher->lines->map(fn ($line): Money => Money::of($line->debit)));
        $credits = Money::sum($voucher->lines->map(fn ($line): Money => Money::of($line->credit)));
        expect($debits->toString())->toBe($credits->toString());

        $expenseDebits = Money::sum(
            $voucher->lines
                ->whereIn('account_id', $accounts->pluck('id')->all())
                ->map(fn ($line): Money => Money::of($line->debit))
        );
        expect($expenseDebits->toString())->toBe('900.01')
            ->and($purchase->discountAmount()->toString())->toBe('100.00');
    });

    $tenant->delete();
});

test('a Box purchase records value excluding VAT and a unit cost per base unit', function () {
    $tenant = provisionPurchaseCostingTenant('purchase-costing-box.tenant-test');

    $tenant->run(function () {
        purchaseCostingOpenFiscalYear();
        $actor = purchaseCostingActor();
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => true]);
        $box = ItemUnit::factory()->create(['item_id' => $item->id, 'name' => 'Box', 'conversion_factor' => 12]);

        // 2 Box at 1,200 with 10% off the line: net 2,160.00 over 24 pieces.
        $purchase = Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'credit'],
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

        $line = $purchase->lines()->firstOrFail();
        expect($line->line_total)->toBe('2160.00')
            ->and($line->net_value)->toBe('2160.00')
            ->and($line->unit_conversion_factor)->toBe('12.0000');

        $movement = ItemStockMovement::where('reference_type', (new PurchaseLine)->getMorphClass())
            ->where('reference_id', $line->id)
            ->firstOrFail();

        expect($movement->quantity)->toBe('24.0000')
            // value is the net line value, VAT excluded - 2,160.00, never 2,440.80.
            ->and($movement->value)->toBe('2160.00')
            // Net cost per BASE unit: 2,160.00 / 24 = 90.0000, not the 1,200
            // entered-unit rate the audit found stored here (P0-17).
            ->and($movement->unit_cost_rate)->toBe('90.0000');
    });

    $tenant->delete();
});

test('the same supplier bill number cannot be entered twice, but is free again once the first is cancelled', function () {
    $tenant = provisionPurchaseCostingTenant('purchase-costing-duplicate-bill.tenant-test');

    $tenant->run(function () {
        purchaseCostingOpenFiscalYear();
        $actor = purchaseCostingActor();
        $supplier = Supplier::factory()->create();
        $otherSupplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        $payload = fn (int $supplierId): array => [
            'supplier_id' => $supplierId,
            'date' => '2026-06-01',
            'payment_mode' => 'credit',
            'bill_number' => 'INV-778',
        ];
        $lines = [['item_id' => $item->id, 'quantity' => '1', 'rate' => '100']];

        $first = Purchase::post($payload($supplier->id), $lines, $actor);

        expect(fn () => Purchase::post($payload($supplier->id), $lines, $actor))
            ->toThrow(InvalidArgumentException::class);

        // A different supplier may well use the same bill number.
        expect(fn () => Purchase::post($payload($otherSupplier->id), $lines, $actor))
            ->not->toThrow(InvalidArgumentException::class);

        $first->cancel($actor, 'Entered against the wrong supplier');
        expect($first->fresh()->bill_number_key)->toBeNull();

        $replacement = Purchase::post($payload($supplier->id), $lines, $actor);
        expect($replacement->bill_number_key)->toBe('INV-778');
    });

    $tenant->delete();
});

test('outstanding nets TDS, settlement, posted returns and a supplier refund exactly', function () {
    $tenant = provisionPurchaseCostingTenant('purchase-costing-outstanding.tenant-test');

    $tenant->run(function () {
        purchaseCostingOpenFiscalYear();
        $actor = purchaseCostingActor();
        $supplier = Supplier::factory()->create();
        $tdsAccount = Account::factory()->create();
        $cash = Account::where('code', 'AS1')->firstOrFail();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => true]);

        $purchase = Purchase::post(
            [
                'supplier_id' => $supplier->id,
                'date' => '2026-06-01',
                'payment_mode' => 'credit',
                'tds_account_id' => $tdsAccount->id,
                'tds_amount' => '100',
            ],
            [['item_id' => $item->id, 'quantity' => '10', 'rate' => '100']],
            $actor,
        );

        // 1,000.00 billed, 100.00 withheld for the IRD: 900.00 owed.
        expect($purchase->outstandingAmount()->toString())->toBe('900.00');

        $line = $purchase->lines()->firstOrFail();

        $return = PurchaseReturn::post(
            ['purchase_id' => $purchase->id, 'date' => '2026-06-05'],
            [['purchase_line_id' => $line->id, 'quantity' => '2']],
            $actor,
        );

        // The return credits 200.00 of goods and hands 20.00 of TDS back, so
        // only 180.00 comes off what the supplier is owed.
        expect($return->total)->toBe('200.00')
            ->and($return->tds_amount)->toBe('20.00')
            ->and($purchase->fresh()->outstandingAmount()->toString())->toBe('720.00');

        // A refunded return puts the money back in our hands, so the bill is
        // owed in full again.
        $refunded = PurchaseReturn::post(
            ['purchase_id' => $purchase->id, 'date' => '2026-06-06', 'refund_account_id' => $cash->id],
            [['purchase_line_id' => $line->id, 'quantity' => '2']],
            $actor,
        );

        expect($refunded->refund_journal_voucher_id)->not->toBeNull()
            ->and($purchase->fresh()->outstandingAmount()->toString())->toBe('720.00');
    });

    $tenant->delete();
});

test('cancelling a purchase whose stock has already left the store is refused', function () {
    $tenant = provisionPurchaseCostingTenant('purchase-costing-cancel-guard.tenant-test');

    $tenant->run(function () {
        purchaseCostingOpenFiscalYear();
        $actor = purchaseCostingActor();
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => true]);

        $purchase = Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => '10', 'rate' => '100']],
            $actor,
        );

        // The goods have been sold on, so only 2 are left in the store.
        $item->recordStockMovement(StockMovementType::Sale, '8', '2026-06-03', $purchase->store_id, $purchase);

        expect(fn () => $purchase->cancel($actor, 'Supplier never delivered'))
            ->toThrow(InvalidArgumentException::class);

        expect($purchase->fresh()->status)->toBe('posted');

        // A tenant that has opted into negative stock is allowed through.
        CompanySetting::current()->update(['allow_negative_stock' => true]);
        $purchase->cancel($actor, 'Supplier never delivered');

        expect($purchase->fresh()->status)->toBe('cancelled')
            ->and($purchase->fresh()->cancel_reason)->toBe('Supplier never delivered')
            ->and($purchase->fresh()->cancelled_by)->toBe($actor->id)
            ->and($purchase->fresh()->cancelled_at)->not->toBeNull();
    });

    $tenant->delete();
});

test('cancelling a purchase posts its reversal in the Reversal series', function () {
    $tenant = provisionPurchaseCostingTenant('purchase-costing-reversal-series.tenant-test');

    $tenant->run(function () {
        purchaseCostingOpenFiscalYear();
        $actor = purchaseCostingActor();
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        $purchase = Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => '1', 'rate' => '100']],
            $actor,
        );

        $purchase->cancel($actor, 'Duplicate of an earlier bill');
        $purchase = $purchase->fresh();

        $reversal = $purchase->reversalJournalVoucher()->firstOrFail();

        // Its own series, so no purchase or debit-note number is ever eaten by a
        // cancellation (audit P0-15).
        expect($reversal->voucher_type)->toBe(VoucherType::Reversal)
            ->and($reversal->reversal_of_id)->toBe($purchase->journal_voucher_id);
    });

    $tenant->delete();
});

test('returning one Box of an item bought in Boxes of twelve takes twelve pieces out of stock', function () {
    $tenant = provisionPurchaseCostingTenant('purchase-costing-box-return.tenant-test');

    $tenant->run(function () {
        purchaseCostingOpenFiscalYear();
        $actor = purchaseCostingActor();
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => true]);
        $box = ItemUnit::factory()->create(['item_id' => $item->id, 'name' => 'Box', 'conversion_factor' => 12]);

        $purchase = Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'item_unit_id' => $box->id, 'quantity' => '2', 'rate' => '600']],
            $actor,
        );

        expect($item->fresh()->currentStock()->toString())->toBe('24.0000');

        $line = $purchase->lines()->firstOrFail();

        $return = PurchaseReturn::post(
            ['purchase_id' => $purchase->id, 'date' => '2026-06-05'],
            [['purchase_line_id' => $line->id, 'quantity' => '1']],
            $actor,
        );

        $movement = ItemStockMovement::where('reference_type', (new PurchaseReturnLine)->getMorphClass())
            ->where('reference_id', $return->lines()->firstOrFail()->id)
            ->firstOrFail();

        // One Box, twelve pieces: the audit found the as-entered 1 being
        // recorded against a base-unit stock ledger (P0-12).
        expect($movement->quantity)->toBe('12.0000')
            ->and($movement->value)->toBe('600.00')
            ->and($movement->unit_cost_rate)->toBe('50.0000')
            ->and($item->fresh()->currentStock()->toString())->toBe('12.0000');
    });

    $tenant->delete();
});

test('three one-third returns credit the line exactly, with no paisa invented or lost', function () {
    $tenant = provisionPurchaseCostingTenant('purchase-costing-thirds.tenant-test');

    $tenant->run(function () {
        purchaseCostingOpenFiscalYear();
        $actor = purchaseCostingActor();
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => true]);

        // 3 at 33.3333... is not representable, so the line is priced to land
        // on exactly 100.00: one third of it is 33.33 and the thirds have to be
        // topped up to 100.00 by the last return (CONTRACTS C6).
        $purchase = Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => '3', 'rate' => '33.3333']],
            $actor,
        );

        $line = $purchase->lines()->firstOrFail();
        expect($line->line_total)->toBe('100.00');

        $credited = [];
        foreach (['2026-06-02', '2026-06-03', '2026-06-04'] as $date) {
            $return = PurchaseReturn::post(
                ['purchase_id' => $purchase->id, 'date' => $date],
                [['purchase_line_id' => $line->id, 'quantity' => '1']],
                $actor,
            );
            $credited[] = $return;
        }

        $netCredited = Money::sum(array_map(fn (PurchaseReturn $r): Money => Money::of($r->taxable_amount), $credited));
        $vatCredited = Money::sum(array_map(fn (PurchaseReturn $r): Money => Money::of($r->vat_amount), $credited));

        expect($netCredited->toString())->toBe('100.00')
            // The completed document reverses its VAT exactly.
            ->and($vatCredited->toString())->toBe($purchase->vat_amount)
            ->and(Money::of($credited[2]->taxable_amount)->toString())->toBe('33.34');

        // Nothing remains returnable.
        expect(fn () => PurchaseReturn::post(
            ['purchase_id' => $purchase->id, 'date' => '2026-06-05'],
            [['purchase_line_id' => $line->id, 'quantity' => '1']],
            $actor,
        ))->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('repeating a purchase line in one return payload cannot exceed the remaining quantity', function () {
    $tenant = provisionPurchaseCostingTenant('purchase-costing-duplicate-return-row.tenant-test');

    $tenant->run(function () {
        purchaseCostingOpenFiscalYear();
        $actor = purchaseCostingActor();
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => true]);

        $purchase = Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => '5', 'rate' => '100']],
            $actor,
        );
        $line = $purchase->lines()->firstOrFail();

        // Two rows of 5 against a line of 5 used to credit 10 (audit P0-14).
        expect(fn () => PurchaseReturn::post(
            ['purchase_id' => $purchase->id, 'date' => '2026-06-02'],
            [
                ['purchase_line_id' => $line->id, 'quantity' => '5'],
                ['purchase_line_id' => $line->id, 'quantity' => '5'],
            ],
            $actor,
        ))->toThrow(InvalidArgumentException::class);

        expect(PurchaseReturn::count())->toBe(0);
    });

    $tenant->delete();
});

test('repeating a purchase in one payment payload cannot over-allocate it', function () {
    $tenant = provisionPurchaseCostingTenant('purchase-costing-duplicate-allocation.tenant-test');

    $tenant->run(function () {
        purchaseCostingOpenFiscalYear();
        $actor = purchaseCostingActor();
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        $purchase = Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => '1', 'rate' => '1000']],
            $actor,
        );

        // Two rows of 1,000 against a 1,000 bill used to pass because each row
        // was checked on its own (audit P0-14).
        expect(fn () => Payment::post([
            'supplier_id' => $supplier->id,
            'date' => '2026-06-02',
            'amount' => '2000',
            'payment_mode' => 'cash',
            'allocations' => [
                ['purchase_id' => $purchase->id, 'amount' => '1000'],
                ['purchase_id' => $purchase->id, 'amount' => '1000'],
            ],
        ], $actor))->toThrow(InvalidArgumentException::class);

        expect(Payment::count())->toBe(0);

        // One paisa over the balance is an error, not a rounding artefact.
        expect(fn () => Payment::post([
            'supplier_id' => $supplier->id,
            'date' => '2026-06-02',
            'amount' => '2000',
            'payment_mode' => 'cash',
            'allocations' => [['purchase_id' => $purchase->id, 'amount' => '1000.01']],
        ], $actor))->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('a purchase return cannot be dated before the bill it returns', function () {
    $tenant = provisionPurchaseCostingTenant('purchase-costing-return-date.tenant-test');

    $tenant->run(function () {
        purchaseCostingOpenFiscalYear();
        $actor = purchaseCostingActor();
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => true]);

        $purchase = Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-10', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => '5', 'rate' => '100']],
            $actor,
        );
        $line = $purchase->lines()->firstOrFail();

        expect(fn () => PurchaseReturn::post(
            ['purchase_id' => $purchase->id, 'date' => '2026-06-01'],
            [['purchase_line_id' => $line->id, 'quantity' => '1']],
            $actor,
        ))->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('a posted purchase return carries a stored debit note number and its fiscal year', function () {
    $tenant = provisionPurchaseCostingTenant('purchase-costing-debit-note-number.tenant-test');

    $tenant->run(function () {
        purchaseCostingOpenFiscalYear();
        $actor = purchaseCostingActor();
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => true]);

        $purchase = Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => '5', 'rate' => '100']],
            $actor,
        );
        $line = $purchase->lines()->firstOrFail();

        $return = PurchaseReturn::post(
            ['purchase_id' => $purchase->id, 'date' => '2026-06-02'],
            [['purchase_line_id' => $line->id, 'quantity' => '1']],
            $actor,
        );

        $voucher = $return->journalVoucher()->firstOrFail();

        expect($return->debit_note_number)->toBe('PR-'.$voucher->voucher_number)
            ->and($return->fiscal_year_id)->toBe($voucher->fiscal_year_id)
            ->and($return->documentNumber())->toBe($return->debit_note_number);
    });

    $tenant->delete();
});

test('a debit note credits the account the original line debited, not the item current account', function () {
    $tenant = provisionPurchaseCostingTenant('purchase-costing-return-account.tenant-test');

    $tenant->run(function () {
        purchaseCostingOpenFiscalYear();
        $actor = purchaseCostingActor();
        $supplier = Supplier::factory()->create();
        $originalAccount = Account::factory()->create();
        $newAccount = Account::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => true, 'account_id' => $originalAccount->id]);

        $purchase = Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => '5', 'rate' => '100']],
            $actor,
        );
        $line = $purchase->lines()->firstOrFail();
        expect($line->account_id)->toBe($originalAccount->id);

        // The item is re-filed under a different expense account after the bill.
        $item->update(['account_id' => $newAccount->id]);

        $return = PurchaseReturn::post(
            ['purchase_id' => $purchase->id, 'date' => '2026-06-02'],
            [['purchase_line_id' => $line->id, 'quantity' => '2']],
            $actor,
        );

        $voucher = $return->journalVoucher()->with('lines')->firstOrFail();

        expect((string) Money::of($voucher->lines->where('account_id', $originalAccount->id)->sum('credit')))->toBe('200.00')
            ->and($voucher->lines->where('account_id', $newAccount->id))->toBeEmpty();
    });

    $tenant->delete();
});

test('posting a purchase whose expected_total disagrees with the server is refused', function () {
    $tenant = provisionPurchaseCostingTenant('purchase-costing-expected-total.tenant-test');

    $tenant->run(function () {
        purchaseCostingOpenFiscalYear();
        $actor = purchaseCostingActor();
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => false]);

        expect(fn () => Purchase::post(
            [
                'supplier_id' => $supplier->id,
                'date' => '2026-06-01',
                'payment_mode' => 'credit',
                // The old float preview showed 130.19 VAT where the server books
                // 130.20, so the bill on screen was not the bill being saved.
                'expected_total' => '1131.69',
            ],
            [['item_id' => $item->id, 'quantity' => '1', 'rate' => '1001.50']],
            $actor,
        ))->toThrow(BillingException::class);

        expect(Purchase::count())->toBe(0);
    });

    $tenant->delete();
});

test('a partial payment split that does not land on the amount due is refused exactly', function () {
    $tenant = provisionPurchaseCostingTenant('purchase-costing-partial-split.tenant-test');

    $tenant->run(function () {
        purchaseCostingOpenFiscalYear();
        $actor = purchaseCostingActor();
        $supplier = Supplier::factory()->create();
        $bank = Account::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        $payload = fn (string $cash, string $bankAmount): array => [
            'supplier_id' => $supplier->id,
            'date' => '2026-06-01',
            'payment_mode' => 'partial',
            'bank_account_id' => $bank->id,
            'cash_amount' => $cash,
            'bank_amount' => $bankAmount,
        ];
        $lines = [['item_id' => $item->id, 'quantity' => '1', 'rate' => '1000']];

        // One paisa short used to be inside a 0.01 tolerance (audit P0-4).
        expect(fn () => Purchase::post($payload('400.00', '599.99'), $lines, $actor))
            ->toThrow(BillingException::class);

        $purchase = Purchase::post($payload('400.00', '600.00'), $lines, $actor);

        expect($purchase->cash_amount)->toBe('400.00')
            ->and($purchase->bank_amount)->toBe('600.00')
            ->and($purchase->settledAtPosting()->toString())->toBe('1000.00')
            ->and($purchase->outstandingAmount()->toString())->toBe('0.00');
    });

    $tenant->delete();
});
