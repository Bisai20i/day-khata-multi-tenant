<?php

use App\Enums\FiscalYearStatus;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionActivityLogTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function activityLogTestAdmin(): User
{
    return User::factory()->create(['role_id' => Role::where('slug', 'admin')->value('id')]);
}

function activityLogTestOpenFiscalYear(): FiscalYear
{
    return FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
}

function loginAsActivityLogAdmin(string $domain, string $email = 'boss@example.com'): User
{
    $admin = null;

    tenancy()->initialize(Tenant::query()->whereHas('domains', fn ($q) => $q->where('domain', $domain))->firstOrFail());

    $adminRole = Role::query()->where('slug', 'admin')->firstOrFail();
    $admin = User::factory()->create([
        'email' => $email,
        'password' => 'password',
        'role_id' => $adminRole->id,
    ]);

    tenancy()->end();

    test()->post("http://{$domain}/login", [
        'email' => $email,
        'password' => 'password',
    ]);

    return $admin;
}

test('posting a sale writes an ActivityLog row, without touching Sale.php', function () {
    $tenant = provisionActivityLogTestTenant('activity-log-sale.tenant-test');

    $tenant->run(function () {
        activityLogTestOpenFiscalYear();
        $admin = activityLogTestAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => true]);

        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );

        $log = ActivityLog::where('subject_type', Sale::class)
            ->where('subject_id', $sale->id)
            ->where('action', 'created')
            ->first();

        expect($log)->not->toBeNull()
            ->and($log->description)->toBe("Sale #{$sale->id} created");
    });

    $tenant->delete();
});

test('a bare touch() does not write an ActivityLog row', function () {
    $tenant = provisionActivityLogTestTenant('activity-log-touch.tenant-test');

    $tenant->run(function () {
        activityLogTestOpenFiscalYear();
        $admin = activityLogTestAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => true]);

        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );

        $countBefore = ActivityLog::count();

        $sale->touch();

        expect(ActivityLog::count())->toBe($countBefore)
            ->and(ActivityLog::where('subject_type', Sale::class)->where('subject_id', $sale->id)->where('action', 'updated')->exists())
            ->toBeFalse();
    });

    $tenant->delete();
});

test('a real attribute update writes an ActivityLog row with only the changed attributes', function () {
    $tenant = provisionActivityLogTestTenant('activity-log-update.tenant-test');

    $tenant->run(function () {
        $adminRole = Role::query()->where('slug', 'admin')->firstOrFail();
        $user = User::factory()->create(['role_id' => $adminRole->id, 'name' => 'Original Name']);

        $user->name = 'Renamed';
        $user->save();

        $log = ActivityLog::where('subject_type', User::class)
            ->where('subject_id', $user->id)
            ->where('action', 'updated')
            ->first();

        expect($log)->not->toBeNull();
        expect($log->changes)->toHaveKey('name');
        expect($log->changes)->not->toHaveKey('updated_at');
    });

    $tenant->delete();
});

test('the activity log page is admin-only', function () {
    $domain = 'activity-log-admin-only.tenant-test';
    $tenant = provisionActivityLogTestTenant($domain);

    $staff = null;
    $tenant->run(function () use (&$staff) {
        $staffRole = Role::query()->where('slug', 'staff')->firstOrFail();
        $staff = User::factory()->create(['email' => 'staffer@example.com', 'role_id' => $staffRole->id]);
    });

    test()->post("http://{$domain}/login", ['email' => 'staffer@example.com', 'password' => 'password']);

    test()->get("http://{$domain}/activity-log")->assertForbidden();

    $tenant->delete();
});

test('an admin can view the activity log, with filtering and pagination', function () {
    $domain = 'activity-log-pagination.tenant-test';
    $tenant = provisionActivityLogTestTenant($domain);
    // Logging the admin in creates a User row of its own, which
    // ActivityLogObserver logs too - accounted for below rather than
    // ignored, so this test proves the observer fires end-to-end, not just
    // for the rows the test manually inserts.
    loginAsActivityLogAdmin($domain);

    $tenant->run(function () {
        for ($i = 1; $i <= 55; $i++) {
            ActivityLog::create([
                'user_id' => null,
                'action' => 'created',
                'subject_type' => Sale::class,
                'subject_id' => $i,
                'description' => "Sale #{$i} created",
                'changes' => null,
            ]);
        }

        ActivityLog::create([
            'user_id' => null,
            'action' => 'created',
            'subject_type' => User::class,
            'subject_id' => 999,
            'description' => 'User #999 created',
            'changes' => null,
        ]);
    });

    $response = test()->get("http://{$domain}/activity-log");
    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Tenant/Admin/ActivityLog/Index')
        // 55 manually-inserted Sale-type rows + 1 manually-inserted
        // User-type row + 1 real row from the admin's own account creation
        // above (proves the observer, not just this test's manual inserts).
        ->where('logs.total', 57)
        ->where('logs.last_page', 2)
        ->has('logs.data', 50)
    );

    $filtered = test()->get("http://{$domain}/activity-log?subject_type=".urlencode(User::class));
    $filtered->assertOk();
    $filtered->assertInertia(fn ($page) => $page
        ->where('logs.total', 2)
    );

    $tenant->delete();
});
