<?php

namespace App\Models;

use App\Support\Branches\BranchRuleViolation;
use Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A finance and access unit of a tenant. Stores stay stock locations and each
 * belongs to exactly one branch. Every tenant has exactly one Main branch
 * (created by the branches migration and the tenant seeder) that can never be
 * deactivated, and branches are never deleted.
 *
 * The model guards cover Eloquent saves and deletes. Raw query-builder writes
 * bypass model events, so application code must not mass-update or mass-delete
 * the branches table.
 */
#[Fillable([
    'name', 'code', 'series_code', 'address', 'phone', 'email', 'pan_number',
    'default_store_id', 'is_main', 'is_active', 'sort_order',
])]
class Branch extends Model
{
    /** @use HasFactory<BranchFactory> */
    use HasFactory;

    /**
     * Frozen identity of the branch every tenant starts with.
     */
    public const MAIN_NAME = 'Main';

    public const MAIN_CODE = 'MAIN';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_main' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $branch): void {
            if ($branch->is_main && static::query()->where('is_main', true)->exists()) {
                throw BranchRuleViolation::mainAlreadyExists();
            }
        });

        static::updating(function (self $branch): void {
            if ($branch->isDirty('is_main')) {
                throw BranchRuleViolation::mainCannotBeChanged();
            }

            if ($branch->is_main && ! $branch->is_active) {
                throw BranchRuleViolation::mainCannotBeDeactivated();
            }

            // Per-branch numbering (BR20): a series code that has numbered a
            // document is frozen. A claimed sequence row (last_number > 0)
            // only exists once a voucher was posted in that series, and the
            // table is small, so this costs one cheap query per code edit.
            if ($branch->isDirty('series_code')
                && VoucherSequence::query()->where('series_branch_id', $branch->getKey())->where('last_number', '>', 0)->exists()) {
                throw BranchRuleViolation::seriesCodeIsLocked((string) $branch->getOriginal('name'));
            }

            // A code cannot be cleared while any year numbers per branch: every
            // posting in this branch (and, for Main, the year-end vouchers)
            // would be refused.
            if ($branch->isDirty('series_code')
                && trim((string) $branch->series_code) === ''
                && FiscalYear::query()->where('numbering_scope', FiscalYear::NUMBERING_PER_BRANCH)->exists()) {
                throw BranchRuleViolation::seriesCodeIsRequired((string) $branch->getOriginal('name'));
            }
        });

        static::deleting(function (): never {
            throw BranchRuleViolation::branchCannotBeDeleted();
        });
    }

    /**
     * The Main branch. Throws if the tenant has none, which would mean the
     * branches migration or the tenant seeder did not run.
     */
    public static function main(): self
    {
        return static::query()->where('is_main', true)->firstOrFail();
    }

    /**
     * Find or create the Main branch, for seeders and tests that start from an
     * empty branches table. Safe to call repeatedly.
     */
    public static function ensureMain(): self
    {
        return static::query()->where('is_main', true)->first()
            ?? static::create([
                'name' => self::MAIN_NAME,
                'code' => self::MAIN_CODE,
                'is_main' => true,
                'is_active' => true,
                'sort_order' => 0,
            ]);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Users explicitly assigned to this branch (meaningful only for users
     * whose all_branches flag is off).
     *
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'branch_user');
    }

    /**
     * @return HasMany<Store, $this>
     */
    public function stores(): HasMany
    {
        return $this->hasMany(Store::class);
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function defaultStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'default_store_id');
    }
}
