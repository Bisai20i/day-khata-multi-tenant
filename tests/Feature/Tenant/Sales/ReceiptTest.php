<?php

use App\Enums\FiscalYearStatus;
use App\Models\Account;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\JournalVoucher;
use App\Models\Receipt;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionReceiptTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function receiptTestAdmin(): User
{
    return User::factory()->create();
}

function receiptTestOpenFiscalYear(): FiscalYear
{
    return FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
}

function receiptTestCreditSale(User $admin, Customer $customer, float $rate = 100, float $quantity = 1): Sale
{
    $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

    return Sale::post(
        ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'credit'],
        [['item_id' => $item->id, 'quantity' => $quantity, 'rate' => $rate, 'discount' => 0]],
        $admin,
    );
}

test('posting a cash receipt with no allocations posts a balanced voucher against the customer account', function () {
    $tenant = provisionReceiptTestTenant('receipt-basic.tenant-test');

    $tenant->run(function () {
        receiptTestOpenFiscalYear();
        $admin = receiptTestAdmin();
        $customer = Customer::factory()->create();
        $cash = Account::where('code', 'AS1')->firstOrFail();

        $receipt = Receipt::post([
            'customer_id' => $customer->id,
            'date' => '2026-06-10',
            'amount' => 500,
            'payment_mode' => 'cash',
        ], $admin);

        expect($receipt->status)->toBe('posted')
            ->and($receipt->allocations)->toHaveCount(0);

        $voucher = $receipt->journalVoucher;
        $lines = $voucher->lines->keyBy('account_id');

        expect((float) $lines[$cash->id]->debit)->toBe(500.0)
            ->and((float) $lines[$customer->account_id]->credit)->toBe(500.0)
            ->and((float) $voucher->lines->sum('debit'))->toBe((float) $voucher->lines->sum('credit'));
    });

    $tenant->delete();
});

test('a bank receipt without a bank account is rejected', function () {
    $tenant = provisionReceiptTestTenant('receipt-bank-validation.tenant-test');

    $tenant->run(function () {
        receiptTestOpenFiscalYear();
        $admin = receiptTestAdmin();
        $customer = Customer::factory()->create();

        expect(fn () => Receipt::post([
            'customer_id' => $customer->id,
            'date' => '2026-06-10',
            'amount' => 500,
            'payment_mode' => 'bank',
        ], $admin))->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('a receipt fully allocated against a credit sale zeroes out its outstanding amount', function () {
    $tenant = provisionReceiptTestTenant('receipt-full-allocation.tenant-test');

    $tenant->run(function () {
        receiptTestOpenFiscalYear();
        $admin = receiptTestAdmin();
        $customer = Customer::factory()->create();
        $sale = receiptTestCreditSale($admin, $customer, rate: 100, quantity: 5);

        expect($sale->outstandingAmount())->toBe(500.0);

        Receipt::post([
            'customer_id' => $customer->id,
            'date' => '2026-06-10',
            'amount' => 500,
            'payment_mode' => 'cash',
            'allocations' => [['sale_id' => $sale->id, 'amount' => 500]],
        ], $admin);

        expect($sale->fresh()->outstandingAmount())->toBe(0.0);
    });

    $tenant->delete();
});

test('a receipt partially allocated against a credit sale reduces its outstanding amount by exactly the allocated amount', function () {
    $tenant = provisionReceiptTestTenant('receipt-partial-allocation.tenant-test');

    $tenant->run(function () {
        receiptTestOpenFiscalYear();
        $admin = receiptTestAdmin();
        $customer = Customer::factory()->create();
        $sale = receiptTestCreditSale($admin, $customer, rate: 100, quantity: 5);

        Receipt::post([
            'customer_id' => $customer->id,
            'date' => '2026-06-10',
            'amount' => 200,
            'payment_mode' => 'cash',
            'allocations' => [['sale_id' => $sale->id, 'amount' => 200]],
        ], $admin);

        expect($sale->fresh()->outstandingAmount())->toBe(300.0);
    });

    $tenant->delete();
});

test('allocating more than a sale outstanding balance is rejected', function () {
    $tenant = provisionReceiptTestTenant('receipt-over-allocation.tenant-test');

    $tenant->run(function () {
        receiptTestOpenFiscalYear();
        $admin = receiptTestAdmin();
        $customer = Customer::factory()->create();
        $sale = receiptTestCreditSale($admin, $customer, rate: 100, quantity: 5);

        expect(fn () => Receipt::post([
            'customer_id' => $customer->id,
            'date' => '2026-06-10',
            'amount' => 1000,
            'payment_mode' => 'cash',
            'allocations' => [['sale_id' => $sale->id, 'amount' => 600]],
        ], $admin))->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('allocating against another customer sale is rejected', function () {
    $tenant = provisionReceiptTestTenant('receipt-wrong-customer.tenant-test');

    $tenant->run(function () {
        receiptTestOpenFiscalYear();
        $admin = receiptTestAdmin();
        $customer = Customer::factory()->create();
        $otherCustomer = Customer::factory()->create();
        $sale = receiptTestCreditSale($admin, $otherCustomer, rate: 100, quantity: 5);

        expect(fn () => Receipt::post([
            'customer_id' => $customer->id,
            'date' => '2026-06-10',
            'amount' => 500,
            'payment_mode' => 'cash',
            'allocations' => [['sale_id' => $sale->id, 'amount' => 500]],
        ], $admin))->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('allocations summing above the receipt own amount are rejected', function () {
    $tenant = provisionReceiptTestTenant('receipt-over-total.tenant-test');

    $tenant->run(function () {
        receiptTestOpenFiscalYear();
        $admin = receiptTestAdmin();
        $customer = Customer::factory()->create();
        $saleA = receiptTestCreditSale($admin, $customer, rate: 100, quantity: 5);
        $saleB = receiptTestCreditSale($admin, $customer, rate: 100, quantity: 5);

        expect(fn () => Receipt::post([
            'customer_id' => $customer->id,
            'date' => '2026-06-10',
            'amount' => 300,
            'payment_mode' => 'cash',
            'allocations' => [
                ['sale_id' => $saleA->id, 'amount' => 200],
                ['sale_id' => $saleB->id, 'amount' => 200],
            ],
        ], $admin))->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('cancelling a receipt reverses its voucher and restores the sale outstanding amount', function () {
    $tenant = provisionReceiptTestTenant('receipt-cancel.tenant-test');

    $tenant->run(function () {
        receiptTestOpenFiscalYear();
        $admin = receiptTestAdmin();
        $customer = Customer::factory()->create();
        $sale = receiptTestCreditSale($admin, $customer, rate: 100, quantity: 5);

        $receipt = Receipt::post([
            'customer_id' => $customer->id,
            'date' => '2026-06-10',
            'amount' => 500,
            'payment_mode' => 'cash',
            'allocations' => [['sale_id' => $sale->id, 'amount' => 500]],
        ], $admin);

        expect($sale->fresh()->outstandingAmount())->toBe(0.0);

        $receipt->cancel($admin, 'Customer disputed the payment');

        expect($receipt->fresh()->status)->toBe('cancelled')
            ->and($sale->fresh()->outstandingAmount())->toBe(500.0);

        $cancelVoucher = JournalVoucher::latest('id')->firstOrFail();
        expect((float) $cancelVoucher->lines->sum('debit'))->toBe((float) $cancelVoucher->lines->sum('credit'));

        expect(fn () => $receipt->cancel($admin, 'Second attempt'))->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('cancelling a sale with a live receipt allocated against it is rejected', function () {
    $tenant = provisionReceiptTestTenant('receipt-blocks-sale-cancel.tenant-test');

    $tenant->run(function () {
        receiptTestOpenFiscalYear();
        $admin = receiptTestAdmin();
        $customer = Customer::factory()->create();
        $sale = receiptTestCreditSale($admin, $customer, rate: 100, quantity: 5);

        Receipt::post([
            'customer_id' => $customer->id,
            'date' => '2026-06-10',
            'amount' => 500,
            'payment_mode' => 'cash',
            'allocations' => [['sale_id' => $sale->id, 'amount' => 500]],
        ], $admin);

        expect(fn () => $sale->cancel($admin, 'Too late'))->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

function loginReceiptTestUser(string $domain): void
{
    test()->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);
}

test('the receipts index page renders and an over-allocated receipt is rejected through the store route', function () {
    $domain = 'receipt-http-validation.tenant-test';
    $tenant = provisionReceiptTestTenant($domain);

    $customerId = null;
    $saleId = null;
    $tenant->run(function () use (&$customerId, &$saleId) {
        User::factory()->create(['email' => 'owner@example.com']);
        receiptTestOpenFiscalYear();
        $admin = receiptTestAdmin();
        $customer = Customer::factory()->create();
        $sale = receiptTestCreditSale($admin, $customer, rate: 100, quantity: 5);
        $customerId = $customer->id;
        $saleId = $sale->id;
    });

    loginReceiptTestUser($domain);

    $this->get("http://{$domain}/receipts")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Tenant/Sales/Receipts/Index'));

    $this->post("http://{$domain}/receipts", [
        'customer_id' => $customerId,
        'date' => '2026-06-10',
        'amount' => 200,
        'payment_mode' => 'cash',
        'allocations' => [
            ['sale_id' => $saleId, 'amount' => 900],
        ],
    ])->assertSessionHasErrors('amount');

    $tenant->run(function () {
        expect(Receipt::query()->count())->toBe(0);
    });

    $tenant->delete();
});

test('the receipts store and cancel routes round-trip a receipt over HTTP', function () {
    $domain = 'receipt-http-roundtrip.tenant-test';
    $tenant = provisionReceiptTestTenant($domain);

    $customerId = null;
    $tenant->run(function () use (&$customerId) {
        User::factory()->create(['email' => 'owner@example.com']);
        receiptTestOpenFiscalYear();
        $customer = Customer::factory()->create();
        $customerId = $customer->id;
    });

    loginReceiptTestUser($domain);

    $this->post("http://{$domain}/receipts", [
        'customer_id' => $customerId,
        'date' => '2026-06-10',
        'amount' => 250,
        'payment_mode' => 'cash',
    ])->assertRedirect("http://{$domain}/receipts");

    $receiptId = null;
    $tenant->run(function () use (&$receiptId) {
        expect(Receipt::query()->count())->toBe(1);
        $receiptId = Receipt::query()->firstOrFail()->id;
    });

    $this->post("http://{$domain}/receipts/{$receiptId}/cancel", ['reason' => 'Duplicate entry'])
        ->assertRedirect("http://{$domain}/receipts");

    $tenant->run(function () use ($receiptId) {
        expect(Receipt::find($receiptId)->status)->toBe('cancelled');
    });

    $tenant->delete();
});
