<?php

use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Tenancy has no automatic "end of request" hook outside of a real PHP-FPM
 * style process boundary, so within a single test process the tenant
 * connection stays the default connection after an HTTP call. Revert to the
 * central connection after every test so RefreshDatabase's teardown rolls
 * back the connection it actually started a transaction on.
 */
afterEach(function () {
    tenancy()->end();
});

function provisionCustomerTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

test('creating a customer auto-creates a linked ledger account under Sundry Debtors', function () {
    $domain = 'customer-ledger.tenant-test';
    $tenant = provisionCustomerTestTenant($domain);

    $tenant->run(function () {
        $customer = Customer::factory()->create(['name' => 'Ram Shrestha', 'mobile_no' => '9800000001']);

        expect($customer->account_id)->not->toBeNull();
        expect($customer->account->name)->toBe('Ram Shrestha');
        expect($customer->account->phone)->toBe('9800000001');
        expect($customer->account->subgroup->name)->toBe('Sundry Debtors');
    });

    $tenant->delete();
});

test('updating a customer syncs its linked ledger account', function () {
    $domain = 'customer-sync.tenant-test';
    $tenant = provisionCustomerTestTenant($domain);

    $tenant->run(function () {
        $customer = Customer::factory()->create(['name' => 'Old Name', 'mobile_no' => '9800000002']);

        $customer->update(['name' => 'New Name', 'mobile_no' => '9811111111', 'address' => 'Kathmandu']);

        expect($customer->account->fresh()->name)->toBe('New Name');
        expect($customer->account->fresh()->phone)->toBe('9811111111');
        expect($customer->account->fresh()->address)->toBe('Kathmandu');
    });

    $tenant->delete();
});

test('an authenticated user can create, update, and delete a customer', function () {
    $domain = 'customer-crud.tenant-test';
    $tenant = provisionCustomerTestTenant($domain);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);
    });

    $this->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);

    $store = $this->post("http://{$domain}/customers", [
        'name' => 'Sita Gurung',
        'mobile_no' => '9822222222',
        'address' => 'Pokhara',
    ]);
    $store->assertRedirect("http://{$domain}/customers");

    $customerId = null;
    $tenant->run(function () use (&$customerId) {
        $customer = Customer::query()->where('name', 'Sita Gurung')->firstOrFail();
        $customerId = $customer->id;
        expect($customer->account_id)->not->toBeNull();
    });

    $update = $this->put("http://{$domain}/customers/{$customerId}", [
        'name' => 'Sita Gurung Thapa',
        'mobile_no' => '9822222222',
    ]);
    $update->assertRedirect("http://{$domain}/customers");

    $tenant->run(function () use ($customerId) {
        expect(Customer::query()->findOrFail($customerId)->name)->toBe('Sita Gurung Thapa');
    });

    $destroy = $this->delete("http://{$domain}/customers/{$customerId}");
    $destroy->assertRedirect("http://{$domain}/customers");

    $tenant->run(function () use ($customerId) {
        expect(Customer::query()->find($customerId))->toBeNull();
    });

    $tenant->delete();
});

test('a duplicate customer mobile number is rejected', function () {
    $domain = 'customer-duplicate-mobile.tenant-test';
    $tenant = provisionCustomerTestTenant($domain);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);
        Customer::factory()->create(['mobile_no' => '9833333333']);
    });

    $this->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);

    $response = $this->post("http://{$domain}/customers", [
        'name' => 'Duplicate Mobile',
        'mobile_no' => '9833333333',
    ]);

    $response->assertSessionHasErrors('mobile_no');

    $tenant->delete();
});
