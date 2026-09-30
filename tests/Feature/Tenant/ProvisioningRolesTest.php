<?php

use App\Models\PlatformAdmin;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Permissions\PermissionCatalog;
use App\Support\Permissions\RoleTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
    Tenant::all()->each(fn (Tenant $tenant) => $tenant->delete());
});

/**
 * Provision through the real central endpoint, so the enabled_modules
 * selection reaches the tenant before the seeder runs, exactly as in
 * production. The sync queue runs the whole TenantCreated pipeline inline.
 *
 * @param  array<int, string>  $modules
 */
function provisionRolesTenant(string $subdomain, array $modules): Tenant
{
    Mail::fake();

    test()->actingAs(PlatformAdmin::factory()->create(), 'platform')
        ->post(route('central.tenants.store'), [
            'company_name' => 'Roles '.$subdomain,
            'subdomain' => $subdomain,
            'contact_email' => "billing@{$subdomain}.test",
            'admin_name' => 'First Admin',
            'admin_email' => "admin@{$subdomain}.test",
            'admin_password' => 'password123',
            'enabled_modules' => $modules,
        ]);

    return Tenant::where('company_name', 'Roles '.$subdomain)->firstOrFail();
}

test('manager and cashier are seeded without keys from modules the tenant lacks, admin keeps every grantable key', function () {
    $tenant = provisionRolesTenant('rolesubset', ['sales', 'reports']);

    expect($tenant->entitledModules())->not->toContain('purchases')->not->toContain('pos');

    $tenant->run(function () use ($tenant) {
        $entitled = $tenant->entitledModules();
        $manager = Role::where('slug', 'manager')->firstOrFail()->permissions;
        $cashier = Role::where('slug', 'cashier')->firstOrFail()->permissions;
        $admin = Role::where('slug', 'admin')->firstOrFail();

        foreach ([$manager, $cashier] as $keys) {
            foreach ($keys as $key) {
                expect($entitled)->toContain(PermissionCatalog::moduleOf($key));
            }
        }

        expect($manager)->not->toContain('purchases.view')
            ->and($manager)->not->toContain('pos.view')
            ->and($manager)->toContain('sales.create')
            ->and($manager)->toContain('sales_reports.view')
            ->and($cashier)->not->toContain('pos.view')
            ->and($cashier)->toContain('sales.create')
            ->and($manager)->toBe(RoleTemplates::forModules(RoleTemplates::MANAGER, $entitled))
            ->and($cashier)->toBe(RoleTemplates::forModules(RoleTemplates::CASHIER, $entitled))
            ->and($admin->permissions)->toBe(PermissionCatalog::grantable())
            ->and($admin->is_system)->toBeTrue();
    });
});

test('the first admin is the only owner and holds the admin role', function () {
    $tenant = provisionRolesTenant('roleowner', ['sales']);

    $tenant->run(function () {
        $owners = User::where('is_owner', true)->get();

        expect(User::count())->toBe(1)
            ->and($owners)->toHaveCount(1)
            ->and($owners->first()->email)->toBe('admin@roleowner.test')
            ->and($owners->first()->role->slug)->toBe('admin');
    });
});

test('new tenants have no staff role and no placeholder permission rows', function () {
    $tenant = provisionRolesTenant('rolenostaff', ['sales']);

    $tenant->run(function () {
        expect(Role::where('slug', 'staff')->exists())->toBeFalse()
            ->and(Role::pluck('slug')->sort()->values()->all())->toBe(['admin', 'cashier', 'manager'])
            ->and(Schema::hasTable('permissions'))->toBeFalse()
            ->and(Schema::hasTable('permission_role'))->toBeFalse();
    });
});

test('templates hold only real grantable keys and no owner-only keys', function () {
    foreach ([RoleTemplates::CASHIER, RoleTemplates::MANAGER] as $template) {
        expect($template)->toBe(array_values(array_unique($template)));

        foreach ($template as $key) {
            expect(PermissionCatalog::has($key))->toBeTrue("unknown key {$key}")
                ->and(PermissionCatalog::isOwnerOnly($key))->toBeFalse("owner-only key {$key}");
        }
    }

    expect(RoleTemplates::MANAGER)->not->toContain('users.manage')
        ->and(RoleTemplates::MANAGER)->not->toContain('activity_log.view')
        ->and(RoleTemplates::MANAGER)->not->toContain('print_log.view')
        ->and(RoleTemplates::CASHIER)->not->toContain('users.manage');
});

test('every non-view key in a template comes with its resource view key', function () {
    // Keys with no `.view` of their own that ride on another resource's view.
    $viewKeyOverrides = [
        'cash_bank_vouchers' => 'journal_vouchers.view',
        'sales_return_requests' => 'sales_returns.view',
        'unlinked_sales_returns' => 'sales_returns.view',
        'unlinked_purchase_returns' => 'purchase_returns.view',
        'capital_purchase_settlements' => 'capital_purchases.view',
        'opening_balances' => 'accounts.view',
        'opening_stock' => 'stock_adjustments.view',
    ];

    foreach ([RoleTemplates::CASHIER, RoleTemplates::MANAGER] as $template) {
        foreach ($template as $key) {
            [$resource, $action] = explode('.', $key, 2);

            if ($action === 'view') {
                continue;
            }

            $viewKey = $viewKeyOverrides[$resource] ?? "{$resource}.view";

            if (! isset($viewKeyOverrides[$resource]) && ! PermissionCatalog::has($viewKey)) {
                continue;
            }

            expect(in_array($viewKey, $template, true))->toBeTrue("{$key} needs {$viewKey}");
        }
    }
});

test('the cashier template stays a counter role', function () {
    expect(RoleTemplates::CASHIER)->toContain('pos.view')
        ->toContain('sales.create')
        ->toContain('items.view')
        ->toContain('customers.create');

    foreach (RoleTemplates::CASHIER as $key) {
        expect($key)->not->toEndWith('.cancel')
            ->and($key)->not->toEndWith('.export')
            ->and($key)->not->toStartWith('purchases.')
            ->and(PermissionCatalog::moduleOf($key))->not->toBe('reports');
    }
});

test('forModules drops keys of unentitled modules and owner-only keys', function () {
    $filtered = RoleTemplates::forModules(
        ['sales.view', 'pos.view', 'purchases.view', 'roles.manage', 'not.a.key'],
        ['core', 'sales'],
    );

    expect($filtered)->toBe(['sales.view']);
});
