<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['account_group_id', 'name'])]
class AccountSubgroup extends Model
{
    use HasFactory;

    /**
     * Seeded subgroups that code looks up by exact name (party ledger
     * accounts, Profit & Loss, stock).
     *
     * @var list<string>
     */
    public const PROTECTED_NAMES = [
        'Sundry Debtors', 'Cash-In-Hand', 'Stock', 'Sundry Creditors', 'Sales Agents', 'Reserve Surplus',
    ];

    /**
     * Why this subgroup cannot be deleted right now, or null when it can.
     */
    public function deletionBlocker(): ?string
    {
        if (in_array($this->name, self::PROTECTED_NAMES, true)) {
            return 'This is a default subgroup the system depends on and cannot be deleted.';
        }

        if ($this->accounts()->exists()) {
            return 'This subgroup still has accounts. Delete or move them first.';
        }

        return null;
    }

    /**
     * The account group this subgroup belongs to.
     *
     * @return BelongsTo<AccountGroup, $this>
     */
    public function accountGroup(): BelongsTo
    {
        return $this->belongsTo(AccountGroup::class);
    }

    /**
     * Leaf accounts filed under this subgroup.
     *
     * @return HasMany<Account, $this>
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class, 'account_subgroup_id');
    }
}
