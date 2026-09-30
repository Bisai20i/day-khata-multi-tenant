<?php

namespace App\Models;

use App\Enums\TenantStatus;
use App\Support\Permissions\PermissionCatalog;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

#[Fillable(['company_name', 'status', 'suspended_at', 'trial_ends_at', 'contact_email', 'enabled_modules'])]
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
            'enabled_modules' => 'array',
        ];
    }

    /**
     * Default `enabled_modules` for tenants created without an explicit list
     * (tests, tinker, seeders). The central create form always sends an
     * explicit list, and an explicit empty list is honoured (core only), so
     * the default applies only when the attribute was never set. At `creating`
     * time the column is a real attribute (stancl's data-column encoding runs
     * after this listener), so array_key_exists on the raw attributes is exact.
     */
    protected static function booted(): void
    {
        static::creating(function (Tenant $tenant): void {
            if (! array_key_exists('enabled_modules', $tenant->getAttributes())) {
                $tenant->enabled_modules = config('permissions.default_modules', []);
            }
        });
    }

    /**
     * The module keys this tenant is entitled to, always including `core` and
     * every transitive dependency (`pos` implies `sales`).
     *
     * Fail-closed: a NULL `enabled_modules` (row never backfilled) means core
     * only, never "everything". Existing tenants are backfilled with all
     * modules by the migration, so NULL is only reachable by a deliberate or
     * broken write. A JSON column on the tenant row beats a pivot table here:
     * the tenant is already loaded on every request, so entitlement checks
     * cost zero extra queries.
     *
     * Not memoized: the row can change mid-request (central edits) and
     * resolveModules() is a cheap in-memory computation.
     *
     * @return array<int, string>
     */
    public function entitledModules(): array
    {
        $enabled = $this->enabled_modules;

        return PermissionCatalog::resolveModules(is_array($enabled) ? $enabled : []);
    }

    /**
     * Whether the tenant is entitled to the given module key. `core` is
     * always true; unknown keys are false.
     */
    public function hasModule(string $module): bool
    {
        return in_array($module, $this->entitledModules(), true);
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
            'enabled_modules',
        ];
    }
}
