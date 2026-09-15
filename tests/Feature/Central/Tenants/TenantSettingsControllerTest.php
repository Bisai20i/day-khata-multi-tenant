<?php

use App\Enums\FiscalYearStatus;
use App\Enums\TenantStatus;
use App\Enums\VoucherType;
use App\Models\Account;
use App\Models\CompanySetting;
use App\Models\FiscalYear;
use App\Models\JournalVoucher;
use App\Models\PlatformAdmin;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VoucherSequence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(RefreshDatabase::class);

afterEach(function () {
    // Tenant databases are real SQLite files on disk, independent of the
    // central (in-memory) DB reset RefreshDatabase gives us, so drop them
    // explicitly via the normal TenantDeleted -> DeleteDatabase pipeline
    // (matches the convention in TenantUserControllerTest).
    Tenant::all()->each(fn (Tenant $tenant) => $tenant->delete());
});

/**
 * Provisions a tenant through the real HTTP endpoint (as an authenticated
 * platform admin) and returns it, so this test exercises the same
 * provisioning flow (and its real seeded admin user/database) as the other
 * central tenant tests.
 */
function provisionTenantSettingsTestTenant(TestCase $test, PlatformAdmin $admin, string $subdomain): Tenant
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

/**
 * @return array<string, mixed>
 */
function baseCentralSettingsUpdatePayload(): array
{
    return [
        'company_name' => 'Trinovate Tech',
        'address' => 'Kathmandu, Nepal',
        'phone' => '01-4123456',
        'email' => 'billing@trinovatetech.com',
        'pan_vat_number' => '123456789',
        'invoice_footer_note' => 'Thank you for your business!',
        'print_paper_size' => 'a4',
        'default_vat_rate' => '13.00',
        'allow_negative_stock' => false,
        'default_store_id' => null,
        'sale_full_prefix' => 'SL',
        'sale_full_enabled' => true,
        'sale_abbreviated_prefix' => 'SLA',
        'sale_abbreviated_enabled' => true,
        'sale_pan_prefix' => 'SLP',
        'sale_pan_enabled' => true,
        'purchase_prefix' => 'PU',
        'sale_return_prefix' => 'SR',
        'purchase_return_prefix' => 'PR',
    ];
}

test('a platform admin can view a tenant\'s settings edit page', function () {
    $admin = PlatformAdmin::factory()->create();
    $tenant = provisionTenantSettingsTestTenant($this, $admin, 'settingsview');

    $response = $this->actingAs($admin, 'platform')
        ->get(route('central.tenants.settings.edit', $tenant));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Central/Tenants/Settings/Edit')
        ->where('tenant.id', $tenant->id)
        ->has('settings')
        ->where('settings.company_name', 'My Company')
    );
});

test('viewing settings for a tenant with a missing database returns a clean error instead of a crash', function () {
    $admin = PlatformAdmin::factory()->create();

    // Same technique ImpersonationTest/TenantUserControllerTest use:
    // Queue::fake() stops the real CreateDatabase job from ever running.
    Queue::fake();
    $tenant = new Tenant(['company_name' => 'No Database Settings Co', 'status' => TenantStatus::Active]);
    $tenant->save();
    $tenant->domains()->create(['domain' => 'nodatabasesettingsco.localhost']);

    $response = $this->actingAs($admin, 'platform')
        ->get(route('central.tenants.settings.edit', $tenant));

    $response->assertRedirect(route('central.tenants.show', $tenant));
    $response->assertSessionHas('status', "This tenant's database doesn't exist yet - use \"Re-provision database\" first.");
});

test('a guest cannot view a tenant\'s settings', function () {
    $admin = PlatformAdmin::factory()->create();
    $tenant = provisionTenantSettingsTestTenant($this, $admin, 'settingsguest');

    $this->get(route('central.tenants.settings.edit', $tenant))
        ->assertRedirect(route('login'));
});

test('a tenant-side web-guard user cannot view the central tenant settings page', function () {
    $admin = PlatformAdmin::factory()->create();
    $tenant = provisionTenantSettingsTestTenant($this, $admin, 'settingswebguard');

    $tenantUser = $tenant->run(fn () => User::where('email', 'admin@settingswebguard.test')->firstOrFail());

    $this->actingAs($tenantUser, 'web')
        ->get(route('central.tenants.settings.edit', $tenant))
        ->assertRedirect(route('login'));

    expect(Auth::guard('platform')->check())->toBeFalse();
});

test('a platform admin can update a tenant\'s company settings', function () {
    $admin = PlatformAdmin::factory()->create();
    $tenant = provisionTenantSettingsTestTenant($this, $admin, 'settingsupdate');

    $response = $this->actingAs($admin, 'platform')
        ->put(route('central.tenants.settings.update', $tenant), baseCentralSettingsUpdatePayload());

    $response->assertRedirect(route('central.tenants.settings.edit', $tenant));

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
});

test('updating a tenant\'s settings requires a company name', function () {
    $admin = PlatformAdmin::factory()->create();
    $tenant = provisionTenantSettingsTestTenant($this, $admin, 'settingsvalidation');

    $response = $this->actingAs($admin, 'platform')
        ->put(route('central.tenants.settings.update', $tenant), ['company_name' => '']);

    $response->assertSessionHasErrors('company_name');
});

test('a platform admin can update the invoicing and stock policy fields', function () {
    $admin = PlatformAdmin::factory()->create();
    $tenant = provisionTenantSettingsTestTenant($this, $admin, 'settingsinvoicing');

    $storeId = $tenant->run(fn () => Store::factory()->create(['name' => 'Main Warehouse'])->id);

    $payload = array_merge(baseCentralSettingsUpdatePayload(), [
        'default_vat_rate' => '7.50',
        'allow_negative_stock' => true,
        'default_store_id' => $storeId,
        'sale_full_prefix' => 'INV',
        'sale_full_enabled' => false,
        'sale_abbreviated_prefix' => 'ABR',
        'sale_pan_prefix' => 'PAN',
        'purchase_prefix' => 'PRC',
        'sale_return_prefix' => 'CRN',
        'purchase_return_prefix' => 'DBN',
    ]);

    $response = $this->actingAs($admin, 'platform')
        ->put(route('central.tenants.settings.update', $tenant), $payload);

    $response->assertRedirect(route('central.tenants.settings.edit', $tenant));

    $tenant->run(function () use ($storeId) {
        $settings = CompanySetting::current();

        expect((float) $settings->default_vat_rate)->toBe(7.50);
        expect($settings->allow_negative_stock)->toBeTrue();
        expect($settings->default_store_id)->toBe($storeId);
        expect($settings->sale_full_prefix)->toBe('INV');
        expect($settings->sale_full_enabled)->toBeFalse();
    });
});

test('an out-of-range VAT rate is rejected', function () {
    $admin = PlatformAdmin::factory()->create();
    $tenant = provisionTenantSettingsTestTenant($this, $admin, 'settingsvatrange');

    $payload = array_merge(baseCentralSettingsUpdatePayload(), ['default_vat_rate' => '150']);

    $this->actingAs($admin, 'platform')
        ->put(route('central.tenants.settings.update', $tenant), $payload)
        ->assertSessionHasErrors('default_vat_rate');
});

test('a VAT rate with more than two decimals is rejected instead of being silently rounded', function () {
    $admin = PlatformAdmin::factory()->create();
    $tenant = provisionTenantSettingsTestTenant($this, $admin, 'settingsvatprecision');

    $payload = array_merge(baseCentralSettingsUpdatePayload(), ['default_vat_rate' => '13.005']);

    $this->actingAs($admin, 'platform')
        ->put(route('central.tenants.settings.update', $tenant), $payload)
        ->assertSessionHasErrors('default_vat_rate');
});

test('a non-existent default store id is rejected', function () {
    $admin = PlatformAdmin::factory()->create();
    $tenant = provisionTenantSettingsTestTenant($this, $admin, 'settingsstorevalidation');

    $payload = array_merge(baseCentralSettingsUpdatePayload(), ['default_store_id' => 999999]);

    $this->actingAs($admin, 'platform')
        ->put(route('central.tenants.settings.update', $tenant), $payload)
        ->assertSessionHasErrors('default_store_id');
});

test('two document series cannot share a prefix', function () {
    // "SL-7" printed on both a full invoice and a PAN invoice is
    // indistinguishable on paper and in the VAT book.
    $admin = PlatformAdmin::factory()->create();
    $tenant = provisionTenantSettingsTestTenant($this, $admin, 'settingsdupprefix');

    $payload = array_merge(baseCentralSettingsUpdatePayload(), ['sale_pan_prefix' => 'SL']);

    $this->actingAs($admin, 'platform')
        ->put(route('central.tenants.settings.update', $tenant), $payload)
        ->assertSessionHasErrors('sale_pan_prefix');

    $tenant->run(function () {
        expect(CompanySetting::current()->sale_pan_prefix)->toBe('SLP');
    });
});

test('a platform admin can upload a tenant\'s company logo', function () {
    Storage::fake('public');

    $admin = PlatformAdmin::factory()->create();
    $tenant = provisionTenantSettingsTestTenant($this, $admin, 'settingslogo');

    $response = $this->actingAs($admin, 'platform')->post(route('central.tenants.settings.logo', $tenant), [
        'logo' => UploadedFile::fake()->image('logo.jpg'),
    ]);

    $response->assertRedirect(route('central.tenants.settings.edit', $tenant));

    $tenant->run(function () use ($tenant) {
        $settings = CompanySetting::current();

        expect($settings->logo_path)->not->toBeNull();
        expect($settings->logo_path)->toStartWith('tenant-logos/'.$tenant->id.'/');

        Storage::disk('public')->assertExists($settings->logo_path);
    });
});

test('re-uploading a tenant\'s company logo deletes the old file', function () {
    Storage::fake('public');

    $admin = PlatformAdmin::factory()->create();
    $tenant = provisionTenantSettingsTestTenant($this, $admin, 'settingslogoreplace');

    $this->actingAs($admin, 'platform')->post(route('central.tenants.settings.logo', $tenant), [
        'logo' => UploadedFile::fake()->image('first.jpg'),
    ]);

    $originalPath = $tenant->run(fn () => CompanySetting::current()->logo_path);

    $response = $this->actingAs($admin, 'platform')->post(route('central.tenants.settings.logo', $tenant), [
        'logo' => UploadedFile::fake()->image('second.jpg'),
    ]);

    $response->assertRedirect(route('central.tenants.settings.edit', $tenant));

    $tenant->run(function () use ($originalPath) {
        $settings = CompanySetting::current();

        expect($settings->logo_path)->not->toBeNull();
        expect($settings->logo_path)->not->toBe($originalPath);

        Storage::disk('public')->assertExists($settings->logo_path);
        Storage::disk('public')->assertMissing($originalPath);
    });
});

test('a non-image file is rejected for the logo upload', function () {
    Storage::fake('public');

    $admin = PlatformAdmin::factory()->create();
    $tenant = provisionTenantSettingsTestTenant($this, $admin, 'settingslogononimage');

    $this->actingAs($admin, 'platform')->post(route('central.tenants.settings.logo', $tenant), [
        'logo' => UploadedFile::fake()->create('document.pdf', 100, 'application/pdf'),
    ])->assertSessionHasErrors('logo');
});

test('an oversized logo is rejected', function () {
    Storage::fake('public');

    $admin = PlatformAdmin::factory()->create();
    $tenant = provisionTenantSettingsTestTenant($this, $admin, 'settingslogooversized');

    $this->actingAs($admin, 'platform')->post(route('central.tenants.settings.logo', $tenant), [
        'logo' => UploadedFile::fake()->image('big.jpg')->size(3000),
    ])->assertSessionHasErrors('logo');
});

test('the settings page lists every numbered series with its next number', function () {
    $admin = PlatformAdmin::factory()->create();
    $tenant = provisionTenantSettingsTestTenant($this, $admin, 'settingsnumberingpanel');

    $tenant->run(function () {
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
    });

    $this->actingAs($admin, 'platform')
        ->get(route('central.tenants.settings.edit', $tenant))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('invoiceNumbering', 6)
            ->where('invoiceNumbering.0.voucher_type', VoucherType::Sale->value)
            ->where('invoiceNumbering.0.next_number', 1)
            ->where('invoiceNumbering.0.can_set', true)
        );
});

test('a platform admin can set a series starting number, and cannot once that series has issued a document', function () {
    $admin = PlatformAdmin::factory()->create();
    $tenant = provisionTenantSettingsTestTenant($this, $admin, 'settingsstartingnumber');

    $tenant->run(function () {
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
    });

    $this->actingAs($admin, 'platform')->post(route('central.tenants.settings.starting-number', $tenant), [
        'voucher_type' => VoucherType::Sale->value,
        'next_number' => 501,
    ])->assertRedirect(route('central.tenants.settings.edit', $tenant));

    $tenant->run(function () {
        $fiscalYear = FiscalYear::query()->firstOrFail();

        expect(VoucherSequence::nextNumberFor($fiscalYear, VoucherType::Sale))->toBe(501)
            ->and(VoucherSequence::nextNumberFor($fiscalYear, VoucherType::SalePan))->toBe(1);
    });

    // Issue one document in that series, then the setting locks.
    $tenant->run(function () {
        $tenantAdmin = User::where('email', 'admin@settingsstartingnumber.test')->firstOrFail();

        JournalVoucher::post(
            ['voucher_type' => VoucherType::Sale->value, 'date' => '2026-06-01', 'narration' => 'Sale'],
            [
                ['account_id' => Account::where('code', 'AS1')->value('id'), 'debit' => 100, 'credit' => 0],
                ['account_id' => Account::where('code', 'INI20')->value('id'), 'debit' => 0, 'credit' => 100],
            ],
            $tenantAdmin,
        );
    });

    $this->actingAs($admin, 'platform')->post(route('central.tenants.settings.starting-number', $tenant), [
        'voucher_type' => VoucherType::Sale->value,
        'next_number' => 900,
    ])->assertSessionHasErrors('next_number');

    $tenant->run(function () {
        $fiscalYear = FiscalYear::query()->firstOrFail();

        expect(VoucherSequence::nextNumberFor($fiscalYear, VoucherType::Sale))->toBe(502);
    });
});
