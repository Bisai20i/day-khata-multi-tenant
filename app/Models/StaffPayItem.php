<?php

namespace App\Models;

use App\Casts\Decimal;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A recurring entitlement: this staff member is paid `amount` of a component
 * each period from `effective_from` until `effective_to` (open-ended when
 * null). History is kept by closing a row and inserting a new one, never by
 * editing the amount in place (see App\Support\Hr\StaffPayItemSync), so a
 * posted run can always be traced to the entitlement that produced it.
 */
#[Fillable(['staff_id', 'pay_component_id', 'amount', 'effective_from', 'effective_to'])]
class StaffPayItem extends Model
{
    use HasFactory;

    protected $table = 'hr_staff_pay_items';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => Decimal::class.':2',
            'effective_from' => 'date:Y-m-d',
            'effective_to' => 'date:Y-m-d',
        ];
    }

    /**
     * Items in force on the given date (Y-m-d).
     *
     * @param  Builder<StaffPayItem>  $query
     * @return Builder<StaffPayItem>
     */
    public function scopeInForceOn(Builder $query, string $date): Builder
    {
        return $query
            ->whereDate('effective_from', '<=', $date)
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date));
    }

    /**
     * @return BelongsTo<StaffMember, $this>
     */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(StaffMember::class, 'staff_id');
    }

    /**
     * @return BelongsTo<PayComponent, $this>
     */
    public function component(): BelongsTo
    {
        return $this->belongsTo(PayComponent::class, 'pay_component_id');
    }
}
