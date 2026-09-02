<?php

use App\Models\Store;
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

function provisionStoreTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

test('the store index renders with the seeded default store', function () {
    $domain = 'store-index.tenant-test';
    $tenant = provisionStoreTestTenant($domain);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);
    });

    $this->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);

    $response = $this->get("http://{$domain}/stores");

    $response->assertOk();

    $tenant->run(function () {
        expect(Store::query()->where('name', 'Main Store')->exists())->toBeTrue();
    });

    $tenant->delete();
});

test('an authenticated user can create a store', function () {
    $domain = 'store-store.tenant-test';
    $tenant = provisionStoreTestTenant($domain);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);
    });

    $this->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);

    $response = $this->post("http://{$domain}/stores", [
        'name' => 'Warehouse',
        'address' => '123 Industrial Rd',
        'phone' => '9800000000',
        'is_active' => true,
    ]);

    $response->assertRedirect("http://{$domain}/stores");

    $tenant->run(function () {
        $store = Store::query()->where('name', 'Warehouse')->firstOrFail();
        expect($store->address)->toBe('123 Industrial Rd')
            ->and($store->phone)->toBe('9800000000')
            ->and($store->is_active)->toBeTrue();
    });

    $tenant->delete();
});

test('creating a store without a name is rejected', function () {
    $domain = 'store-validation.tenant-test';
    $tenant = provisionStoreTestTenant($domain);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);
    });

    $this->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);

    $response = $this->post("http://{$domain}/stores", [
        'address' => 'No name provided',
    ]);

    $response->assertSessionHasErrors('name');

    $tenant->delete();
});

test('an authenticated user can update and delete a store', function () {
    $domain = 'store-crud.tenant-test';
    $tenant = provisionStoreTestTenant($domain);

    $storeId = null;
    $tenant->run(function () use (&$storeId) {
        User::factory()->create(['email' => 'owner@example.com']);
        $storeId = Store::factory()->create(['name' => 'Branch A'])->id;
    });

    $this->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);

    $update = $this->put("http://{$domain}/stores/{$storeId}", [
        'name' => 'Branch A Renamed',
        'is_active' => false,
    ]);
    $update->assertRedirect("http://{$domain}/stores");

    $tenant->run(function () use ($storeId) {
        $store = Store::query()->findOrFail($storeId);
        expect($store->name)->toBe('Branch A Renamed')
            ->and($store->is_active)->toBeFalse();
    });

    $destroy = $this->delete("http://{$domain}/stores/{$storeId}");
    $destroy->assertRedirect("http://{$domain}/stores");

    $tenant->run(function () use ($storeId) {
        expect(Store::query()->find($storeId))->toBeNull();
    });

    $tenant->delete();
});
