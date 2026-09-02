<?php

use App\Models\Notice;
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

function provisionNoticeTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function loginAsNoticeAdmin(string $domain): User
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

function loginAsNoticeStaff(string $domain): User
{
    $staff = null;

    tenancy()->initialize(Tenant::query()->whereHas('domains', fn ($q) => $q->where('domain', $domain))->firstOrFail());

    $staffRole = Role::query()->where('slug', 'staff')->firstOrFail();
    $staff = User::factory()->create([
        'email' => 'staffer@example.com',
        'password' => 'password',
        'role_id' => $staffRole->id,
    ]);

    tenancy()->end();

    test()->post("http://{$domain}/login", [
        'email' => 'staffer@example.com',
        'password' => 'password',
    ]);

    return $staff;
}

test('an admin can create, update, and delete a notice', function () {
    $domain = 'notice-crud.tenant-test';
    $tenant = provisionNoticeTestTenant($domain);
    loginAsNoticeAdmin($domain);

    $response = test()->post("http://{$domain}/notices", [
        'title' => 'Holiday hours',
        'body' => 'We will be closed on Friday.',
        'is_active' => true,
    ]);

    $response->assertRedirect();

    $noticeId = null;
    $tenant->run(function () use (&$noticeId) {
        $notice = Notice::where('title', 'Holiday hours')->firstOrFail();
        $noticeId = $notice->id;
        expect($notice->body)->toBe('We will be closed on Friday.')
            ->and($notice->is_active)->toBeTrue()
            ->and($notice->created_by)->not->toBeNull();
    });

    $updateResponse = test()->put("http://{$domain}/notices/{$noticeId}", [
        'title' => 'Holiday hours (updated)',
        'body' => 'We will be closed on Friday and Saturday.',
        'is_active' => false,
    ]);

    $updateResponse->assertRedirect();

    $tenant->run(function () use ($noticeId) {
        $notice = Notice::findOrFail($noticeId);
        expect($notice->title)->toBe('Holiday hours (updated)')
            ->and($notice->is_active)->toBeFalse();
    });

    $deleteResponse = test()->delete("http://{$domain}/notices/{$noticeId}");
    $deleteResponse->assertRedirect();

    $tenant->run(function () use ($noticeId) {
        expect(Notice::find($noticeId))->toBeNull();
    });

    $tenant->delete();
});

test('an admin cannot set ends_at before starts_at', function () {
    $domain = 'notice-date-validation.tenant-test';
    $tenant = provisionNoticeTestTenant($domain);
    loginAsNoticeAdmin($domain);

    $response = test()->post("http://{$domain}/notices", [
        'title' => 'Bad window',
        'body' => 'Body text',
        'starts_at' => '2026-09-10',
        'ends_at' => '2026-09-01',
        'is_active' => true,
    ]);

    $response->assertSessionHasErrors('ends_at');

    $tenant->run(function () {
        expect(Notice::count())->toBe(0);
    });

    $tenant->delete();
});

test('a non-admin is forbidden from every notice CRUD action', function () {
    $domain = 'notice-staff.tenant-test';
    $tenant = provisionNoticeTestTenant($domain);
    loginAsNoticeStaff($domain);

    $noticeId = null;
    $tenant->run(function () use (&$noticeId) {
        $admin = User::factory()->create(['role_id' => Role::where('slug', 'admin')->firstOrFail()->id]);
        $noticeId = Notice::create([
            'title' => 'Existing notice',
            'body' => 'Body',
            'is_active' => true,
            'created_by' => $admin->id,
        ])->id;
    });

    test()->get("http://{$domain}/notices")->assertForbidden();
    test()->post("http://{$domain}/notices", ['title' => 'x', 'body' => 'y'])->assertForbidden();
    test()->put("http://{$domain}/notices/{$noticeId}", ['title' => 'x', 'body' => 'y'])->assertForbidden();
    test()->delete("http://{$domain}/notices/{$noticeId}")->assertForbidden();

    $tenant->delete();
});

test('the currentlyActive scope includes and excludes notices by date window and is_active', function () {
    $domain = 'notice-scope.tenant-test';
    $tenant = provisionNoticeTestTenant($domain);

    $tenant->run(function () {
        $admin = User::factory()->create();
        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();
        $tomorrow = now()->addDay()->toDateString();

        $alwaysOn = Notice::create(['title' => 'Always on', 'body' => 'b', 'is_active' => true, 'created_by' => $admin->id]);
        $withinWindow = Notice::create(['title' => 'Within window', 'body' => 'b', 'starts_at' => $yesterday, 'ends_at' => $tomorrow, 'is_active' => true, 'created_by' => $admin->id]);
        $startsToday = Notice::create(['title' => 'Starts today', 'body' => 'b', 'starts_at' => $today, 'is_active' => true, 'created_by' => $admin->id]);
        $endsToday = Notice::create(['title' => 'Ends today', 'body' => 'b', 'ends_at' => $today, 'is_active' => true, 'created_by' => $admin->id]);

        $notYetStarted = Notice::create(['title' => 'Not yet started', 'body' => 'b', 'starts_at' => $tomorrow, 'is_active' => true, 'created_by' => $admin->id]);
        $alreadyEnded = Notice::create(['title' => 'Already ended', 'body' => 'b', 'ends_at' => $yesterday, 'is_active' => true, 'created_by' => $admin->id]);
        $inactive = Notice::create(['title' => 'Turned off', 'body' => 'b', 'is_active' => false, 'created_by' => $admin->id]);

        $activeIds = Notice::currentlyActive()->pluck('id')->all();

        expect($activeIds)->toContain($alwaysOn->id, $withinWindow->id, $startsToday->id, $endsToday->id)
            ->and($activeIds)->not->toContain($notYetStarted->id, $alreadyEnded->id, $inactive->id);
    });

    $tenant->delete();
});

test('the dashboard only receives currently-active notices', function () {
    $domain = 'notice-dashboard.tenant-test';
    $tenant = provisionNoticeTestTenant($domain);
    $admin = loginAsNoticeAdmin($domain);

    $tenant->run(function () use ($admin) {
        Notice::create(['title' => 'Visible now', 'body' => 'b', 'is_active' => true, 'created_by' => $admin->id]);
        Notice::create(['title' => 'Turned off', 'body' => 'b', 'is_active' => false, 'created_by' => $admin->id]);
        Notice::create(['title' => 'Expired', 'body' => 'b', 'ends_at' => now()->subDay()->toDateString(), 'is_active' => true, 'created_by' => $admin->id]);
        Notice::create(['title' => 'Not started yet', 'body' => 'b', 'starts_at' => now()->addDay()->toDateString(), 'is_active' => true, 'created_by' => $admin->id]);
    });

    $response = test()->get("http://{$domain}/dashboard");

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page->component('Tenant/Dashboard')
        ->has('notices', 1)
        ->where('notices.0.title', 'Visible now')
    );

    $tenant->delete();
});
