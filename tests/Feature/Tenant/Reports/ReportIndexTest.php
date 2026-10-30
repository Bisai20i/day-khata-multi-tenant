<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Reports hub
|--------------------------------------------------------------------------
|
| GET /reports renders a static landing page: the catalog of reports and the
| per-permission filtering both live in the front end, so the server only has
| to guarantee that it is for signed-in users and sends no report data.
|
*/

afterEach(function () {
    tenancy()->end();
});

function provisionReportIndexTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

test('the reports hub renders for the owner', function () {
    $domain = 'report-index.tenant-test';
    $tenant = provisionReportIndexTestTenant($domain);

    $tenant->run(fn () => User::factory()->create(['email' => 'owner@example.com']));

    $this->post("http://{$domain}/login", ['email' => 'owner@example.com', 'password' => 'password']);

    $this->get("http://{$domain}/reports")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Tenant/Reports/Index'));
});

test('the reports hub renders for a user whose role grants no report permission, and the reports behind it stay gated', function () {
    $domain = 'report-index-restricted.tenant-test';
    $tenant = provisionReportIndexTestTenant($domain);

    $tenant->run(fn () => userWithPermissions(['customers.view'])->update(['email' => 'limited@example.com']));

    $this->post("http://{$domain}/login", ['email' => 'limited@example.com', 'password' => 'password']);

    $this->get("http://{$domain}/reports")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Tenant/Reports/Index'));

    $this->get("http://{$domain}/reports/sales-register")->assertForbidden();
});

test('the reports hub redirects a guest to the login page', function () {
    $domain = 'report-index-guest.tenant-test';
    provisionReportIndexTestTenant($domain);

    $this->get("http://{$domain}/reports")->assertRedirect();
});
