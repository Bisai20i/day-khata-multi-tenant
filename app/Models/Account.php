<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

#[Fillable(['account_group_id', 'account_subgroup_id', 'code', 'name', 'phone', 'address'])]
class Account extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (self $account): void {
            if (($account->account_group_id === null) === ($account->account_subgroup_id === null)) {
                throw new InvalidArgumentException(
                    'An account must belong to exactly one of account_group_id or account_subgroup_id.'
                );
            }
        });
    }

    /**
     * The account group this account is filed directly under, if any.
     *
     * @return BelongsTo<AccountGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(AccountGroup::class, 'account_group_id');
    }

    /**
     * The account subgroup this account is filed under, if any.
     *
     * @return BelongsTo<AccountSubgroup, $this>
     */
    public function subgroup(): BelongsTo
    {
        return $this->belongsTo(AccountSubgroup::class, 'account_subgroup_id');
    }
}
