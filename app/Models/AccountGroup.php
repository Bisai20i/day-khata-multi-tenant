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
     * Seeded groups (ChartOfAccountsSeeder). Code looks several of them up
     * by exact name (Fixed Assets, Current Assets, ...), so none can go.
     *
     * @var list<string>
     */
    public const PROTECTED_NAMES = [
        'Current Assets', 'Fixed Assets', 'Current Liabilities', 'Sales Accounts',
        'Indirect Income', 'Purchase Accounts', 'Indirect Expenses', 'Capital Account',
    ];

    /**
     * Why this group cannot be deleted right now, or null when it can.
     */
    public function deletionBlocker(): ?string
    {
        if (in_array($this->name, self::PROTECTED_NAMES, true)) {
            return 'This is a default group the system depends on and cannot be deleted.';
        }

        if ($this->subgroups()->exists()) {
            return 'This group still has subgroups. Delete or move them first.';
        }

        if ($this->accounts()->exists()) {
            return 'This group still has accounts. Delete or move them first.';
        }

        return null;
    }

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
