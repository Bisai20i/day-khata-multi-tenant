<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionSampleFormatsTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Sample Formats Test Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function loginSampleFormatsUser(string $domain): void
{
    test()->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);
}

test('all 5 import modules provide downloadable excel sample format files', function () {
    $domain = 'import-samples.tenant-test';
    $tenant = provisionSampleFormatsTenant($domain);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);
    });

    loginSampleFormatsUser($domain);

    // 1. Customers
    $this->get("http://{$domain}/customers/import/sample-excel")
        ->assertOk()
        ->assertHeader('content-disposition');

    // 2. Suppliers
    $this->get("http://{$domain}/suppliers/import/sample-excel")
        ->assertOk()
        ->assertHeader('content-disposition');

    // 3. Items
    $this->get("http://{$domain}/items/import/sample-excel")
        ->assertOk()
        ->assertHeader('content-disposition');

    // 4. Opening Stock
    $this->get("http://{$domain}/stock-adjustments/opening-stock/sample-excel")
        ->assertOk()
        ->assertHeader('content-disposition');

    // 5. Opening Balances
    $this->get("http://{$domain}/accounts/opening-balances/sample-excel")
        ->assertOk()
        ->assertHeader('content-disposition');

    $tenant->delete();
});
