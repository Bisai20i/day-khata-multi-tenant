<?php

use App\Enums\FiscalYearStatus;
use App\Enums\StockMovementType;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\Item;
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
