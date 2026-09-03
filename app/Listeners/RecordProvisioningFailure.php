<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Jobs\CreateTenantFirstAdmin;
use App\Models\PlatformAdminActivityLog;
use App\Models\Tenant;
use Illuminate\Queue\Events\JobFailed;
use Stancl\JobPipeline\JobPipeline;
use Throwable;

/**
 * Tenant provisioning (TenancyServiceProvider's TenantCreated pipeline:
 * CreateDatabase/MigrateDatabase/SeedDatabase/CreateTenantFirstAdmin) runs
 * as a single queued Stancl\JobPipeline\JobPipeline job. None of its steps
 * define a failed() method, so JobPipeline::handle() re-throws any
 * exception one of them raises instead of swallowing it - that failure
 * propagates out and fails the outer JobPipeline job, which is what this
 * listener catches.
 *
 * Deliberately not parsing the failed_jobs table (tenant id isn't a
 * queryable column there, and the payload shape is an implementation
 * detail of whichever queue driver is in use) - listening for the
 * framework's own JobFailed event and reading the still-serialized command
 * off its payload is the stable hook.
 */
class RecordProvisioningFailure
{
    public function handle(JobFailed $event): void
    {
        $payload = $event->job->payload();

        if (($payload['displayName'] ?? null) !== JobPipeline::class) {
            return;
        }

        $tenant = $this->extractProvisioningTenant($payload);

        if ($tenant === null) {
            return;
        }

        PlatformAdminActivityLog::record('provisioning.failed', $tenant, [
            'error' => $event->exception->getMessage(),
        ]);
    }

    /**
     * Unserializes the JobPipeline command carried in the failed job's own
     * payload to recover the tenant it was provisioning - the same
     * mechanism Laravel's own CallQueuedHandler uses to run it in the first
     * place, just read back out afterwards. Returns null for any pipeline
     * that isn't the tenant-creation one (e.g. the TenantDeleted pipeline
     * also runs as a JobPipeline) or whose payload can't be decoded.
     *
     * @param  array<string, mixed>  $payload
     */
    private function extractProvisioningTenant(array $payload): ?Tenant
    {
        $serialized = $payload['data']['command'] ?? null;

        if (! is_string($serialized)) {
            return null;
        }

        try {
            $command = unserialize($serialized);
        } catch (Throwable) {
            return null;
        }

        if (! $command instanceof JobPipeline || ! in_array(CreateTenantFirstAdmin::class, $command->jobs, true)) {
            return null;
        }

        $tenant = $command->passable[0] ?? null;

        return $tenant instanceof Tenant ? $tenant : null;
    }
}
