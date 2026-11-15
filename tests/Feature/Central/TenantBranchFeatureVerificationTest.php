<?php

use App\Models\Branch;
use App\Models\PlatformAdmin;
use App\Models\PlatformAdminActivityLog;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Branches\ActiveBranchSelection;
use App\Support\Branches\BranchFormOptions;
use App\Support\Branches\UserBranchAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

afterEach(function () {
    auth()->forgetGuards();
    tenancy()->end();
});

test('branch feature is controllable from central panel and strictly isolated to entitled tenants', function () {
    // 1. Central Admin creates a tenant WITHOUT the branches module
    $admin = PlatformAdmin::factory()->create();
    $domain = 'nobranch-'.Str::lower(Str::random(6)).'.tenant-test';

    // In config('permissions.default_modules'), branches is present by default in config,
    // but the central create form explicitly omits branches unless ticked.
    $modulesWithoutBranches = array_values(array_diff(config('permissions.default_modules', []), ['branches']));

    $this->actingAs($admin, 'platform')->post(route('central.tenants.store'), [
        'company_name' => 'Single Branch Co',
        'subdomain' => explode('.', $domain)[0],
        'contact_email' => 'admin@singlebranch.test',
        'admin_name' => 'Admin User',
        'admin_email' => 'admin@singlebranch.test',
        'admin_password' => 'password123',
        'enabled_modules' => $modulesWithoutBranches,
    ])->assertSessionHasNoErrors();

    $tenant = Tenant::where('company_name', 'Single Branch Co')->firstOrFail();

    // Verify tenant does not have branches module
    expect($tenant->hasModule('branches'))->toBeFalse()
        ->and($tenant->entitledModules())->not->toContain('branches');

    // Verify Central Show page renders module catalog and shows branches as unchecked
    $this->actingAs($admin, 'platform')
        ->get(route('central.tenants.show', $tenant))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Central/Tenants/Show')
            ->has('moduleCatalog')
            ->where('tenant.enabled_modules', fn ($modules) => !collect($modules)->contains('branches'))
        );

    // 2. Tenant side verification for tenant WITHOUT branches module
    Auth::guard('platform')->logout();
    $owner = $tenant->run(function () {
        $user = User::firstOrFail();
        auth()->login($user);

        // Check Gate for all branch abilities - owner included!
        expect(Gate::allows('branches.view'))->toBeFalse()
            ->and(Gate::allows('branches.manage'))->toBeFalse()
            ->and(Gate::allows('branches.report'))->toBeFalse()
            ->and(Gate::allows('stock_transfers.receive'))->toBeFalse();

        // Effective permissions does not include branches
        expect($user->effectivePermissions())->not->toHaveKey('branches.view')
            ->and($user->effectivePermissions())->not->toHaveKey('branches.manage')
            ->and($user->effectivePermissions())->not->toHaveKey('branches.report')
            ->and($user->effectivePermissions())->not->toHaveKey('stock_transfers.receive');

        // Feature flags and helper guards
        expect(ActiveBranchSelection::moduleEnabled())->toBeFalse()
            ->and(UserBranchAccess::enabled())->toBeFalse()
            ->and(ActiveBranchSelection::isMultiBranch(request()))->toBeFalse()
            ->and(BranchFormOptions::choices($user, request()))->toBeEmpty();

        return $user;
    });

    // Verify active-branch update endpoint is blocked (404) for tenant without branches module
    tenancy()->end();
    Auth::guard('platform')->logout();
    $fullDomain = $tenant->domains()->firstOrFail()->domain;

    $this->actingAs($owner, 'web')
        ->put("http://{$fullDomain}/active-branch", ['branch' => 1])
        ->assertNotFound();

    // Verify branches admin routes return 403 Forbidden
    $this->actingAs($owner, 'web')
        ->get("http://{$fullDomain}/branches")->assertForbidden();
    $this->actingAs($owner, 'web')
        ->get("http://{$fullDomain}/reports/branch-comparison")->assertForbidden();
    $this->actingAs($owner, 'web')
        ->get("http://{$fullDomain}/reports/inter-branch-balances")->assertForbidden();
    tenancy()->end();

    // 3. Central Admin enables the branches module for this tenant
    auth()->forgetGuards();
    $modulesWithBranches = [...$modulesWithoutBranches, 'branches'];

    $this->actingAs($admin, 'platform')
        ->put('http://localhost'.route('central.tenants.modules.update', $tenant, false), [
            'enabled_modules' => $modulesWithBranches,
        ])
        ->assertRedirect()
        ->assertSessionHas('status', 'Modules updated.');

    $tenant = $tenant->fresh();
    expect($tenant->hasModule('branches'))->toBeTrue()
        ->and(PlatformAdminActivityLog::where('action', 'tenant.module_branches_enabled')->where('tenant_id', $tenant->id)->exists())->toBeTrue();

    // 4. Tenant side verification for tenant WITH branches module
    Auth::guard('platform')->logout();
    tenancy()->initialize($tenant);
    $tenant->run(function () {
        $owner = User::firstOrFail();
        auth()->login($owner);

        // Owner now has branch permissions
        expect(Gate::allows('branches.view'))->toBeTrue()
            ->and(Gate::allows('branches.manage'))->toBeTrue()
            ->and(Gate::allows('branches.report'))->toBeTrue()
            ->and(Gate::allows('stock_transfers.receive'))->toBeTrue();

        expect(ActiveBranchSelection::moduleEnabled())->toBeTrue()
            ->and(UserBranchAccess::enabled())->toBeTrue();
    });

    // Now owner can access branches.index
    tenancy()->end();
    Auth::guard('platform')->logout();
    $this->actingAs($owner, 'web')
        ->get("http://{$fullDomain}/branches")
        ->assertOk();

    // 5. Test Central Panel BranchModuleGuard preventing disabling branches when tenant has multiple branches
    $tenant->run(function () {
        Branch::create([
            'name' => 'Pokhara Branch',
            'code' => 'PKR',
            'is_main' => false,
            'is_active' => true,
        ]);
        expect(Branch::count())->toBe(2);
    });

    tenancy()->end();
    auth()->forgetGuards();

    // Central admin attempts to switch off branches while company has 2 branches
    $this->actingAs($admin, 'platform')
        ->put('http://localhost'.route('central.tenants.modules.update', $tenant, false), [
            'enabled_modules' => $modulesWithoutBranches,
        ])
        ->assertSessionHasErrors(['enabled_modules']);

    // Module remains enabled due to guard
    expect($tenant->fresh()->hasModule('branches'))->toBeTrue();

    // 6. Delete the extra branch so only Main remains, then switch off successfully
    tenancy()->initialize($tenant);
    $tenant->run(function () {
        Branch::where('code', 'PKR')->delete();
        expect(Branch::count())->toBe(1);
    });
    tenancy()->end();
    auth()->forgetGuards();

    $this->actingAs($admin, 'platform')
        ->put('http://localhost'.route('central.tenants.modules.update', $tenant, false), [
            'enabled_modules' => $modulesWithoutBranches,
        ])
        ->assertRedirect()
        ->assertSessionHas('status', 'Modules updated.');

    expect($tenant->fresh()->hasModule('branches'))->toBeFalse()
        ->and(PlatformAdminActivityLog::where('action', 'tenant.module_branches_disabled')->where('tenant_id', $tenant->id)->exists())->toBeTrue();

    $tenant->delete();
});
