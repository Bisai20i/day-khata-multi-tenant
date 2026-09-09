<?php

use App\Enums\TenantStatus;
use App\Jobs\CreateTenantFirstAdmin;
use App\Models\PlatformAdmin;
use App\Models\PlatformAdminActivityLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Queue;
use Stancl\JobPipeline\JobPipeline;
use Stancl\Tenancy\Events\TenantCreated;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Jobs\MigrateDatabase;
use Stancl\Tenancy\Jobs\SeedDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    Tenant::all()->each(fn (Tenant $tenant) => $tenant->delete());
});

/**
 * A tenant that never got provisioned - Queue::fake() stops the
 * TenantCreated job pipeline (CreateDatabase/MigrateDatabase/SeedDatabase/
 * CreateTenantFirstAdmin) from ever running, leaving status genuinely stuck
 * at Provisioning, same technique TenantProvisioningTest's "still-cooking"
 * test already uses. No factory exists for App\Models\Tenant (a
 * stancl/tenancy base model) - constructing directly is this suite's own
 * established convention (see TenantSuspensionTest/GracePeriodTest).
 */
function stuckProvisioningTenant(string $companyName): Tenant
{
    Queue::fake();

    $tenant = new Tenant(['company_name' => $companyName, 'status' => TenantStatus::Provisioning]);
    $tenant->save();

    return $tenant->fresh();
}

/**
 * Builds a JobFailed event whose payload matches what the "sync"/"database"
 * queue connections actually produce for the TenantCreated JobPipeline (see
 * Illuminate\Queue\Queue::createObjectPayload()) - only the two keys
 * App\Listeners\RecordProvisioningFailure reads (displayName, data.command)
 * need to be faithful, since the rest of the queue's own payload-building
 * behavior isn't what this test is verifying.
 */
function fakeProvisioningPipelineFailure(Tenant $tenant, string $message): JobFailed
{
    $pipeline = JobPipeline::make([
        CreateDatabase::class,
        MigrateDatabase::class,
        SeedDatabase::class,
        CreateTenantFirstAdmin::class,
    ])->send(fn (TenantCreated $event) => $event->tenant)->shouldBeQueued(true);

    $executable = $pipeline->executable([new TenantCreated($tenant)]);

    $payload = json_encode([
        'displayName' => JobPipeline::class,
        'job' => 'Illuminate\Queue\CallQueuedHandler@call',
        'data' => [
            'commandName' => JobPipeline::class,
            'command' => serialize($executable),
        ],
    ]);

    $job = new SyncJob(app(), $payload, 'sync', 'default');

    return new JobFailed('sync', $job, new RuntimeException($message));
}

test('a failed tenant-creation pipeline records a provisioning.failed activity log entry with the tenant and error message', function () {
    $tenant = stuckProvisioningTenant('Failure Co');

    event(fakeProvisioningPipelineFailure($tenant, 'Migration failed: table already exists'));

    $entry = PlatformAdminActivityLog::where('tenant_id', $tenant->id)
        ->where('action', 'provisioning.failed')
        ->first();

    expect($entry)->not->toBeNull()
        ->and($entry->metadata['error'])->toBe('Migration failed: table already exists')
        ->and($entry->platform_admin_id)->toBeNull();
});

test('a failed pipeline for an unrelated job is ignored', function () {
    $payload = json_encode([
        'displayName' => 'App\\Jobs\\SomeOtherJob',
        'job' => 'Illuminate\Queue\CallQueuedHandler@call',
        'data' => ['commandName' => 'App\\Jobs\\SomeOtherJob', 'command' => serialize((object) [])],
    ]);

    $job = new SyncJob(app(), $payload, 'sync', 'default');

    event(new JobFailed('sync', $job, new RuntimeException('irrelevant')));

    expect(PlatformAdminActivityLog::where('action', 'provisioning.failed')->exists())->toBeFalse();
});

test('the tenant show page surfaces the most recent provisioning failure while still provisioning', function () {
    $admin = PlatformAdmin::factory()->create();
    $tenant = stuckProvisioningTenant('Latest Failure Co');

    PlatformAdminActivityLog::record('provisioning.failed', $tenant, ['error' => 'Older failure']);
    PlatformAdminActivityLog::record('provisioning.failed', $tenant, ['error' => 'Latest failure']);

    $this->actingAs($admin, 'platform')
        ->get(route('central.tenants.show', $tenant))
        ->assertInertia(fn ($page) => $page->where('tenant.provisioning_error', 'Latest failure'));
});

test('an active tenant does not surface a stale provisioning failure', function () {
    $admin = PlatformAdmin::factory()->create();

    $tenant = new Tenant(['company_name' => 'Resolved Co', 'status' => TenantStatus::Provisioning]);
    $tenant->save();
    $tenant->domains()->create(['domain' => 'resolvedco.localhost']);

    PlatformAdminActivityLog::record('provisioning.failed', $tenant, ['error' => 'Old failure, now resolved']);

    $this->actingAs($admin, 'platform')
        ->get(route('central.tenants.show', $tenant))
        ->assertInertia(fn ($page) => $page->where('tenant.provisioning_error', null));
});

test('retrying provisioning re-dispatches the tenant-creation job pipeline for a still-provisioning tenant', function () {
    $admin = PlatformAdmin::factory()->create();
    $tenant = stuckProvisioningTenant('Retry Co');

    $this->actingAs($admin, 'platform')
        ->post(route('central.tenants.retry-provisioning', $tenant))
        ->assertRedirect(route('central.tenants.show', $tenant));

    // TenantProvisioningTest already proves the real pipeline works
    // end-to-end; this proves retryProvisioning() is the thing that
    // re-fires it, without actually re-running it twice in this test.
    Queue::assertPushed(JobPipeline::class);

    expect(PlatformAdminActivityLog::where('tenant_id', $tenant->id)
        ->where('action', 'tenant.retry_provisioning')
        ->where('platform_admin_id', $admin->id)
        ->exists())->toBeTrue();
});

test('retrying provisioning is a no-op for a tenant that already has a real database', function () {
    $admin = PlatformAdmin::factory()->create();

    $tenant = new Tenant(['company_name' => 'Already Active Co', 'status' => TenantStatus::Provisioning]);
    $tenant->save();
    $tenant->domains()->create(['domain' => 'alreadyactiveco.localhost']);

    expect($tenant->fresh()->status)->toBe(TenantStatus::Active);
    expect($tenant->fresh()->databaseExists())->toBeTrue();

    $this->actingAs($admin, 'platform')
        ->post(route('central.tenants.retry-provisioning', $tenant))
        ->assertRedirect(route('central.tenants.show', $tenant))
        ->assertSessionHas('status', 'This tenant already has a database - nothing to retry.');

    expect(PlatformAdminActivityLog::where('tenant_id', $tenant->id)
        ->where('action', 'tenant.retry_provisioning')
        ->exists())->toBeFalse();
});

test('retrying provisioning succeeds for a tenant marked active whose database is actually missing', function () {
    $admin = PlatformAdmin::factory()->create();

    // Reproduces the real bug report: a tenant whose status says Active
    // (claiming the pipeline finished) but whose database doesn't actually
    // exist. Built by provisioning for real (so the retry below runs
    // against a genuinely un-faked queue) and then deleting the database
    // file and restoring pending_admin afterward - not Queue::fake(), which
    // would still be in effect for this test's own later retry call and
    // prevent it from actually running.
    $this->actingAs($admin, 'platform')->post(route('central.tenants.store'), [
        'company_name' => 'Stale Active Co',
        'subdomain' => 'staleactiveco',
        'contact_email' => null,
        'admin_name' => 'Stale Admin',
        'admin_email' => 'admin@staleactiveco.test',
        'admin_password' => 'password123',
    ]);
    $tenant = Tenant::where('company_name', 'Stale Active Co')->firstOrFail();

    expect($tenant->databaseExists())->toBeTrue();

    $tenant->database()->manager()->deleteDatabase($tenant);

    // pending_admin isn't in Tenant's #[Fillable(...)] list (see Tenant.php)
    // - update() would silently drop it, the same mass-assignment trap this
    // suite has hit before. Direct property assignment + save() bypasses it,
    // same as TenantController::store() itself does.
    $tenant->pending_admin = [
        'name' => 'Stale Admin',
        'email' => 'admin@staleactiveco.test',
        'password' => bcrypt('password123'),
        'domain' => 'staleactiveco.localhost',
    ];
    $tenant->save();

    expect($tenant->fresh()->databaseExists())->toBeFalse();

    $this->actingAs($admin, 'platform')
        ->post(route('central.tenants.retry-provisioning', $tenant))
        ->assertRedirect(route('central.tenants.show', $tenant));

    $fresh = $tenant->fresh();
    expect($fresh->databaseExists())->toBeTrue()
        ->and($fresh->status)->toBe(TenantStatus::Active)
        ->and($fresh->pending_admin)->toBeNull();

    $adminExists = $fresh->run(fn () => User::where('email', 'admin@staleactiveco.test')->exists());
    expect($adminExists)->toBeTrue();
});

test('the tenant show page flags a database_missing tenant independently of its status', function () {
    $admin = PlatformAdmin::factory()->create();
    $tenant = stuckProvisioningTenant('Missing Db Co');
    $tenant->update(['status' => TenantStatus::Active]);
    $tenant->domains()->create(['domain' => 'missingdbco.localhost']);

    $this->actingAs($admin, 'platform')
        ->get(route('central.tenants.show', $tenant))
        ->assertInertia(fn ($page) => $page->where('tenant.database_missing', true));
});

test('the tenant show page does not flag database_missing for a genuinely provisioned tenant', function () {
    $admin = PlatformAdmin::factory()->create();
    $tenant = new Tenant(['company_name' => 'Really Fine Co', 'status' => TenantStatus::Provisioning]);
    $tenant->save();
    $tenant->domains()->create(['domain' => 'reallyfineco.localhost']);

    expect($tenant->fresh()->databaseExists())->toBeTrue();

    $this->actingAs($admin, 'platform')
        ->get(route('central.tenants.show', $tenant))
        ->assertInertia(fn ($page) => $page->where('tenant.database_missing', false));
});

test('guests cannot retry provisioning', function () {
    $tenant = stuckProvisioningTenant('Guest Blocked Co');

    $this->post(route('central.tenants.retry-provisioning', $tenant))
        ->assertRedirect(route('login'));
});

test('an owner can force a stuck-provisioning tenant to active', function () {
    $owner = PlatformAdmin::factory()->create();
    $tenant = stuckProvisioningTenant('Force Active Co');

    $this->actingAs($owner, 'platform')
        ->post(route('central.tenants.force-active', $tenant))
        ->assertRedirect(route('central.tenants.show', $tenant));

    expect($tenant->fresh()->status)->toBe(TenantStatus::Active);

    expect(PlatformAdminActivityLog::where('tenant_id', $tenant->id)
        ->where('action', 'tenant.force_active')
        ->where('platform_admin_id', $owner->id)
        ->exists())->toBeTrue();
});

test('forcing active is a no-op for a tenant that is not provisioning', function () {
    $owner = PlatformAdmin::factory()->create();

    $tenant = new Tenant(['company_name' => 'Not Stuck Co', 'status' => TenantStatus::Provisioning]);
    $tenant->save();
    $tenant->domains()->create(['domain' => 'notstuckco.localhost']);

    expect($tenant->fresh()->status)->toBe(TenantStatus::Active);

    $this->actingAs($owner, 'platform')
        ->post(route('central.tenants.force-active', $tenant))
        ->assertRedirect(route('central.tenants.show', $tenant))
        ->assertSessionHas('status', 'Only a still-provisioning tenant can be forced active.');

    expect(PlatformAdminActivityLog::where('tenant_id', $tenant->id)
        ->where('action', 'tenant.force_active')
        ->exists())->toBeFalse();
});

test('a support admin cannot force a tenant active', function () {
    $support = PlatformAdmin::factory()->support()->create();
    $tenant = stuckProvisioningTenant('Support Blocked Co');

    $this->actingAs($support, 'platform')
        ->post(route('central.tenants.force-active', $tenant))
        ->assertForbidden();

    expect($tenant->fresh()->status)->toBe(TenantStatus::Provisioning);
});

test('guests cannot force a tenant active', function () {
    $tenant = stuckProvisioningTenant('Guest Blocked Force Co');

    $this->post(route('central.tenants.force-active', $tenant))
        ->assertRedirect(route('login'));
});
