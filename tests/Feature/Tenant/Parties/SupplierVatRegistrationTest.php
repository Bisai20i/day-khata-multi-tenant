<?php

use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * T13 item 2: `suppliers.is_vat_registered` is what a purchase form reads to
 * decide whether a bill opens in PAN / non-VAT mode, so it has to survive a
 * create and an update, and existing suppliers have to keep behaving exactly
 * as they did before the column existed (registered).
 */
uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionSupplierVatTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

test('a supplier is VAT registered unless it is said otherwise', function () {
    $tenant = provisionSupplierVatTestTenant('supplier-vat-default.tenant-test');

    $tenant->run(function () {
        $supplier = Supplier::factory()->create();

        expect($supplier->fresh()->is_vat_registered)->toBeTrue();
    });

    $tenant->delete();
});

test('the VAT registration flag round-trips through create and update', function () {
    $domain = 'supplier-vat-crud.tenant-test';
    $tenant = provisionSupplierVatTestTenant($domain);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);
    });

    $this->post("http://{$domain}/login", ['email' => 'owner@example.com', 'password' => 'password']);

    $this->post("http://{$domain}/suppliers", [
        'name' => 'Local Farm Supply',
        'mobile_no' => '9844444445',
        'is_vat_registered' => false,
    ])->assertRedirect("http://{$domain}/suppliers");

    $supplierId = null;

    $tenant->run(function () use (&$supplierId) {
        $supplier = Supplier::query()->where('name', 'Local Farm Supply')->firstOrFail();
        $supplierId = $supplier->id;

        expect($supplier->is_vat_registered)->toBeFalse();
    });

    $this->put("http://{$domain}/suppliers/{$supplierId}", [
        'name' => 'Local Farm Supply',
        'mobile_no' => '9844444445',
        'is_vat_registered' => true,
    ])->assertRedirect("http://{$domain}/suppliers");

    $tenant->run(function () use ($supplierId) {
        expect(Supplier::findOrFail($supplierId)->is_vat_registered)->toBeTrue();
    });

    $tenant->delete();
});
