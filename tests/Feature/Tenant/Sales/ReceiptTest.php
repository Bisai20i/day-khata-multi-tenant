<?php

use App\Enums\FiscalYearStatus;
use App\Enums\VoucherType;
use App\Models\Account;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\JournalVoucher;
use App\Models\Receipt;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Money\Money;
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

function receiptTestCreditSale(User $admin, Customer $customer, string $rate = '100', string $quantity = '1'): Sale
{
    $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

    return Sale::post(
        ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'credit'],
        [['item_id' => $item->id, 'quantity' => $quantity, 'rate' => $rate, 'discount' => 0]],
        $admin,
    );
}

/** The same invoice settled in cash at the counter: nothing is left to allocate against. */
function receiptTestCashSale(User $admin, Customer $customer, string $rate = '100', string $quantity = '1'): Sale
{
    $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

    return Sale::post(
        ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
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

        expect(Money::of($lines[$cash->id]->debit)->toString())->toBe('500.00')
            ->and(Money::of($lines[$customer->account_id]->credit)->toString())->toBe('500.00')
            ->and(Money::sum($voucher->lines->pluck('debit'))->toString())
            ->toBe(Money::sum($voucher->lines->pluck('credit'))->toString());
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
        $sale = receiptTestCreditSale($admin, $customer, rate: '100', quantity: '5');

        // Sale::outstandingAmount() returns an exact Money (T04), so there is
        // no float anywhere between the invoice and the allocation cap.
        expect($sale->outstandingAmount()->toString())->toBe('500.00');

        Receipt::post([
            'customer_id' => $customer->id,
            'date' => '2026-06-10',
            'amount' => 500,
            'payment_mode' => 'cash',
            'allocations' => [['sale_id' => $sale->id, 'amount' => 500]],
        ], $admin);

        expect($sale->fresh()->outstandingAmount()->toString())->toBe('0.00');
    });

    $tenant->delete();
});

test('a receipt partially allocated against a credit sale reduces its outstanding amount by exactly the allocated amount', function () {
    $tenant = provisionReceiptTestTenant('receipt-partial-allocation.tenant-test');

    $tenant->run(function () {
        receiptTestOpenFiscalYear();
        $admin = receiptTestAdmin();
        $customer = Customer::factory()->create();
        $sale = receiptTestCreditSale($admin, $customer, rate: '100', quantity: '5');

        Receipt::post([
            'customer_id' => $customer->id,
            'date' => '2026-06-10',
            'amount' => 200,
            'payment_mode' => 'cash',
            'allocations' => [['sale_id' => $sale->id, 'amount' => 200]],
        ], $admin);

        expect($sale->fresh()->outstandingAmount()->toString())->toBe('300.00');
    });

    $tenant->delete();
});

test('allocating more than a sale outstanding balance is rejected', function () {
    $tenant = provisionReceiptTestTenant('receipt-over-allocation.tenant-test');

    $tenant->run(function () {
        receiptTestOpenFiscalYear();
        $admin = receiptTestAdmin();
        $customer = Customer::factory()->create();
        $sale = receiptTestCreditSale($admin, $customer, rate: '100', quantity: '5');

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
        $sale = receiptTestCreditSale($admin, $otherCustomer, rate: '100', quantity: '5');

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
        $saleA = receiptTestCreditSale($admin, $customer, rate: '100', quantity: '5');
        $saleB = receiptTestCreditSale($admin, $customer, rate: '100', quantity: '5');

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
        $sale = receiptTestCreditSale($admin, $customer, rate: '100', quantity: '5');

        $receipt = Receipt::post([
            'customer_id' => $customer->id,
            'date' => '2026-06-10',
            'amount' => 500,
            'payment_mode' => 'cash',
            'allocations' => [['sale_id' => $sale->id, 'amount' => 500]],
        ], $admin);

        expect($sale->fresh()->outstandingAmount()->toString())->toBe('0.00');

        $receipt->cancel($admin, 'Customer disputed the payment');

        $cancelled = $receipt->fresh();

        expect($cancelled->status)->toBe('cancelled')
            ->and($cancelled->cancel_reason)->toBe('Customer disputed the payment')
            ->and($cancelled->cancelled_by)->toBe($admin->id)
            ->and($cancelled->cancelled_at)->not->toBeNull()
            ->and($cancelled->reversal_journal_voucher_id)->not->toBeNull()
            ->and($sale->fresh()->outstandingAmount()->toString())->toBe('500.00');

        // The reversal is its own Reversal-series voucher, so no receipt
        // number is ever consumed by a cancellation (CONTRACTS C4/C5).
        $cancelVoucher = JournalVoucher::findOrFail($cancelled->reversal_journal_voucher_id);
        expect($cancelVoucher->voucher_type)->toBe(VoucherType::Reversal)
            ->and($cancelVoucher->reversal_of_id)->toBe($receipt->journal_voucher_id)
            ->and(Money::sum($cancelVoucher->lines->pluck('debit'))->toString())
            ->toBe(Money::sum($cancelVoucher->lines->pluck('credit'))->toString());

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
        $sale = receiptTestCreditSale($admin, $customer, rate: '100', quantity: '5');

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
        $sale = receiptTestCreditSale($admin, $customer, rate: '100', quantity: '5');
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
        // Cancelling reverses money already collected, so the route is
        // admin-only now (CONTRACTS C5).
        User::factory()->create([
            'email' => 'owner@example.com',
            'role_id' => Role::where('slug', 'admin')->value('id'),
        ]);
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

test('a one paisa receipt is accepted and a one paisa over-allocation is not', function () {
    $tenant = provisionReceiptTestTenant('receipt-one-paisa.tenant-test');

    $tenant->run(function () {
        receiptTestOpenFiscalYear();
        $admin = receiptTestAdmin();
        $customer = Customer::factory()->create();
        $sale = receiptTestCreditSale($admin, $customer, rate: '0.01', quantity: '1');

        expect($sale->outstandingAmount()->toString())->toBe('0.01');

        // A paisa is a real amount: the old "> 0.01" guard refused it
        // outright and left such invoices permanently unsettleable (P0-4).
        $receipt = Receipt::post([
            'customer_id' => $customer->id,
            'date' => '2026-06-10',
            'amount' => '0.01',
            'payment_mode' => 'cash',
            'allocations' => [['sale_id' => $sale->id, 'amount' => '0.01']],
        ], $admin);

        expect($receipt->amount)->toBe('0.01')
            ->and($sale->fresh()->outstandingAmount()->toString())->toBe('0.00');

        $second = receiptTestCreditSale($admin, $customer, rate: '100', quantity: '1');

        // ...and a paisa too much is refused rather than absorbed by a
        // tolerance: 100.01 against a 100.00 invoice.
        expect(fn () => Receipt::post([
            'customer_id' => $customer->id,
            'date' => '2026-06-10',
            'amount' => '200.00',
            'payment_mode' => 'cash',
            'allocations' => [['sale_id' => $second->id, 'amount' => '100.01']],
        ], $admin))->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('allocating against a sale with nothing outstanding is rejected', function () {
    $tenant = provisionReceiptTestTenant('receipt-nothing-outstanding.tenant-test');

    $tenant->run(function () {
        receiptTestOpenFiscalYear();
        $admin = receiptTestAdmin();
        $customer = Customer::factory()->create();

        // A cash sale was settled at the counter; there is no receivable for
        // a receipt to apply to, so allocating against it would double-count
        // the money.
        $cashSale = receiptTestCashSale($admin, $customer, rate: '100', quantity: '5');

        expect($cashSale->outstandingAmount()->toString())->toBe('0.00');

        expect(fn () => Receipt::post([
            'customer_id' => $customer->id,
            'date' => '2026-06-10',
            'amount' => '500.00',
            'payment_mode' => 'cash',
            'allocations' => [['sale_id' => $cashSale->id, 'amount' => '500.00']],
        ], $admin))->toThrow(InvalidArgumentException::class);

        expect(Receipt::query()->count())->toBe(0);
    });

    $tenant->delete();
});

test('naming the same invoice twice in one payload cannot settle more than it owes', function () {
    $domain = 'receipt-duplicate-allocation.tenant-test';
    $tenant = provisionReceiptTestTenant($domain);

    $customerId = null;
    $saleId = null;

    $tenant->run(function () use (&$customerId, &$saleId) {
        User::factory()->create(['email' => 'owner@example.com']);
        receiptTestOpenFiscalYear();
        $admin = receiptTestAdmin();
        $customer = Customer::factory()->create();
        $sale = receiptTestCreditSale($admin, $customer, rate: '100', quantity: '5');
        $customerId = $customer->id;
        $saleId = $sale->id;

        // Receipt::post() aggregates per invoice before checking the cap, so
        // even a caller that bypasses the request validation cannot settle
        // 1000 against a 500 invoice (audit P0-14).
        expect(fn () => Receipt::post([
            'customer_id' => $customerId,
            'date' => '2026-06-10',
            'amount' => '1000.00',
            'payment_mode' => 'cash',
            'allocations' => [
                ['sale_id' => $saleId, 'amount' => '500.00'],
                ['sale_id' => $saleId, 'amount' => '500.00'],
            ],
        ], $admin))->toThrow(InvalidArgumentException::class);
    });

    loginReceiptTestUser($domain);

    // And the request layer refuses the duplicate outright.
    $this->post("http://{$domain}/receipts", [
        'customer_id' => $customerId,
        'date' => '2026-06-10',
        'amount' => 400,
        'payment_mode' => 'cash',
        'allocations' => [
            ['sale_id' => $saleId, 'amount' => 200],
            ['sale_id' => $saleId, 'amount' => 200],
        ],
    ])->assertSessionHasErrors('allocations.0.sale_id');

    $tenant->run(function () {
        expect(Receipt::query()->count())->toBe(0);
    });

    $tenant->delete();
});

test('cancelling a receipt is refused for a user who is not an admin', function () {
    $domain = 'receipt-cancel-role.tenant-test';
    $tenant = provisionReceiptTestTenant($domain);

    $receiptId = null;
    $tenant->run(function () use (&$receiptId) {
        // No role at all: a plain tenant user may record receipts but not
        // reverse one (CONTRACTS C5).
        User::factory()->create(['email' => 'owner@example.com']);
        receiptTestOpenFiscalYear();
        $admin = receiptTestAdmin();
        $customer = Customer::factory()->create();

        $receiptId = Receipt::post([
            'customer_id' => $customer->id,
            'date' => '2026-06-10',
            'amount' => '250.00',
            'payment_mode' => 'cash',
        ], $admin)->id;
    });

    loginReceiptTestUser($domain);

    $this->post("http://{$domain}/receipts/{$receiptId}/cancel", ['reason' => 'Not my call'])
        ->assertForbidden();

    $tenant->run(function () use ($receiptId) {
        expect(Receipt::findOrFail($receiptId)->status)->toBe('posted');
    });

    $tenant->delete();
});
