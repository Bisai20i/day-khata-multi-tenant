<?php

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

/**
 * Every controller's index() names an Inertia component by string
 * ('Tenant/Accounting/Accounts/Index', etc.) with no compile-time link to
 * the actual .vue file - a typo or a moved file would 200 with the wrong (or
 * a nonexistent) component and nothing else would catch it. This test is
 * the guard against that class of bug for the core-business-schema pages.
 */
test('every core business schema page renders its expected Inertia component', function () {
    $domain = 'business-pages-render.tenant-test';
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);
    });

    $this->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);

    $pages = [
        '/account-groups' => 'Tenant/Accounting/AccountGroups/Index',
        '/account-subgroups' => 'Tenant/Accounting/AccountSubgroups/Index',
        '/accounts' => 'Tenant/Accounting/Accounts/Index',
        '/customers' => 'Tenant/Parties/Customers/Index',
        '/suppliers' => 'Tenant/Parties/Suppliers/Index',
        '/item-categories' => 'Tenant/Inventory/ItemCategories/Index',
        '/item-subcategories' => 'Tenant/Inventory/ItemSubcategories/Index',
        '/items' => 'Tenant/Inventory/Items/Index',
    ];

    foreach ($pages as $path => $component) {
        $response = $this->get("http://{$domain}{$path}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component($component));
    }

    $tenant->delete();
});
