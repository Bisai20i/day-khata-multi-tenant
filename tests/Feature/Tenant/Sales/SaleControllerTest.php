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

function provisionSaleControllerTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function loginSaleControllerTestUser(string $domain): void
{
    test()->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);
}

test('the sales index page renders its expected Inertia component', function () {
    $domain = 'sales-page-render.tenant-test';
    $tenant = provisionSaleControllerTestTenant($domain);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);
    });

    loginSaleControllerTestUser($domain);

    $this->get("http://{$domain}/sales")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Tenant/Sales/Index'));

    $tenant->delete();
});

test('an authenticated user can post a sale through the store route', function () {
    $domain = 'sale-store-http.tenant-test';
    $tenant = provisionSaleControllerTestTenant($domain);

    $customerId = null;
    $itemId = null;
    $tenant->run(function () use (&$customerId, &$itemId) {
        User::factory()->create(['email' => 'owner@example.com']);
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $customerId = Customer::factory()->create()->id;
        $itemId = Item::factory()->create(['is_vatable' => true, 'is_stockable' => true])->id;
    });

    loginSaleControllerTestUser($domain);

    $this->post("http://{$domain}/sales", [
        'customer_id' => $customerId,
        'invoice_type' => 'full',
        'date' => '2026-06-01',
        'payment_mode' => 'cash',
        'lines' => [
            ['item_id' => $itemId, 'quantity' => 1, 'rate' => 100],
        ],
    ])->assertRedirect("http://{$domain}/sales");

    $tenant->run(function () {
        expect(Sale::query()->count())->toBe(1);
    });

    $tenant->delete();
});

test('cancelling a sale through the cancel route requires a reason and posts a reversal', function () {
    $domain = 'sale-cancel-http.tenant-test';
    $tenant = provisionSaleControllerTestTenant($domain);

    $saleId = null;
    $tenant->run(function () use (&$saleId) {
        $admin = User::factory()->create(['email' => 'owner@example.com']);
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        $saleId = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        )->id;
    });

    loginSaleControllerTestUser($domain);

    $this->post("http://{$domain}/sales/{$saleId}/cancel", [])
        ->assertSessionHasErrors('reason');

    $this->post("http://{$domain}/sales/{$saleId}/cancel", ['reason' => 'Wrong customer'])
        ->assertRedirect("http://{$domain}/sales");

    $tenant->run(function () use ($saleId) {
        expect(Sale::find($saleId)->status)->toBe('cancelled');
    });

    $tenant->delete();
});

test('the accounts picker for bank/TDS is available on the create form data', function () {
    $domain = 'sales-accounts-prop.tenant-test';
    $tenant = provisionSaleControllerTestTenant($domain);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);
        Account::where('code', 'AS1')->firstOrFail();
    });

    loginSaleControllerTestUser($domain);

    $this->get("http://{$domain}/sales")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('accounts')->has('customers')->has('items'));

    $tenant->delete();
});
