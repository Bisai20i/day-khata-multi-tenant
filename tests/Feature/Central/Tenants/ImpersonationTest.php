<?php

use App\Models\PlatformAdmin;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

uses(RefreshDatabase::class);

afterEach(function () {
    Tenant::all()->each(fn (Tenant $tenant) => $tenant->delete());
});

/**
 * Provisions a tenant through the real HTTP endpoint (as an authenticated
 * platform admin) and returns it, so these tests exercise the same
 * provisioning flow (and its real seeded admin user) as the other central
 * tenant tests.
 */
function provisionImpersonationTestTenant(TestCase $test, PlatformAdmin $admin, string $subdomain): Tenant
{
    $test->actingAs($admin, 'platform')->post(route('central.tenants.store'), [
        'company_name' => ucfirst($subdomain).' Inc',
        'subdomain' => $subdomain,
        'contact_email' => null,
        'admin_name' => 'Tenant Admin',
        'admin_email' => "admin@{$subdomain}.test",
        'admin_password' => 'password123',
    ]);

    return Tenant::where('company_name', ucfirst($subdomain).' Inc')->firstOrFail();
}

test('a platform admin can trigger impersonation and land authenticated as the tenant admin on the tenant dashboard', function () {
    $admin = PlatformAdmin::factory()->create();
    $tenant = provisionImpersonationTestTenant($this, $admin, 'impersonateme');

    $adminUserId = $tenant->run(fn () => User::where('email', 'admin@impersonateme.test')->value('id'));

    $response = $this->actingAs($admin, 'platform')
        ->post(route('central.tenants.impersonate', $tenant));

    // Not an Inertia request here, so Inertia::location() falls back to a
    // plain 302 redirect to the signed URL (see ResponseFactory::location()).
    $response->assertStatus(302);
    $signedUrl = $response->headers->get('Location');

    expect($signedUrl)->toContain('impersonateme.localhost')
        ->and($signedUrl)->toContain('/impersonate/')
        ->and($signedUrl)->toContain('signature=');

    $landing = $this->get($signedUrl);

    $landing->assertRedirect('http://impersonateme.localhost/dashboard');
    expect(Auth::guard('web')->check())->toBeTrue();
    expect(Auth::guard('web')->id())->toBe($adminUserId);
});

test('a non-platform-admin cannot trigger impersonation', function () {
    $admin = PlatformAdmin::factory()->create();
    $tenant = provisionImpersonationTestTenant($this, $admin, 'noaccess');

    // provisionImpersonationTestTenant() used actingAs() to provision, which
    // leaves the platform guard authenticated for the rest of this
    // TestCase - log out so this request is genuinely anonymous.
    Auth::guard('platform')->logout();

    $this->post(route('central.tenants.impersonate', $tenant))
        ->assertRedirect(route('login'));

    expect(Auth::guard('web')->check())->toBeFalse();
});

test('an expired signed impersonation link is rejected', function () {
    $admin = PlatformAdmin::factory()->create();
    $tenant = provisionImpersonationTestTenant($this, $admin, 'expiredlink');

    $adminUserId = $tenant->run(fn () => User::where('email', 'admin@expiredlink.test')->value('id'));

    URL::forceRootUrl('http://expiredlink.localhost');
    $expiredUrl = URL::temporarySignedRoute('tenant.impersonate', now()->subMinute(), ['user' => $adminUserId]);
    URL::forceRootUrl(null);

    $this->get($expiredUrl)->assertForbidden();

    expect(Auth::guard('web')->check())->toBeFalse();
});

test('a tampered signed impersonation link is rejected', function () {
    $admin = PlatformAdmin::factory()->create();
    $tenant = provisionImpersonationTestTenant($this, $admin, 'tamperedlink');

    $adminUserId = $tenant->run(fn () => User::where('email', 'admin@tamperedlink.test')->value('id'));

    URL::forceRootUrl('http://tamperedlink.localhost');
    $validUrl = URL::temporarySignedRoute('tenant.impersonate', now()->addMinutes(5), ['user' => $adminUserId]);
    URL::forceRootUrl(null);

    $tamperedUrl = str_replace('signature=', 'signature=tampered', $validUrl);

    $this->get($tamperedUrl)->assertForbidden();

    expect(Auth::guard('web')->check())->toBeFalse();
});

test('a tenant with no admin user returns a clean error instead of a crash', function () {
    $admin = PlatformAdmin::factory()->create();
    $tenant = provisionImpersonationTestTenant($this, $admin, 'noadmin');

    // Simulate a tenant with no admin-role user at all (e.g. everyone was
    // demoted/deactivated some other way) - deleting the seeded admin is the
    // simplest way to reach that state through the real pipeline.
    $tenant->run(function () {
        User::query()->delete();
    });

    $response = $this->actingAs($admin, 'platform')
        ->post(route('central.tenants.impersonate', $tenant));

    $response->assertRedirect(route('central.tenants.show', $tenant));
    $response->assertSessionHas('status', 'This tenant has no admin user to impersonate.');
});
