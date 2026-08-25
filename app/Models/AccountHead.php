<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name'])]
class AccountHead extends Model
{
    /**
     * The groups filed under this account head.
     *
     * @return HasMany<AccountGroup, $this>
     */
    public function groups(): HasMany
    {
        return $this->hasMany(AccountGroup::class);
    }
}
