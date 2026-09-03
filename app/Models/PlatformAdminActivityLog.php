<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Append-only audit trail for sensitive central (platform-admin) actions -
 * tenant create/update/suspend/resume/delete/impersonate, settings changes,
 * platform-admin management. Replaces the earlier Log::info() stopgap in
 * TenantController::impersonate(). Never updated or deleted from the
 * application layer - only ever inserted via record().
 */
#[Fillable(['platform_admin_id', 'tenant_id', 'action', 'metadata'])]
class PlatformAdminActivityLog extends Model
{
    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * The platform admin who performed the action.
     *
     * @return BelongsTo<PlatformAdmin, $this>
     */
    public function platformAdmin(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class);
    }

    /**
     * The tenant this action was about, if any (settings changes and
     * platform-admin management aren't tenant-scoped).
     *
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Record a sensitive central action. The acting admin is always read
     * from the "platform" guard, never accepted as a parameter, so a caller
     * can't misattribute an entry to someone else.
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function record(string $action, ?Tenant $tenant = null, array $metadata = []): self
    {
        return self::create([
            'platform_admin_id' => Auth::guard('platform')->id(),
            'tenant_id' => $tenant?->id,
            'action' => $action,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }
}
