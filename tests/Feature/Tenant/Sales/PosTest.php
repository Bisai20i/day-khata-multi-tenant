<?php

use App\Enums\FiscalYearStatus;
use App\Models\Account;
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

function provisionPosTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function loginPosTestUser(string $domain): void
{
    test()->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);
}

test('the pos page renders its expected Inertia component with items and customers', function () {
    $domain = 'pos-page-render.tenant-test';
    $tenant = provisionPosTestTenant($domain);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);
        Customer::factory()->create(['name' => 'Walk-in Customer']);
        Item::factory()->create(['name' => 'Instant Noodles', 'is_vatable' => true, 'is_stockable' => true]);
    });

    loginPosTestUser($domain);

    $this->get("http://{$domain}/pos")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Tenant/Sales/Pos')
            ->has('customers', 1)
            ->has('items', 1)
            ->has('accounts')
            ->has('stores')
        );

    $tenant->delete();
});

test('a POS-shaped payload posts a sale through the existing sales store route', function () {
    $domain = 'pos-sale-store-http.tenant-test';
    $tenant = provisionPosTestTenant($domain);

    $customerId = null;
    $itemId = null;
    $tenant->run(function () use (&$customerId, &$itemId) {
        User::factory()->create(['email' => 'owner@example.com']);
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $customerId = Customer::factory()->create()->id;
        $itemId = Item::factory()->create(['is_vatable' => true, 'is_stockable' => true])->id;
    });

    loginPosTestUser($domain);

    // Shape mirrors Pos.vue's completeSale() transform: numeric strings for
    // quantity/rate coerced to numbers, empty optional fields dropped/blank,
    // same as Sales/Create.vue already submits.
    $this->post("http://{$domain}/sales", [
        'customer_id' => $customerId,
        'store_id' => null,
        'invoice_type' => 'full',
        'date' => '2026-06-01',
        'payment_mode' => 'cash',
        'bank_account_id' => null,
        'discount' => 0,
        'vat_rate' => 13,
        'tds_account_id' => null,
        'tds_amount' => 0,
        'narration' => '',
        'lines' => [
            ['item_id' => $itemId, 'quantity' => 2, 'rate' => 50, 'discount' => 0],
        ],
    ])->assertRedirect("http://{$domain}/sales");

    $tenant->run(function () {
        expect(Sale::query()->count())->toBe(1);
        expect(Sale::query()->first()->total)->toBe('113.00');
    });

    $tenant->delete();
});

test('a POS quick-sale with partial cash/bank payment posts correctly', function () {
    $domain = 'pos-sale-partial-http.tenant-test';
    $tenant = provisionPosTestTenant($domain);

    $customerId = null;
    $itemId = null;
    $bankAccountId = null;
    $tenant->run(function () use (&$customerId, &$itemId, &$bankAccountId) {
        User::factory()->create(['email' => 'owner@example.com']);
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $customerId = Customer::factory()->create()->id;
        $itemId = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false])->id;
        $bankAccountId = Account::factory()->create()->id;
    });

    loginPosTestUser($domain);

    $this->post("http://{$domain}/sales", [
        'customer_id' => $customerId,
        'invoice_type' => 'full',
        'date' => '2026-06-01',
        'payment_mode' => 'partial',
        'bank_account_id' => $bankAccountId,
        'vat_rate' => 13,
        'cash_amount' => 60,
        'bank_amount' => 40,
        'lines' => [
            ['item_id' => $itemId, 'quantity' => 1, 'rate' => 100, 'discount' => 0],
        ],
    ])->assertRedirect("http://{$domain}/sales");

    $tenant->run(function () {
        expect(Sale::query()->count())->toBe(1);
        $sale = Sale::query()->first();
        expect($sale->payment_mode)->toBe('partial')
            ->and($sale->cash_amount)->toBe('60.00')
            ->and($sale->bank_amount)->toBe('40.00');
    });

    $tenant->delete();
});
