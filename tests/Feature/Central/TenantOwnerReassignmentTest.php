<?php

use App\Models\PlatformAdmin;
use App\Models\PlatformAdminActivityLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Stancl\Tenancy\Events\TenantCreated;

uses(RefreshDatabase::class);

/**
 * Tenant routes leave tenancy initialized in some tests, so revert to the
 * central connection for RefreshDatabase's teardown.
 */
afterEach(function () {
    tenancy()->end();
});

/**
 * A provisioned tenant (own database) with an owner and two more users: one
 * active and one inactive. Tenant users are wiped first so the counts below
 * do not depend on what provisioning seeds. The caller deletes the tenant at
 * the end (drops its database).
 *
 * @return array{0: Tenant, 1: User, 2: User, 3: User}
 */
function ownerReassignTenant(): array
{
    $tenant = Tenant::create(['company_name' => 'Owner Swap Co']);
    $tenant->domains()->create(['domain' => 'owner-'.Str::lower(Str::random(8)).'.tenant-test']);

    return $tenant->run(function () use ($tenant): array {
        User::query()->delete();

        $owner = User::factory()->create(['name' => 'Old Owner']);
        $active = User::factory()->create(['is_owner' => false, 'name' => 'Active Person']);
        $inactive = User::factory()->create(['is_owner' => false, 'is_active' => false, 'name' => 'Gone Person']);

        return [$tenant, $owner, $active, $inactive];
    });
}

/**
 * Absolute central URL: route() reuses the last request's host, which would
 * send the POST to a tenant domain after any tenant request.
 */
function ownerReassignUrl(Tenant $tenant): string
{
    return 'http://localhost'.route('central.tenants.owner.update', $tenant, false);
}

function ownerIds(Tenant $tenant): array
{
    return $tenant->run(fn () => User::where('is_owner', true)->orderBy('id')->pluck('id')->all());
}

test('a guest is redirected to login and nothing changes', function () {
    [$tenant, $owner, $active] = ownerReassignTenant();

    $this->post(ownerReassignUrl($tenant), ['user_id' => $active->id])
        ->assertRedirect(route('login'));

    expect(ownerIds($tenant))->toBe([$owner->id]);

    $tenant->delete();
});

test('a support admin cannot reassign the owner', function () {
    [$tenant, $owner, $active] = ownerReassignTenant();
    $support = PlatformAdmin::factory()->support()->create();

    $this->actingAs($support, 'platform')
        ->post(ownerReassignUrl($tenant), ['user_id' => $active->id])
        ->assertForbidden();

    expect(ownerIds($tenant))->toBe([$owner->id])
        ->and(PlatformAdminActivityLog::where('action', 'tenant.reassign_owner')->exists())->toBeFalse();

    $tenant->delete();
});

test('a platform owner moves ownership and exactly one owner remains', function () {
    [$tenant, $owner, $active] = ownerReassignTenant();
    $admin = PlatformAdmin::factory()->create();

    $this->actingAs($admin, 'platform')
        ->post(ownerReassignUrl($tenant), ['user_id' => $active->id])
        ->assertRedirect(route('central.tenants.show', $tenant))
        ->assertSessionHas('status', 'Active Person is now the owner.');

    expect(ownerIds($tenant))->toBe([$active->id]);

    $tenant->delete();
});

test('an inactive target is rejected with 422 and nothing changes', function () {
    [$tenant, $owner, , $inactive] = ownerReassignTenant();
    $admin = PlatformAdmin::factory()->create();

    $this->actingAs($admin, 'platform')
        ->postJson(ownerReassignUrl($tenant), ['user_id' => $inactive->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors('user_id');

    expect(ownerIds($tenant))->toBe([$owner->id])
        ->and(PlatformAdminActivityLog::where('action', 'tenant.reassign_owner')->exists())->toBeFalse();

    $tenant->delete();
});

test('an unknown user id is rejected with 422', function () {
    [$tenant, $owner] = ownerReassignTenant();
    $admin = PlatformAdmin::factory()->create();

    $this->actingAs($admin, 'platform')
        ->postJson(ownerReassignUrl($tenant), ['user_id' => 999999])
        ->assertStatus(422)
        ->assertJsonValidationErrors('user_id');

    expect(ownerIds($tenant))->toBe([$owner->id]);

    $tenant->delete();
});

test('a missing or non-integer user_id is rejected with 422', function (mixed $payload) {
    [$tenant, $owner] = ownerReassignTenant();
    $admin = PlatformAdmin::factory()->create();

    $this->actingAs($admin, 'platform')
        ->postJson(ownerReassignUrl($tenant), $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors('user_id');

    expect(ownerIds($tenant))->toBe([$owner->id]);

    $tenant->delete();
})->with([
    'missing' => [[]],
    'not an integer' => [['user_id' => 'abc']],
]);

test('choosing the current owner is rejected as already the owner', function () {
    [$tenant, $owner] = ownerReassignTenant();
    $admin = PlatformAdmin::factory()->create();

    $this->actingAs($admin, 'platform')
        ->postJson(ownerReassignUrl($tenant), ['user_id' => $owner->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors('user_id');

    expect(ownerIds($tenant))->toBe([$owner->id])
        ->and(PlatformAdminActivityLog::where('action', 'tenant.reassign_owner')->exists())->toBeFalse();

    $tenant->delete();
});

test('a tenant with no owner gets one', function () {
    [$tenant, $owner, $active] = ownerReassignTenant();
    $tenant->run(fn () => User::whereKey($owner->id)->update(['is_owner' => false]));
    $admin = PlatformAdmin::factory()->create();

    $this->actingAs($admin, 'platform')
        ->post(ownerReassignUrl($tenant), ['user_id' => $active->id])
        ->assertRedirect(route('central.tenants.show', $tenant));

    expect(ownerIds($tenant))->toBe([$active->id]);

    $entry = PlatformAdminActivityLog::where('action', 'tenant.reassign_owner')->sole();

    expect($entry->metadata['before'])->toBeNull()
        ->and($entry->metadata['after']['id'])->toBe($active->id);

    $tenant->delete();
});

test('a reassignment writes a central activity entry with before and after', function () {
    [$tenant, $owner, $active] = ownerReassignTenant();
    $admin = PlatformAdmin::factory()->create();

    $this->actingAs($admin, 'platform')
        ->post(ownerReassignUrl($tenant), ['user_id' => $active->id]);

    $entry = PlatformAdminActivityLog::where('action', 'tenant.reassign_owner')
        ->where('tenant_id', $tenant->id)
        ->where('platform_admin_id', $admin->id)
        ->sole();

    expect($entry->metadata)->toBe([
        'before' => ['id' => $owner->id, 'name' => 'Old Owner', 'email' => $owner->email],
        'after' => ['id' => $active->id, 'name' => 'Active Person', 'email' => $active->email],
    ]);

    $tenant->delete();
});

test('the show page exposes the owner and the active candidates only', function () {
    [$tenant, $owner, $active, $inactive] = ownerReassignTenant();
    $admin = PlatformAdmin::factory()->create();

    $this->actingAs($admin, 'platform')
        ->get('http://localhost'.route('central.tenants.show', $tenant, false))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Central/Tenants/Show')
            ->where('owner.id', $owner->id)
            ->where('owner.email', $owner->email)
            ->has('ownerCandidates', 2)
            ->where('ownerCandidates', fn ($candidates) => collect($candidates)->pluck('id')->sort()->values()->all() === [$owner->id, $active->id]
                && ! collect($candidates)->pluck('id')->contains($inactive->id)));

    $tenant->delete();
});

test('the show page still renders for a tenant whose database does not exist', function () {
    Event::fake([TenantCreated::class]);
    $tenant = Tenant::create(['company_name' => 'No Database Co']);
    $admin = PlatformAdmin::factory()->create();

    $this->actingAs($admin, 'platform')
        ->get('http://localhost'.route('central.tenants.show', $tenant, false))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Central/Tenants/Show')
            ->where('owner', null)
            ->where('ownerCandidates', []));
});
