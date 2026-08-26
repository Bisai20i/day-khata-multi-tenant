<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'is_profit_and_loss'])]
class AccountHead extends Model
{
    use HasFactory;

    /**
     * The groups filed under this account head.
     *
     * @return HasMany<AccountGroup, $this>
     */
    public function groups(): HasMany
    {
        return $this->hasMany(AccountGroup::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_profit_and_loss' => 'boolean',
        ];
    }
}
