<?php

namespace App\Models;

use App\Enums\TenantStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

#[Fillable(['company_name', 'status', 'suspended_at', 'contact_email'])]
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
            'contact_email',
        ];
    }
}
