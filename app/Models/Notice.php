<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A dashboard-only announcement/banner. Never touches the ledger and isn't
 * fiscal-year-scoped - a plain permanent register, like a customer or
 * supplier, admin-managed via routes/tenant-notices.php and surfaced to
 * every authenticated user on the dashboard through the currentlyActive
 * scope below.
 */
#[Fillable(['title', 'body', 'starts_at', 'ends_at', 'is_active', 'created_by'])]
class Notice extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'date',
            'ends_at' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * A notice is "currently active" when its admin off-switch is on AND
     * today falls inside its optional date window - a null starts_at/
     * ends_at means "no lower/upper bound" respectively.
     *
     * Uses whereDate() (not a raw string comparison) deliberately: under
     * SQLite a `date`-cast column is actually stored as a full
     * "Y-m-d H:i:s" string, which sorts lexicographically *after* a bare
     * "Y-m-d" string on the very day a window starts/ends - whereDate()'s
     * driver-portable date() wrapping avoids that off-by-one-day trap (see
     * day-khata-multi-tenant mem.md's FiscalYear::rollForward() writeup for
     * the same class of bug hit before).
     *
     * @param  Builder<Notice>  $query
     * @return Builder<Notice>
     */
    public function scopeCurrentlyActive(Builder $query): Builder
    {
        $today = now()->toDateString();

        return $query->where('is_active', true)
            ->where(function (Builder $query) use ($today) {
                $query->whereNull('starts_at')->orWhereDate('starts_at', '<=', $today);
            })
            ->where(function (Builder $query) use ($today) {
                $query->whereNull('ends_at')->orWhereDate('ends_at', '>=', $today);
            });
    }
}
