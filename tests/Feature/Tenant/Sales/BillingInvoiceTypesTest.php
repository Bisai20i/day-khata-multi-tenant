<?php

use App\Enums\FiscalYearStatus;
use App\Enums\VoucherType;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionBillingInvoiceTypesTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Invoice Types Test Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function loginBillingInvoiceTypesUser(string $domain): void
{
    test()->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);
}

test('store route validates invoice_type in full, abbreviated, pan', function () {
    $domain = 'sale-inv-validate.tenant-test';
    $tenant = provisionBillingInvoiceTypesTenant($domain);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);
    });

    loginBillingInvoiceTypesUser($domain);

    $res = $this->post("http://{$domain}/sales", [
        'invoice_type' => 'invalid_type',
    ]);

    $res->assertSessionHasErrors('invoice_type');

    $tenant->delete();
});

test('store route allows posting a full tax invoice with standard VAT', function () {
    $domain = 'sale-full-tax.tenant-test';
    $tenant = provisionBillingInvoiceTypesTenant($domain);

    $customerId = null;
    $itemId = null;

    $tenant->run(function () use (&$customerId, &$itemId) {
        User::factory()->create(['email' => 'owner@example.com']);
        FiscalYear::create(['name' => '2081/82', 'start_date' => '2024-07-16', 'end_date' => '2025-07-15', 'status' => FiscalYearStatus::Open]);
        CompanySetting::current()->update(['allow_negative_stock' => true]);

        $cust = Customer::factory()->create(['name' => 'Full Tax Customer', 'tpin' => '123456789']);
        $customerId = $cust->id;

        $item = Item::factory()->create(['name' => 'Vatable Widget', 'sale_rate' => '100.00', 'is_vatable' => true, 'is_stockable' => true]);
        $itemId = $item->id;
    });

    loginBillingInvoiceTypesUser($domain);

    $res = $this->post("http://{$domain}/sales", [
        'customer_id' => $customerId,
        'invoice_type' => 'full',
        'date' => '2025-01-01',
        'payment_mode' => 'cash',
        'lines' => [
            ['item_id' => $itemId, 'quantity' => '2', 'rate' => '100.00'],
        ],
        'expected_total' => '226.00',
    ]);

    $res->assertRedirect();

    $tenant->run(function () {
        $sale = Sale::first();
        expect($sale)->not->toBeNull();
        expect($sale->invoice_type)->toBe('full');
        expect((string) $sale->vat_amount)->toBe('26.00');
        expect((string) $sale->total)->toBe('226.00');
        expect($sale->journalVoucher->voucher_type)->toBe(VoucherType::Sale);
    });

    $tenant->delete();
});

test('store route allows posting an abbreviated invoice under NPR 10,000 ceiling', function () {
    $domain = 'sale-abit-ok.tenant-test';
    $tenant = provisionBillingInvoiceTypesTenant($domain);

    $customerId = null;
    $itemId = null;

    $tenant->run(function () use (&$customerId, &$itemId) {
        User::factory()->create(['email' => 'owner@example.com']);
        FiscalYear::create(['name' => '2081/82', 'start_date' => '2024-07-16', 'end_date' => '2025-07-15', 'status' => FiscalYearStatus::Open]);
        CompanySetting::current()->update(['allow_negative_stock' => true]);

        $cust = Customer::factory()->create(['name' => 'Walk-in', 'is_walk_in' => true]);
        $customerId = $cust->id;

        $item = Item::factory()->create(['name' => 'Coffee', 'sale_rate' => '100.00', 'is_vatable' => true, 'is_stockable' => true]);
        $itemId = $item->id;
    });

    loginBillingInvoiceTypesUser($domain);

    $res = $this->post("http://{$domain}/sales", [
        'customer_id' => $customerId,
        'invoice_type' => 'abbreviated',
        'date' => '2025-01-01',
        'payment_mode' => 'cash',
        'lines' => [
            ['item_id' => $itemId, 'quantity' => '5', 'rate' => '100.00'],
        ],
        'expected_total' => '565.00',
    ]);

    $res->assertRedirect();

    $tenant->run(function () {
        $sale = Sale::first();
        expect($sale)->not->toBeNull();
        expect($sale->invoice_type)->toBe('abbreviated');
        expect($sale->journalVoucher->voucher_type)->toBe(VoucherType::SaleAbbreviated);
    });

    $tenant->delete();
});

test('store route rejects an abbreviated invoice exceeding NPR 10,000 ceiling', function () {
    $domain = 'sale-abit-exceed.tenant-test';
    $tenant = provisionBillingInvoiceTypesTenant($domain);

    $customerId = null;
    $itemId = null;

    $tenant->run(function () use (&$customerId, &$itemId) {
        User::factory()->create(['email' => 'owner@example.com']);
        FiscalYear::create(['name' => '2081/82', 'start_date' => '2024-07-16', 'end_date' => '2025-07-15', 'status' => FiscalYearStatus::Open]);
        CompanySetting::current()->update(['allow_negative_stock' => true]);

        $cust = Customer::factory()->create(['name' => 'Walk-in', 'is_walk_in' => true]);
        $customerId = $cust->id;

        $item = Item::factory()->create(['name' => 'Expensive Device', 'sale_rate' => '15000.00', 'is_vatable' => true, 'is_stockable' => true]);
        $itemId = $item->id;
    });

    loginBillingInvoiceTypesUser($domain);

    $res = $this->post("http://{$domain}/sales", [
        'customer_id' => $customerId,
        'invoice_type' => 'abbreviated',
        'date' => '2025-01-01',
        'payment_mode' => 'cash',
        'lines' => [
            ['item_id' => $itemId, 'quantity' => '1', 'rate' => '15000.00'],
        ],
        'expected_total' => '16950.00',
    ]);

    $res->assertSessionHasErrors('lines');

    $tenant->delete();
});

test('store route allows posting a PAN invoice with 0% VAT enforced', function () {
    $domain = 'sale-pan-zero-vat.tenant-test';
    $tenant = provisionBillingInvoiceTypesTenant($domain);

    $customerId = null;
    $itemId = null;

    $tenant->run(function () use (&$customerId, &$itemId) {
        User::factory()->create(['email' => 'owner@example.com']);
        FiscalYear::create(['name' => '2081/82', 'start_date' => '2024-07-16', 'end_date' => '2025-07-15', 'status' => FiscalYearStatus::Open]);
        CompanySetting::current()->update(['allow_negative_stock' => true]);

        $cust = Customer::factory()->create(['name' => 'PAN Buyer']);
        $customerId = $cust->id;

        // Even though item is marked is_vatable => true, PAN invoice forces 0% VAT
        $item = Item::factory()->create(['name' => 'Vatable Widget', 'sale_rate' => '100.00', 'is_vatable' => true, 'is_stockable' => true]);
        $itemId = $item->id;
    });

    loginBillingInvoiceTypesUser($domain);

    $res = $this->post("http://{$domain}/sales", [
        'customer_id' => $customerId,
        'invoice_type' => 'pan',
        'date' => '2025-01-01',
        'payment_mode' => 'cash',
        'lines' => [
            ['item_id' => $itemId, 'quantity' => '2', 'rate' => '100.00'],
        ],
        'expected_total' => '200.00',
    ]);

    $res->assertRedirect();

    $tenant->run(function () {
        $sale = Sale::first();
        expect($sale)->not->toBeNull();
        expect($sale->invoice_type)->toBe('pan');
        expect((string) $sale->vat_amount)->toBe('0.00');
        expect((string) $sale->nontaxable_amount)->toBe('200.00');
        expect((string) $sale->taxable_amount)->toBe('0.00');
        expect((string) $sale->total)->toBe('200.00');
        expect($sale->journalVoucher->voucher_type)->toBe(VoucherType::SalePan);
    });

    $tenant->delete();
});
