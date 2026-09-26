<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

test('the stock adjustments page still opens when there is no open fiscal year', function () {
    $domain = 'stock-adjustment-no-fiscal-year.tenant-test';
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);
    });

    $this->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);

    $this->get("http://{$domain}/stock-adjustments")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Inventory/StockAdjustments/Index')
            ->where('tenant.has_open_fiscal_year', false));

    $tenant->delete();
});
