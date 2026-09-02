<?php

use App\Enums\FiscalYearStatus;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\JournalVoucher;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionPaymentTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function paymentTestActor(): User
{
    return User::factory()->create();
}

function paymentOpenFiscalYear(): void
{
    FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
}

function loginPaymentTestUser(string $domain): void
{
    test()->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);
}

function creditPurchase(Supplier $supplier, User $actor, float $rate = 100, float $qty = 5): Purchase
{
    $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => true]);

    return Purchase::post(
        ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'credit'],
        [['item_id' => $item->id, 'quantity' => $qty, 'rate' => $rate]],
        $actor,
    );
}

test('a cash payment with no allocations posts a balanced voucher debiting the supplier and crediting cash', function () {
    $tenant = provisionPaymentTestTenant('payment-cash-noalloc.tenant-test');

    $tenant->run(function () {
        paymentOpenFiscalYear();
        $actor = paymentTestActor();
        $supplier = Supplier::factory()->create();

        $payment = Payment::post([
            'supplier_id' => $supplier->id,
            'date' => '2026-06-10',
            'amount' => 200,
            'payment_mode' => 'cash',
        ], $actor);

        expect($payment->status)->toBe('posted')
            ->and((float) $payment->amount)->toBe(200.0)
            ->and($payment->allocations)->toHaveCount(0);

        $voucher = $payment->journalVoucher()->with('lines')->firstOrFail();
        $cash = Account::where('code', 'AS1')->firstOrFail();
        $lines = $voucher->lines->keyBy('account_id');

        expect((float) $lines[$supplier->account_id]->debit)->toBe(200.0)
            ->and((float) $lines[$cash->id]->credit)->toBe(200.0);
    });

    $tenant->delete();
});

test('a payment allocated fully against a credit purchase zeroes out its outstanding amount', function () {
    $tenant = provisionPaymentTestTenant('payment-full-alloc.tenant-test');

    $tenant->run(function () {
        paymentOpenFiscalYear();
        $actor = paymentTestActor();
        $supplier = Supplier::factory()->create();
        $purchase = creditPurchase($supplier, $actor, 100, 5); // total 500

        expect($purchase->outstandingAmount())->toBe(500.0);

        Payment::post([
            'supplier_id' => $supplier->id,
            'date' => '2026-06-10',
            'amount' => 500,
            'payment_mode' => 'cash',
            'allocations' => [['purchase_id' => $purchase->id, 'amount' => 500]],
        ], $actor);

        expect($purchase->fresh()->outstandingAmount())->toBe(0.0);
    });

    $tenant->delete();
});

test('a payment allocated partially against a purchase reduces its outstanding amount by exactly the allocated amount', function () {
    $tenant = provisionPaymentTestTenant('payment-partial-alloc.tenant-test');

    $tenant->run(function () {
        paymentOpenFiscalYear();
        $actor = paymentTestActor();
        $supplier = Supplier::factory()->create();
        $purchase = creditPurchase($supplier, $actor, 100, 5); // total 500

        Payment::post([
            'supplier_id' => $supplier->id,
            'date' => '2026-06-10',
            'amount' => 200,
            'payment_mode' => 'cash',
            'allocations' => [['purchase_id' => $purchase->id, 'amount' => 200]],
        ], $actor);

        expect($purchase->fresh()->outstandingAmount())->toBe(300.0);
    });

    $tenant->delete();
});

test('over-allocating beyond a purchase outstanding balance is rejected over HTTP', function () {
    $domain = 'payment-overalloc.tenant-test';
    $tenant = provisionPaymentTestTenant($domain);

    $supplierId = $purchaseId = null;
    $tenant->run(function () use (&$supplierId, &$purchaseId) {
        User::factory()->create(['email' => 'owner@example.com']);
        paymentOpenFiscalYear();
        $supplier = Supplier::factory()->create();
        $purchase = creditPurchase($supplier, User::first(), 100, 5); // total 500
        $supplierId = $supplier->id;
        $purchaseId = $purchase->id;
    });

    loginPaymentTestUser($domain);

    $this->post("http://{$domain}/payments", [
        'supplier_id' => $supplierId,
        'date' => '2026-06-10',
        'amount' => 1000,
        'payment_mode' => 'cash',
        'allocations' => [['purchase_id' => $purchaseId, 'amount' => 900]],
    ])->assertSessionHasErrors('amount');

    $tenant->run(function () {
        expect(Payment::count())->toBe(0);
    });

    $tenant->delete();
});

test('allocating against another suppliers purchase is rejected', function () {
    $tenant = provisionPaymentTestTenant('payment-wrong-supplier.tenant-test');

    $tenant->run(function () {
        paymentOpenFiscalYear();
        $actor = paymentTestActor();
        $supplierA = Supplier::factory()->create();
        $supplierB = Supplier::factory()->create();
        $purchaseForA = creditPurchase($supplierA, $actor, 100, 5);

        expect(fn () => Payment::post([
            'supplier_id' => $supplierB->id,
            'date' => '2026-06-10',
            'amount' => 100,
            'payment_mode' => 'cash',
            'allocations' => [['purchase_id' => $purchaseForA->id, 'amount' => 100]],
        ], $actor))->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('allocations summing beyond the payment amount are rejected', function () {
    $tenant = provisionPaymentTestTenant('payment-overallocsum.tenant-test');

    $tenant->run(function () {
        paymentOpenFiscalYear();
        $actor = paymentTestActor();
        $supplier = Supplier::factory()->create();
        $purchaseOne = creditPurchase($supplier, $actor, 100, 5); // total 500
        $purchaseTwo = creditPurchase($supplier, $actor, 100, 5); // total 500

        expect(fn () => Payment::post([
            'supplier_id' => $supplier->id,
            'date' => '2026-06-10',
            'amount' => 100,
            'payment_mode' => 'cash',
            'allocations' => [
                ['purchase_id' => $purchaseOne->id, 'amount' => 60],
                ['purchase_id' => $purchaseTwo->id, 'amount' => 60],
            ],
        ], $actor))->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('cancelling a payment reverses the voucher and restores outstanding amount', function () {
    $tenant = provisionPaymentTestTenant('payment-cancel.tenant-test');

    $tenant->run(function () {
        paymentOpenFiscalYear();
        $actor = paymentTestActor();
        $supplier = Supplier::factory()->create();
        $purchase = creditPurchase($supplier, $actor, 100, 5); // total 500

        $payment = Payment::post([
            'supplier_id' => $supplier->id,
            'date' => '2026-06-10',
            'amount' => 500,
            'payment_mode' => 'cash',
            'allocations' => [['purchase_id' => $purchase->id, 'amount' => 500]],
        ], $actor);

        expect($purchase->fresh()->outstandingAmount())->toBe(0.0);

        $payment->cancel($actor, 'Supplier disputed the payment');

        expect($payment->fresh()->status)->toBe('cancelled')
            ->and($purchase->fresh()->outstandingAmount())->toBe(500.0);

        $reversalVoucher = JournalVoucher::where('narration', "Cancellation of payment #{$payment->id}: Supplier disputed the payment")->firstOrFail();
        $totalDebit = round((float) $reversalVoucher->lines->sum('debit'), 2);
        $totalCredit = round((float) $reversalVoucher->lines->sum('credit'), 2);
        expect($totalDebit)->toBe($totalCredit)->and($totalDebit)->toBe(500.0);

        expect(fn () => $payment->cancel($actor, 'Second attempt'))->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('cancelling a purchase that has a live payment allocated against it is rejected', function () {
    $tenant = provisionPaymentTestTenant('payment-blocks-purchase-cancel.tenant-test');

    $tenant->run(function () {
        paymentOpenFiscalYear();
        $actor = paymentTestActor();
        $supplier = Supplier::factory()->create();
        $purchase = creditPurchase($supplier, $actor, 100, 5);

        Payment::post([
            'supplier_id' => $supplier->id,
            'date' => '2026-06-10',
            'amount' => 500,
            'payment_mode' => 'cash',
            'allocations' => [['purchase_id' => $purchase->id, 'amount' => 500]],
        ], $actor);

        expect(fn () => $purchase->cancel($actor, 'trying to void after payment'))
            ->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('a bank payment without a bank account is rejected', function () {
    $tenant = provisionPaymentTestTenant('payment-bank-missing-account.tenant-test');

    $tenant->run(function () {
        paymentOpenFiscalYear();
        $actor = paymentTestActor();
        $supplier = Supplier::factory()->create();

        expect(fn () => Payment::post([
            'supplier_id' => $supplier->id,
            'date' => '2026-06-10',
            'amount' => 100,
            'payment_mode' => 'bank',
        ], $actor))->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('the payments index page renders and an authenticated user can post and cancel a payment via HTTP', function () {
    $domain = 'payments-http.tenant-test';
    $tenant = provisionPaymentTestTenant($domain);

    $supplierId = null;
    $tenant->run(function () use (&$supplierId) {
        User::factory()->create(['email' => 'owner@example.com']);
        paymentOpenFiscalYear();
        $supplier = Supplier::factory()->create();
        $supplierId = $supplier->id;
    });

    loginPaymentTestUser($domain);

    $this->get("http://{$domain}/payments")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Tenant/Purchases/Payments/Index'));

    $this->post("http://{$domain}/payments", [
        'supplier_id' => $supplierId,
        'date' => '2026-06-10',
        'amount' => 150,
        'payment_mode' => 'cash',
    ])->assertRedirect();

    $paymentId = null;
    $tenant->run(function () use (&$paymentId) {
        expect(Payment::count())->toBe(1);
        $paymentId = Payment::first()->id;
    });

    $this->post("http://{$domain}/payments/{$paymentId}/cancel", ['reason' => 'Duplicate entry'])->assertRedirect();

    $tenant->run(function () use ($paymentId) {
        expect(Payment::find($paymentId)->status)->toBe('cancelled');
    });

    $tenant->delete();
});
