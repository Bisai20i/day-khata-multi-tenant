<?php

namespace App\Models;

use App\Casts\Decimal;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A project (job or contract) that journal lines can be tagged with. Deleting
 * a project nulls project_id on its lines (nullOnDelete); it never removes
 * ledger lines.
 */
#[Fillable([
    'code', 'name', 'name_np', 'client', 'contract_value', 'budget',
    'start_date', 'end_date', 'status', 'notes',
])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_ON_HOLD = 'on_hold';

    /**
     * @var list<string>
     */
    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_COMPLETED, self::STATUS_ON_HOLD];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'contract_value' => Decimal::class.':2',
            'budget' => Decimal::class.':2',
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * @return HasMany<JournalVoucherLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalVoucherLine::class);
    }
}
