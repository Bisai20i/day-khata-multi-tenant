<?php

namespace App\Listeners;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use Stancl\Tenancy\Events\TenancyInitialized;

class AbortIfTenantSuspended
{
    /**
     * Block the request with a 403 if the tenant that was just resolved for
     * this request has been suspended by a platform admin.
     */
    public function handle(TenancyInitialized $event): void
    {
        /** @var Tenant $tenant */
        $tenant = $event->tenancy->tenant;

        if ($tenant->status === TenantStatus::Suspended) {
            abort(403, 'This tenant account has been suspended.');
        }
    }
}
