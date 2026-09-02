<?php

use App\Models\CompanySetting;
use App\Models\Role;
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

function provisionCompanySettingTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function loginAsSettingsAdmin(string $domain): User
{
    $admin = null;

    tenancy()->initialize(Tenant::query()->whereHas('domains', fn ($q) => $q->where('domain', $domain))->firstOrFail());

    $adminRole = Role::query()->where('slug', 'admin')->firstOrFail();
    $admin = User::factory()->create([
        'email' => 'boss@example.com',
        'password' => 'password',
        'role_id' => $adminRole->id,
    ]);

    tenancy()->end();

    test()->post("http://{$domain}/login", [
        'email' => 'boss@example.com',
        'password' => 'password',
    ]);

    return $admin;
}

test('an admin can view the settings edit page', function () {
    $domain = 'company-settings-edit.tenant-test';
    $tenant = provisionCompanySettingTestTenant($domain);
    loginAsSettingsAdmin($domain);

    $response = test()->get("http://{$domain}/settings");

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page->component('Tenant/Admin/Settings/Edit')
        ->has('settings')
        ->where('settings.company_name', 'My Company')
    );

    $tenant->delete();
});

test('a non-admin is forbidden from the settings edit page', function () {
    $domain = 'company-settings-staff.tenant-test';
    $tenant = provisionCompanySettingTestTenant($domain);

    $tenant->run(function () {
        $staffRole = Role::query()->where('slug', 'staff')->firstOrFail();
        User::factory()->create(['email' => 'staffer@example.com', 'role_id' => $staffRole->id]);
    });

    test()->post("http://{$domain}/login", ['email' => 'staffer@example.com', 'password' => 'password']);

    test()->get("http://{$domain}/settings")->assertForbidden();
    test()->put("http://{$domain}/settings", ['company_name' => 'x'])->assertForbidden();

    $tenant->delete();
});

test('an admin can update the company settings', function () {
    $domain = 'company-settings-update.tenant-test';
    $tenant = provisionCompanySettingTestTenant($domain);
    loginAsSettingsAdmin($domain);

    $response = test()->put("http://{$domain}/settings", [
        'company_name' => 'Trinovate Tech',
        'address' => 'Kathmandu, Nepal',
        'phone' => '01-4123456',
        'email' => 'billing@trinovatetech.com',
        'pan_vat_number' => '123456789',
        'invoice_footer_note' => 'Thank you for your business!',
    ]);

    $response->assertRedirect();

    $tenant->run(function () {
        $settings = CompanySetting::current();

        expect($settings->company_name)->toBe('Trinovate Tech');
        expect($settings->address)->toBe('Kathmandu, Nepal');
        expect($settings->phone)->toBe('01-4123456');
        expect($settings->email)->toBe('billing@trinovatetech.com');
        expect($settings->pan_vat_number)->toBe('123456789');
        expect($settings->invoice_footer_note)->toBe('Thank you for your business!');
        expect(CompanySetting::count())->toBe(1);
    });

    $tenant->delete();
});

test('updating settings requires a company name', function () {
    $domain = 'company-settings-validation.tenant-test';
    $tenant = provisionCompanySettingTestTenant($domain);
    loginAsSettingsAdmin($domain);

    $response = test()->put("http://{$domain}/settings", ['company_name' => '']);

    $response->assertSessionHasErrors('company_name');

    $tenant->delete();
});

test('CompanySetting::current always returns exactly one row, even before any row exists', function () {
    $domain = 'company-settings-singleton.tenant-test';
    $tenant = provisionCompanySettingTestTenant($domain);

    $tenant->run(function () {
        expect(CompanySetting::count())->toBe(0);

        $first = CompanySetting::current();

        expect(CompanySetting::count())->toBe(1);
        expect($first->company_name)->toBe('My Company');

        $second = CompanySetting::current();

        expect(CompanySetting::count())->toBe(1);
        expect($second->id)->toBe($first->id);
    });

    $tenant->delete();
});
