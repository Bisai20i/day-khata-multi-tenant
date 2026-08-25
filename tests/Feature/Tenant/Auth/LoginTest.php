<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

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
 * Provision a real tenant (physical database, migrated + seeded) reachable
 * at the given domain, the way `stancl/tenancy` expects tests to work.
 *
 * Tenant creation already migrates and seeds the tenant database via the
 * TenantCreated event pipeline (see app/Providers/TenancyServiceProvider).
 * No explicit `tenants:seed` call is needed here, and calling it again would
 * seed a second time and violate the roles/permissions unique constraints.
 */
function provisionLoginTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

test('the tenant login screen can be rendered', function () {
    $domain = 'login-render.tenant-test';
    $tenant = provisionLoginTestTenant($domain);

    $response = $this->get("http://{$domain}/login");

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page->component('Tenant/Auth/Login'));

    $tenant->delete();
});

test('a tenant user can authenticate using the login screen', function () {
    $domain = 'login-success.tenant-test';
    $tenant = provisionLoginTestTenant($domain);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);
    });

    $response = $this->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);

    expect(Auth::guard('web')->check())->toBeTrue();
    $response->assertRedirect("http://{$domain}/dashboard");

    $tenant->delete();
});

test('a tenant user cannot authenticate with an invalid password', function () {
    $domain = 'login-invalid.tenant-test';
    $tenant = provisionLoginTestTenant($domain);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);
    });

    $response = $this->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'wrong-password',
    ]);

    expect(Auth::guard('web')->check())->toBeFalse();
    $response->assertSessionHasErrors('email');

    $tenant->delete();
});

test('login does not reveal whether the email exists', function () {
    $domain = 'login-unknown.tenant-test';
    $tenant = provisionLoginTestTenant($domain);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);
    });

    $unknownEmail = $this->post("http://{$domain}/login", [
        'email' => 'nobody@example.com',
        'password' => 'password',
    ]);

    $wrongPassword = $this->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'wrong-password',
    ]);

    // Both failure modes (unknown email vs. known email/wrong password) must
    // surface the exact same generic message, so a caller can't probe which
    // emails are registered.
    $unknownEmail->assertInvalid(['email' => trans('auth.failed')]);
    $wrongPassword->assertInvalid(['email' => trans('auth.failed')]);

    $tenant->delete();
});

test('a tenant user can logout', function () {
    $domain = 'logout.tenant-test';
    $tenant = provisionLoginTestTenant($domain);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);
    });

    $this->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);

    expect(Auth::guard('web')->check())->toBeTrue();

    $response = $this->post("http://{$domain}/logout");

    expect(Auth::guard('web')->check())->toBeFalse();
    $response->assertRedirect("http://{$domain}/login");

    $tenant->delete();
});

test('the protected dashboard redirects unauthenticated visitors to login', function () {
    $domain = 'guest-dashboard.tenant-test';
    $tenant = provisionLoginTestTenant($domain);

    $response = $this->get("http://{$domain}/dashboard");

    $response->assertRedirect("http://{$domain}/login");

    $tenant->delete();
});
