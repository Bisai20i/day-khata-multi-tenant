<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

#[Fillable(['account_group_id', 'account_subgroup_id', 'code', 'name', 'phone', 'address'])]
class Account extends Model
{
    use HasFactory;

    /**
     * Accounts the posting engine looks up by code (CA2 is "Profit & Loss").
     * They cannot be deleted, recoded, renamed or moved (audit JE-02).
     *
     * @var list<string>
     */
    public const SYSTEM_CODES = [
        'AS1', 'AS11', 'AS31', 'ASA23', 'CA2', 'EXE8', 'EXE20', 'EXE21', 'EXE22',
        'INI20', 'INI30', 'LIA20', 'LIA21',
    ];

    public function isSystemAccount(): bool
    {
        return in_array($this->getOriginal('code'), self::SYSTEM_CODES, true);
    }

    /**
     * The account head resolved through a group or subgroup id.
     */
    private static function headIdFor(?int $groupId, ?int $subgroupId): ?int
    {
        if ($groupId !== null) {
            return AccountGroup::query()->whereKey($groupId)->value('account_head_id');
        }

        if ($subgroupId !== null) {
            $parentGroupId = AccountSubgroup::query()->whereKey($subgroupId)->value('account_group_id');

            return $parentGroupId === null ? null : AccountGroup::query()->whereKey($parentGroupId)->value('account_head_id');
        }

        return null;
    }

    protected static function booted(): void
    {
        static::updating(function (self $account): void {
            if ($account->isSystemAccount()
                && $account->isDirty(['code', 'name', 'account_group_id', 'account_subgroup_id'])) {
                throw new InvalidArgumentException('This is a system account the ledger depends on; its code, name and group cannot be changed.');
            }

            if ($account->isDirty(['account_group_id', 'account_subgroup_id']) && $account->journalVoucherLines()->exists()) {
                $before = static::headIdFor($account->getOriginal('account_group_id'), $account->getOriginal('account_subgroup_id'));
                $after = static::headIdFor($account->account_group_id, $account->account_subgroup_id);

                if ($before !== $after) {
                    throw new InvalidArgumentException('This account already has postings, so it can no longer be moved to a different head.');
                }
            }
        });

        static::deleting(function (self $account): void {
            if ($account->isSystemAccount()) {
                throw new InvalidArgumentException('This is a system account the ledger depends on and cannot be deleted.');
            }

            if ($account->journalVoucherLines()->exists()) {
                throw new InvalidArgumentException('This account has postings and cannot be deleted.');
            }
        });

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

    /**
     * @return HasMany<JournalVoucherLine, $this>
     */
    public function journalVoucherLines(): HasMany
    {
        return $this->hasMany(JournalVoucherLine::class);
    }

    /**
     * Whether this account resets to zero at year-end (Income/Expenses)
     * rather than carrying its balance forward - resolved through
     * whichever of group/subgroup this account actually uses.
     */
    public function isProfitAndLoss(): bool
    {
        $head = $this->group?->accountHead ?? $this->subgroup?->accountGroup?->accountHead;

        return $head?->is_profit_and_loss ?? false;
    }
}
