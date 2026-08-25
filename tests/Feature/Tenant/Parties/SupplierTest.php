<?php

use App\Models\Supplier;
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

function provisionSupplierTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

test('creating a supplier auto-creates a linked ledger account under Sundry Creditors', function () {
    $domain = 'supplier-ledger.tenant-test';
    $tenant = provisionSupplierTestTenant($domain);

    $tenant->run(function () {
        $supplier = Supplier::factory()->create(['name' => 'Himal Traders', 'mobile_no' => '9800000003']);

        expect($supplier->account_id)->not->toBeNull();
        expect($supplier->account->name)->toBe('Himal Traders');
        expect($supplier->account->subgroup->name)->toBe('Sundry Creditors');
    });

    $tenant->delete();
});

test('an authenticated user can create, update, and delete a supplier', function () {
    $domain = 'supplier-crud.tenant-test';
    $tenant = provisionSupplierTestTenant($domain);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);
    });

    $this->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);

    $store = $this->post("http://{$domain}/suppliers", [
        'name' => 'Everest Suppliers',
        'mobile_no' => '9844444444',
    ]);
    $store->assertRedirect("http://{$domain}/suppliers");

    $supplierId = null;
    $tenant->run(function () use (&$supplierId) {
        $supplier = Supplier::query()->where('name', 'Everest Suppliers')->firstOrFail();
        $supplierId = $supplier->id;
        expect($supplier->account_id)->not->toBeNull();
    });

    $update = $this->put("http://{$domain}/suppliers/{$supplierId}", [
        'name' => 'Everest Traders',
        'mobile_no' => '9844444444',
    ]);
    $update->assertRedirect("http://{$domain}/suppliers");

    $tenant->run(function () use ($supplierId) {
        expect(Supplier::query()->findOrFail($supplierId)->name)->toBe('Everest Traders');
        expect(Supplier::query()->findOrFail($supplierId)->account->name)->toBe('Everest Traders');
    });

    $destroy = $this->delete("http://{$domain}/suppliers/{$supplierId}");
    $destroy->assertRedirect("http://{$domain}/suppliers");

    $tenant->run(function () use ($supplierId) {
        expect(Supplier::query()->find($supplierId))->toBeNull();
    });

    $tenant->delete();
});
