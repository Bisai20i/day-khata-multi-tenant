<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['account_head_id', 'name'])]
class AccountGroup extends Model
{
    use HasFactory;

    /**
     * The account head this group belongs to.
     *
     * @return BelongsTo<AccountHead, $this>
     */
    public function accountHead(): BelongsTo
    {
        return $this->belongsTo(AccountHead::class);
    }

    /**
     * The subgroups filed under this group.
     *
     * @return HasMany<AccountSubgroup, $this>
     */
    public function subgroups(): HasMany
    {
        return $this->hasMany(AccountSubgroup::class);
    }

    /**
     * Leaf accounts filed directly under this group (no subgroup level).
     *
     * @return HasMany<Account, $this>
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class, 'account_group_id');
    }
}
