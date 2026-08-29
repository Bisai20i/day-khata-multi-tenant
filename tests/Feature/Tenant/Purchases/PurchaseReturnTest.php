<?php

use App\Enums\FiscalYearStatus;
use App\Enums\StockMovementType;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\JournalVoucher;
use App\Models\JournalVoucherLine;
use App\Models\Purchase;
use App\Models\PurchaseLine;
use App\Models\PurchaseReturn;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionPurchaseReturnTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function purchaseReturnTestActor(): User
{
    return User::factory()->create();
}

function purchaseReturnOpenFiscalYear(): void
{
    FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
}

function loginPurchaseReturnTestUser(string $domain): void
{
    test()->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);
}

test('a partial return credits the correct item account, credits VAT receivable not payable, and balances', function () {
    $tenant = provisionPurchaseReturnTestTenant('purchase-return-basic.tenant-test');

    $tenant->run(function () {
        purchaseReturnOpenFiscalYear();
        $actor = purchaseReturnTestActor();
        $supplier = Supplier::factory()->create();

        $stockAccount = Account::factory()->create();
        $serviceAccount = Account::factory()->create();
        $stockItem = Item::factory()->create(['is_vatable' => true, 'is_stockable' => true, 'account_id' => $stockAccount->id]);
        $serviceItem = Item::factory()->create(['is_vatable' => true, 'is_stockable' => false, 'account_id' => $serviceAccount->id]);

        $purchase = Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [
                ['item_id' => $stockItem->id, 'quantity' => 10, 'rate' => 100],
                ['item_id' => $serviceItem->id, 'quantity' => 1, 'rate' => 500],
            ],
            $actor,
        );

        $stockLine = PurchaseLine::where('purchase_id', $purchase->id)->where('item_id', $stockItem->id)->firstOrFail();

        $return = PurchaseReturn::post(
            ['purchase_id' => $purchase->id, 'date' => '2026-06-05', 'reason' => 'Damaged goods'],
            [['purchase_line_id' => $stockLine->id, 'quantity' => 4]],
            $actor,
        );

        expect((float) $return->taxable_amount)->toBe(400.0)
            ->and((float) $return->vat_amount)->toBe(52.0)
            ->and((float) $return->total)->toBe(452.0);

        $voucher = $return->journalVoucher()->with('lines')->first();
        $totalDebit = $voucher->lines->sum(fn ($l) => (float) $l->debit);
        $totalCredit = $voucher->lines->sum(fn ($l) => (float) $l->credit);
        expect(round($totalDebit, 2))->toBe(round($totalCredit, 2));

        $stockAccountCredit = $voucher->lines->where('account_id', $stockAccount->id)->sum('credit');
        $serviceAccountCredit = $voucher->lines->where('account_id', $serviceAccount->id)->sum('credit');
        expect((float) $stockAccountCredit)->toBe(400.0)
            ->and((float) $serviceAccountCredit)->toBe(0.0);

        $asa23 = Account::where('code', 'ASA23')->firstOrFail();
        $lia20 = Account::where('code', 'LIA20')->firstOrFail();
        expect((float) $voucher->lines->where('account_id', $asa23->id)->sum('credit'))->toBe(52.0)
            ->and((float) $voucher->lines->where('account_id', $lia20->id)->sum('credit'))->toBe(0.0)
            ->and((float) $voucher->lines->where('account_id', $lia20->id)->sum('debit'))->toBe(0.0);

        $supplierDebit = $voucher->lines->where('account_id', $supplier->account_id)->sum('debit');
        expect((float) $supplierDebit)->toBe(452.0);

        expect($stockItem->fresh()->currentStock())->toBe(6.0);

        $movement = $stockItem->stockMovements()->where('movement_type', StockMovementType::PurchaseReturn)->firstOrFail();
        expect((float) $movement->quantity)->toBe(4.0);
    });

    $tenant->delete();
});

test('returning more than the remaining returnable quantity throws', function () {
    $tenant = provisionPurchaseReturnTestTenant('purchase-return-overreturn.tenant-test');

    $tenant->run(function () {
        purchaseReturnOpenFiscalYear();
        $actor = purchaseReturnTestActor();
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => true]);

        $purchase = Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 5, 'rate' => 100]],
            $actor,
        );

        $line = PurchaseLine::where('purchase_id', $purchase->id)->firstOrFail();

        PurchaseReturn::post(
            ['purchase_id' => $purchase->id, 'date' => '2026-06-05'],
            [['purchase_line_id' => $line->id, 'quantity' => 3]],
            $actor,
        );

        expect(fn () => PurchaseReturn::post(
            ['purchase_id' => $purchase->id, 'date' => '2026-06-06'],
            [['purchase_line_id' => $line->id, 'quantity' => 3]],
            $actor,
        ))->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('cannot return items against a cancelled purchase', function () {
    $tenant = provisionPurchaseReturnTestTenant('purchase-return-cancelled.tenant-test');

    $tenant->run(function () {
        purchaseReturnOpenFiscalYear();
        $actor = purchaseReturnTestActor();
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => true]);

        $purchase = Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 5, 'rate' => 100]],
            $actor,
        );

        $line = PurchaseLine::where('purchase_id', $purchase->id)->firstOrFail();
        $purchase->cancel($actor, 'wrong entry');

        expect(fn () => PurchaseReturn::post(
            ['purchase_id' => $purchase->id, 'date' => '2026-06-05'],
            [['purchase_line_id' => $line->id, 'quantity' => 1]],
            $actor,
        ))->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('cannot cancel a purchase that already has a partial return against it', function () {
    $tenant = provisionPurchaseReturnTestTenant('purchase-return-blocks-cancel.tenant-test');

    $tenant->run(function () {
        purchaseReturnOpenFiscalYear();
        $actor = purchaseReturnTestActor();
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => true]);

        $purchase = Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 5, 'rate' => 100]],
            $actor,
        );

        $line = PurchaseLine::where('purchase_id', $purchase->id)->firstOrFail();

        PurchaseReturn::post(
            ['purchase_id' => $purchase->id, 'date' => '2026-06-05'],
            [['purchase_line_id' => $line->id, 'quantity' => 1]],
            $actor,
        );

        expect(fn () => $purchase->cancel($actor, 'trying to void after a return'))
            ->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('the purchase returns index page renders and an authenticated user can post a return via HTTP', function () {
    $domain = 'purchase-returns-http.tenant-test';
    $tenant = provisionPurchaseReturnTestTenant($domain);

    $purchaseId = null;
    $lineId = null;
    $tenant->run(function () use (&$purchaseId, &$lineId) {
        User::factory()->create(['email' => 'owner@example.com']);
        purchaseReturnOpenFiscalYear();
        $actor = User::first();
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => true]);

        $purchase = Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 5, 'rate' => 100]],
            $actor,
        );

        $purchaseId = $purchase->id;
        $lineId = PurchaseLine::where('purchase_id', $purchase->id)->firstOrFail()->id;
    });

    loginPurchaseReturnTestUser($domain);

    $this->get("http://{$domain}/purchase-returns")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Tenant/Purchases/Returns/Index'));

    $this->post("http://{$domain}/purchase-returns", [
        'purchase_id' => $purchaseId,
        'date' => '2026-06-05',
        'lines' => [['purchase_line_id' => $lineId, 'quantity' => 2]],
    ])->assertRedirect();

    $tenant->run(function () use ($purchaseId) {
        expect(PurchaseReturn::where('purchase_id', $purchaseId)->count())->toBe(1);
    });

    $tenant->delete();
});

test('cancelling a return posts a reversing voucher, frees up the returned quantity, and re-enables cancelling the purchase', function () {
    $tenant = provisionPurchaseReturnTestTenant('purchase-return-cancel.tenant-test');

    $tenant->run(function () {
        purchaseReturnOpenFiscalYear();
        $actor = purchaseReturnTestActor();
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => true]);

        $purchase = Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 5, 'rate' => 100]],
            $actor,
        );

        $line = PurchaseLine::where('purchase_id', $purchase->id)->firstOrFail();

        $return = PurchaseReturn::post(
            ['purchase_id' => $purchase->id, 'date' => '2026-06-05', 'reason' => 'Damaged'],
            [['purchase_line_id' => $line->id, 'quantity' => 5]],
            $actor,
        );

        expect($item->fresh()->currentStock())->toBe(0.0);

        $return->cancel($actor, 'entered by mistake');

        expect($return->fresh()->status)->toBe('cancelled')
            ->and($item->fresh()->currentStock())->toBe(5.0);

        // journalVoucher() still points at the ORIGINAL return voucher -
        // find the reversal by its distinctive narration instead.
        $reversalVoucher = JournalVoucher::where('narration', "Cancellation of purchase return #{$return->id}: entered by mistake")->firstOrFail();
        $totalDebit = round((float) $reversalVoucher->lines->sum('debit'), 2);
        $totalCredit = round((float) $reversalVoucher->lines->sum('credit'), 2);
        expect($totalDebit)->toBe($totalCredit)->and($totalDebit)->toBeGreaterThan(0);

        // The full original quantity is returnable again since the only
        // prior return against it is now cancelled.
        $secondReturn = PurchaseReturn::post(
            ['purchase_id' => $purchase->id, 'date' => '2026-06-06'],
            [['purchase_line_id' => $line->id, 'quantity' => 5]],
            $actor,
        );
        expect($secondReturn)->not->toBeNull();

        expect(fn () => $return->cancel($actor, 'again'))->toThrow(InvalidArgumentException::class);

        $secondReturn->cancel($actor, 'undo second return too');
        expect(fn () => $purchase->cancel($actor, 'void the whole thing'))->not->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('a return proportionally reverses header discount and TDS and stays balanced', function () {
    $tenant = provisionPurchaseReturnTestTenant('purchase-return-discount-tds.tenant-test');

    $tenant->run(function () {
        purchaseReturnOpenFiscalYear();
        $actor = purchaseReturnTestActor();
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => true]);
        $tdsAccount = Account::factory()->create();

        $purchase = Purchase::post(
            [
                'supplier_id' => $supplier->id,
                'date' => '2026-06-01',
                'payment_mode' => 'credit',
                'discount' => 100,
                'tds_account_id' => $tdsAccount->id,
                'tds_amount' => 50,
            ],
            [['item_id' => $item->id, 'quantity' => 10, 'rate' => 100]],
            $actor,
        );

        expect((float) $purchase->taxable_amount)->toBe(900.0)
            ->and((float) $purchase->vat_amount)->toBe(117.0)
            ->and((float) $purchase->total)->toBe(1017.0);

        $line = PurchaseLine::where('purchase_id', $purchase->id)->firstOrFail();

        $return = PurchaseReturn::post(
            ['purchase_id' => $purchase->id, 'date' => '2026-06-05', 'reason' => 'Partial damage'],
            [['purchase_line_id' => $line->id, 'quantity' => 4]],
            $actor,
        );

        // Line total returned = 400 (4 of 10 units @ 100). Discount ratio =
        // 100 / (900 + 100) = 0.1, so 40 of discount is backed out, leaving
        // an effective taxable return of 360. VAT at 13% = 46.8. Total =
        // 406.8. TDS share = 50 * (406.8 / 1017) = 20.
        expect((float) $return->taxable_amount)->toBe(360.0)
            ->and((float) $return->vat_amount)->toBe(46.8)
            ->and((float) $return->total)->toBe(406.8);

        $voucher = $return->journalVoucher()->with('lines')->first();
        $totalDebit = round((float) $voucher->lines->sum('debit'), 2);
        $totalCredit = round((float) $voucher->lines->sum('credit'), 2);
        expect($totalDebit)->toBe($totalCredit)->and($totalDebit)->toBe(406.8);

        $exe8 = Account::where('code', 'EXE8')->firstOrFail();
        $itemAccountCredit = (float) $voucher->lines->where('account_id', $exe8->id)->sum('credit');
        $asa23 = Account::where('code', 'ASA23')->firstOrFail();
        $vatCredit = (float) $voucher->lines->where('account_id', $asa23->id)->sum('credit');
        $tdsDebit = (float) $voucher->lines->where('account_id', $tdsAccount->id)->sum('debit');
        $supplierDebit = (float) $voucher->lines->where('account_id', $supplier->account_id)->sum('debit');

        expect($itemAccountCredit)->toBe(360.0)
            ->and($vatCredit)->toBe(46.8)
            ->and($tdsDebit)->toBe(20.0)
            ->and($supplierDebit)->toBe(386.8);
    });

    $tenant->delete();
});

test('a return with a refund account posts a second settlement voucher that nets the supplier back to zero for that amount, and cancelling both reverses cleanly', function () {
    $tenant = provisionPurchaseReturnTestTenant('purchase-return-refund.tenant-test');

    $tenant->run(function () {
        purchaseReturnOpenFiscalYear();
        $actor = purchaseReturnTestActor();
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => true]);
        $bankAccount = Account::factory()->create();

        $purchase = Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 10, 'rate' => 100]],
            $actor,
        );

        $balanceAfterPurchase = supplierNetBalance($supplier->account_id);

        $line = PurchaseLine::where('purchase_id', $purchase->id)->firstOrFail();

        $return = PurchaseReturn::post(
            ['purchase_id' => $purchase->id, 'date' => '2026-06-05', 'refund_account_id' => $bankAccount->id],
            [['purchase_line_id' => $line->id, 'quantity' => 4]],
            $actor,
        );

        expect((float) $return->total)->toBe(452.0)
            ->and($return->refund_journal_voucher_id)->not->toBeNull();

        $refundVoucher = $return->refundJournalVoucher()->with('lines')->first();
        $refundDebit = round((float) $refundVoucher->lines->sum('debit'), 2);
        $refundCredit = round((float) $refundVoucher->lines->sum('credit'), 2);
        expect($refundDebit)->toBe($refundCredit)->and($refundDebit)->toBe(452.0);

        $bankDebit = (float) $refundVoucher->lines->where('account_id', $bankAccount->id)->sum('debit');
        $supplierCreditOnRefund = (float) $refundVoucher->lines->where('account_id', $supplier->account_id)->sum('credit');
        expect($bankDebit)->toBe(452.0)->and($supplierCreditOnRefund)->toBe(452.0);

        // The return debited the supplier 452 and the refund voucher
        // credited it right back 452 - net zero change to the supplier's
        // own balance (all of it moved to/through the bank account instead).
        expect(supplierNetBalance($supplier->account_id))->toBe($balanceAfterPurchase);

        $return->cancel($actor, 'refund reversal check');

        expect($return->fresh()->status)->toBe('cancelled')
            ->and(supplierNetBalance($supplier->account_id))->toBe($balanceAfterPurchase);
    });

    $tenant->delete();
});

test('a return without a refund account does not create a settlement voucher', function () {
    $tenant = provisionPurchaseReturnTestTenant('purchase-return-no-refund.tenant-test');

    $tenant->run(function () {
        purchaseReturnOpenFiscalYear();
        $actor = purchaseReturnTestActor();
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => true]);

        $purchase = Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 5, 'rate' => 100]],
            $actor,
        );

        $line = PurchaseLine::where('purchase_id', $purchase->id)->firstOrFail();

        $return = PurchaseReturn::post(
            ['purchase_id' => $purchase->id, 'date' => '2026-06-05'],
            [['purchase_line_id' => $line->id, 'quantity' => 2]],
            $actor,
        );

        expect($return->refund_journal_voucher_id)->toBeNull();
    });

    $tenant->delete();
});

function supplierNetBalance(int $accountId): float
{
    return round(
        (float) JournalVoucherLine::where('account_id', $accountId)->sum('debit')
            - (float) JournalVoucherLine::where('account_id', $accountId)->sum('credit'),
        2
    );
}
