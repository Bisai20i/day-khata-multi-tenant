<?php

namespace App\Models;

use App\Enums\TenantStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

#[Fillable(['company_name', 'status', 'suspended_at', 'trial_ends_at', 'contact_email'])]
class Tenant extends BaseTenant implements TenantWithDatabase
{
    use HasDatabase, HasDomains;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'suspended_at' => 'datetime',
            'trial_ends_at' => 'datetime',
        ];
    }

    /**
     * Whether this tenant has been suspended longer than the given grace
     * period. Computed at read time from suspended_at + the grace-period
     * days setting rather than stored, so it can never drift out of sync
     * with a later change to platform_settings.default_grace_period_days.
     * Surfaced only (tenant list/show, dashboard) - never auto-deletes.
     */
    public function isPastGracePeriod(int $gracePeriodDays): bool
    {
        if ($this->suspended_at === null) {
            return false;
        }

        return now()->greaterThan($this->suspended_at->clone()->addDays($gracePeriodDays));
    }

    /**
     * Whether this tenant's trial period is over. Surfaced only (tenant
     * list/show) - never auto-suspends, same posture as the grace period.
     */
    public function isTrialExpired(): bool
    {
        return $this->trial_ends_at !== null && now()->greaterThan($this->trial_ends_at);
    }

    /**
     * Whether this tenant's own database actually exists on disk/server,
     * independent of what the `status` column claims. A tenant can end up
     * Active with no database behind it (e.g. an interrupted provisioning
     * run from before this check existed) - status alone isn't trustworthy
     * enough to gate a database connection attempt on. Delegates to the
     * driver-specific TenantDatabaseManager (SQLiteDatabaseManager checks
     * the file exists; MySQL/Postgres managers query the server), the same
     * one DatabaseTenancyBootstrapper itself uses in local environments.
     */
    public function databaseExists(): bool
    {
        return $this->database()->manager()->databaseExists($this->database()->getName());
    }

    /**
     * Columns that live as real columns on the `tenants` table rather than
     * being swept into the `data` JSON column by the VirtualColumn trait.
     *
     * @return array<int, string>
     */
    public static function getCustomColumns(): array
    {
        return [
            'id',
            'company_name',
            'status',
            'suspended_at',
            'trial_ends_at',
            'contact_email',
        ];
    }
}
