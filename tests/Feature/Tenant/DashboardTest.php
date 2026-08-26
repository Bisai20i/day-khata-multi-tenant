<?php

use App\Models\Customer;
use App\Models\Item;
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

test('dashboard renders with real kpis, recent customers, and account head breakdown', function () {
    $domain = 'dashboard.tenant-test';
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);

        Customer::factory()->count(2)->create();
        Supplier::factory()->count(1)->create();
        Item::factory()->count(3)->create();
    });

    $this->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);

    $response = $this->get("http://{$domain}/dashboard");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Tenant/Dashboard')
        ->has('kpis.customers.total')
        ->has('kpis.customers.thisWeek')
        ->has('kpis.suppliers.total')
        ->has('kpis.suppliers.thisWeek')
        ->has('kpis.items.total')
        ->has('kpis.items.thisWeek')
        ->has('kpis.accounts.total')
        ->where('kpis.customers.total', 2)
        ->where('kpis.suppliers.total', 1)
        ->where('kpis.items.total', 3)
        ->has('recentCustomers', 2)
        ->has('recentCustomers.0', fn ($customer) => $customer
            ->has('name')
            ->has('mobile')
            ->has('code')
            ->has('added')
        )
        ->has('accountHeadBreakdown')
        ->has('accountHeadBreakdown.0', fn ($head) => $head
            ->has('name')
            ->has('count')
        )
    );

    $tenant->delete();
});
