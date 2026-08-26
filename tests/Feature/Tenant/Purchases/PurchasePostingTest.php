<?php

use App\Enums\FiscalYearStatus;
use App\Enums\StockMovementType;
use App\Enums\VoucherType;
use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\ItemStockMovement;
use App\Models\Purchase;
use App\Models\PurchaseLine;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionPurchasePostingTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function purchasePostingTestActor(): User
{
    return User::factory()->create();
}

function purchasePostingOpenFiscalYear(): void
{
    FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
}

test('a cash purchase posts a balanced voucher and records a stock movement', function () {
    $tenant = provisionPurchasePostingTestTenant('purchase-cash-basic.tenant-test');

    $tenant->run(function () {
        purchasePostingOpenFiscalYear();
        $actor = purchasePostingTestActor();
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => true]);

        $purchase = Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 10, 'rate' => 100]],
            $actor,
        );

        expect($purchase->taxable_amount)->toEqual('1000.00')
            ->and($purchase->vat_amount)->toEqual('130.00')
            ->and($purchase->total)->toEqual('1130.00');

        $voucher = $purchase->journalVoucher()->with('lines')->first();
        $totalDebit = $voucher->lines->sum(fn ($l) => (float) $l->debit);
        $totalCredit = $voucher->lines->sum(fn ($l) => (float) $l->credit);
        expect(round($totalDebit, 2))->toBe(round($totalCredit, 2))
            ->and($voucher->voucher_type)->toBe(VoucherType::Purchase);

        $line = PurchaseLine::query()->where('purchase_id', $purchase->id)->firstOrFail();
        expect((float) $line->line_total)->toBe(1000.0)
            ->and($line->vatable)->toBeTrue();

        $movement = ItemStockMovement::query()->where('item_id', $item->id)->firstOrFail();
        expect($movement->movement_type)->toBe(StockMovementType::Purchase)
            ->and((float) $movement->quantity)->toBe(10.0)
            ->and($movement->cancelled)->toBeFalse()
            ->and($movement->reference_id)->toBe($line->id)
            ->and($movement->reference_type)->toBe(PurchaseLine::class);

        expect($item->fresh()->currentStock())->toBe(10.0);
    });

    $tenant->delete();
});

test('a credit purchase skips the settlement leg and leaves the supplier balance equal to the total', function () {
    $tenant = provisionPurchasePostingTestTenant('purchase-credit.tenant-test');

    $tenant->run(function () {
        purchasePostingOpenFiscalYear();
        $actor = purchasePostingTestActor();
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false]);

        $purchase = Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 500]],
            $actor,
        );

        $cashAccount = Account::where('code', 'AS1')->firstOrFail();
        $cashLines = $purchase->journalVoucher->lines()->where('account_id', $cashAccount->id)->count();
        expect($cashLines)->toBe(0);

        $net = $supplier->account->journalVoucherLines()->selectRaw('COALESCE(SUM(credit),0) - COALESCE(SUM(debit),0) as net')->value('net');
        expect((float) $net)->toBe((float) $purchase->total);
    });

    $tenant->delete();
});

test('VAT is only charged on vatable lines', function () {
    $tenant = provisionPurchasePostingTestTenant('purchase-non-vatable.tenant-test');

    $tenant->run(function () {
        purchasePostingOpenFiscalYear();
        $actor = purchasePostingTestActor();
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false]);

        $purchase = Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 500]],
            $actor,
        );

        expect((float) $purchase->vat_amount)->toBe(0.0)
            ->and((float) $purchase->nontaxable_amount)->toBe(500.0)
            ->and((float) $purchase->taxable_amount)->toBe(0.0);
    });

    $tenant->delete();
});

test('an item with a non-null account_id debits that account instead of the purchases account', function () {
    $tenant = provisionPurchasePostingTestTenant('purchase-capital-item.tenant-test');

    $tenant->run(function () {
        purchasePostingOpenFiscalYear();
        $actor = purchasePostingTestActor();
        $supplier = Supplier::factory()->create();

        $fixedAssetGroup = AccountGroup::where('name', 'Fixed Assets')->firstOrFail();
        $fixedAssetAccount = Account::create(['account_group_id' => $fixedAssetGroup->id, 'name' => 'Furniture']);

        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false, 'account_id' => $fixedAssetAccount->id]);

        $purchase = Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 5000]],
            $actor,
        );

        $line = $purchase->journalVoucher->lines()->where('account_id', $fixedAssetAccount->id)->first();
        expect($line)->not->toBeNull()
            ->and((float) $line->debit)->toBe(5000.0);

        $exe8 = Account::where('code', 'EXE8')->firstOrFail();
        expect($purchase->journalVoucher->lines()->where('account_id', $exe8->id)->exists())->toBeFalse();

        expect(ItemStockMovement::query()->where('item_id', $item->id)->exists())->toBeFalse();
    });

    $tenant->delete();
});

test('an item with a null account_id falls back to the seeded Purchases Account', function () {
    $tenant = provisionPurchasePostingTestTenant('purchase-fallback-account.tenant-test');

    $tenant->run(function () {
        purchasePostingOpenFiscalYear();
        $actor = purchasePostingTestActor();
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'account_id' => null]);

        $purchase = Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 200]],
            $actor,
        );

        $exe8 = Account::where('code', 'EXE8')->firstOrFail();
        $line = $purchase->journalVoucher->lines()->where('account_id', $exe8->id)->first();
        expect($line)->not->toBeNull()->and((float) $line->debit)->toBe(200.0);
    });

    $tenant->delete();
});

test('a partial payment with mismatched cash and bank amounts is rejected', function () {
    $tenant = provisionPurchasePostingTestTenant('purchase-partial-mismatch.tenant-test');

    $tenant->run(function () {
        purchasePostingOpenFiscalYear();
        $actor = purchasePostingTestActor();
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false]);
        $bankAccount = Account::factory()->create();

        expect(fn () => Purchase::post(
            [
                'supplier_id' => $supplier->id,
                'date' => '2026-06-01',
                'payment_mode' => 'partial',
                'bank_account_id' => $bankAccount->id,
                'cash_amount' => 100,
                'bank_amount' => 50,
            ],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 500]],
            $actor,
        ))->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('a TDS leg is only posted when a TDS amount is withheld', function () {
    $tenant = provisionPurchasePostingTestTenant('purchase-tds.tenant-test');

    $tenant->run(function () {
        purchasePostingOpenFiscalYear();
        $actor = purchasePostingTestActor();
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false]);
        $tdsAccount = Account::factory()->create();

        $withoutTds = Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 1000]],
            $actor,
        );
        expect($withoutTds->journalVoucher->lines()->where('account_id', $tdsAccount->id)->exists())->toBeFalse();

        $withTds = Purchase::post(
            [
                'supplier_id' => $supplier->id,
                'date' => '2026-06-02',
                'payment_mode' => 'credit',
                'tds_account_id' => $tdsAccount->id,
                'tds_amount' => 150,
            ],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 1000]],
            $actor,
        );

        $tdsLine = $withTds->journalVoucher->lines()->where('account_id', $tdsAccount->id)->first();
        expect($tdsLine)->not->toBeNull()->and((float) $tdsLine->credit)->toBe(150.0);
    });

    $tenant->delete();
});

test('posting with a TDS amount but no TDS account is rejected', function () {
    $tenant = provisionPurchasePostingTestTenant('purchase-tds-missing-account.tenant-test');

    $tenant->run(function () {
        purchasePostingOpenFiscalYear();
        $actor = purchasePostingTestActor();
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false]);

        expect(fn () => Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'credit', 'tds_amount' => 100],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 1000]],
            $actor,
        ))->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('a header discount keeps the voucher balanced', function () {
    $tenant = provisionPurchasePostingTestTenant('purchase-header-discount.tenant-test');

    $tenant->run(function () {
        purchasePostingOpenFiscalYear();
        $actor = purchasePostingTestActor();
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true]);

        $purchase = Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'cash', 'discount' => 100],
            [['item_id' => $item->id, 'quantity' => 10, 'rate' => 100]],
            $actor,
        );

        expect((float) $purchase->taxable_amount)->toBe(900.0);

        $voucher = $purchase->journalVoucher()->with('lines')->first();
        $totalDebit = round((float) $voucher->lines->sum(fn ($l) => (float) $l->debit), 2);
        $totalCredit = round((float) $voucher->lines->sum(fn ($l) => (float) $l->credit), 2);
        expect($totalDebit)->toBe($totalCredit);
    });

    $tenant->delete();
});

test('cancelling a purchase posts a mirrored reversal and flags stock movements cancelled', function () {
    $tenant = provisionPurchasePostingTestTenant('purchase-cancel.tenant-test');

    $tenant->run(function () {
        purchasePostingOpenFiscalYear();
        $actor = purchasePostingTestActor();
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => true]);

        $purchase = Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 5, 'rate' => 100]],
            $actor,
        );

        $originalLines = $purchase->journalVoucher->lines()->get()->map(fn ($l) => [$l->account_id, (float) $l->debit, (float) $l->credit])->all();

        $purchase->cancel($actor, 'Wrong quantity entered');

        expect($purchase->fresh()->status)->toBe('cancelled');

        $reversal = \App\Models\JournalVoucher::where('voucher_type', VoucherType::PurchaseReturn)->firstOrFail();
        $reversedLines = $reversal->lines()->get()->map(fn ($l) => [$l->account_id, (float) $l->credit, (float) $l->debit])->all();

        sort($originalLines);
        sort($reversedLines);
        expect($reversedLines)->toEqual($originalLines);

        expect($item->fresh()->currentStock())->toBe(0.0);
        expect(ItemStockMovement::query()->where('item_id', $item->id)->first()->cancelled)->toBeTrue();

        expect(fn () => $purchase->cancel($actor, 'Again'))->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});
