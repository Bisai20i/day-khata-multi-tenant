<?php

use App\Enums\TenantStatus;
use App\Jobs\CreateTenantFirstAdmin;
use App\Models\PlatformAdmin;
use App\Models\PlatformAdminActivityLog;
use App\Models\Tenant;
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

test('retrying provisioning is a no-op for a tenant that is not provisioning', function () {
    $admin = PlatformAdmin::factory()->create();

    $tenant = new Tenant(['company_name' => 'Already Active Co', 'status' => TenantStatus::Provisioning]);
    $tenant->save();
    $tenant->domains()->create(['domain' => 'alreadyactiveco.localhost']);

    expect($tenant->fresh()->status)->toBe(TenantStatus::Active);

    $this->actingAs($admin, 'platform')
        ->post(route('central.tenants.retry-provisioning', $tenant))
        ->assertRedirect(route('central.tenants.show', $tenant))
        ->assertSessionHas('status', 'Only a still-provisioning tenant can be retried.');

    expect(PlatformAdminActivityLog::where('tenant_id', $tenant->id)
        ->where('action', 'tenant.retry_provisioning')
        ->exists())->toBeFalse();
});

test('guests cannot retry provisioning', function () {
    $tenant = stuckProvisioningTenant('Guest Blocked Co');

    $this->post(route('central.tenants.retry-provisioning', $tenant))
        ->assertRedirect(route('login'));
});
