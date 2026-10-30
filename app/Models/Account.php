<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

#[Fillable(['account_group_id', 'account_subgroup_id', 'code', 'name', 'phone', 'address'])]
class Account extends Model
{
    use HasFactory;

    /**
     * Accounts the posting engine looks up by code (CA2 is "Profit & Loss",
     * also looked up by that name). They cannot be deleted, recoded, renamed
     * or moved (audit JE-02). EXE9 and INI22 are the opening and closing stock
     * accounts FiscalYear's year-end stock entry resolves by code (confirmed
     * list, flags D3). AS12-AS14, EXE10, EXE23 and EXE24 are the perpetual
     * manufacturing inventory accounts (ManufacturingAccounts).
     *
     * @var list<string>
     */
    public const SYSTEM_CODES = [
        'AS1', 'AS11', 'AS12', 'AS13', 'AS14', 'AS31', 'ASA23', 'CA2', 'EXE8', 'EXE9', 'EXE10',
        'EXE20', 'EXE21', 'EXE22', 'EXE23', 'EXE24',
        'INI20', 'INI22', 'INI30', 'LIA20', 'LIA21',
    ];

    /**
     * Seeded starter accounts that code does not look up by code but every
     * tenant ships with (ChartOfAccountsSeeder). Protected from deletion
     * only - they stay renamable, unlike SYSTEM_CODES.
     *
     * @var list<string>
     */
    public const SEEDED_CODES = ['INI21', 'EXE81'];

    /**
     * Every table => columns that point at accounts.id. Most are
     * nullOnDelete, which would silently orphan a document's bank / TDS /
     * refund / posting account rather than fail, so deletion checks each.
     *
     * @var array<string, string>
     */
    private const REFERENCES = [
        'customers' => 'account_id',
        'suppliers' => 'account_id',
        'agents' => 'account_id',
        'fixed_assets' => 'account_id',
        'items' => 'account_id',
        'purchase_lines' => 'account_id',
        'capital_purchase_lines' => 'account_id',
        'capital_sale_lines' => 'account_id',
        'sales' => 'bank_account_id,tds_account_id',
        'purchases' => 'bank_account_id,tds_account_id',
        'sales_returns' => 'refund_account_id,bank_account_id',
        'purchase_returns' => 'refund_account_id,bank_account_id',
        'receipts' => 'bank_account_id',
        'payments' => 'bank_account_id',
        'capital_purchases' => 'bank_account_id',
        'capital_sales' => 'bank_account_id',
        'capital_purchase_settlements' => 'bank_account_id',
    ];

    public function isSystemAccount(): bool
    {
        return in_array($this->getOriginal('code'), self::SYSTEM_CODES, true);
    }

    /**
     * The reason this account cannot be deleted right now, or null when it
     * is unused. The controller re-runs it inside the delete transaction.
     */
    public function deletionBlocker(): ?string
    {
        if ($this->isSystemAccount() || in_array($this->getOriginal('code'), self::SEEDED_CODES, true)) {
            return 'This is a default system account the ledger depends on and cannot be deleted.';
        }

        // ANY line counts - posted, cancelled or reversed, any fiscal year,
        // opening-balance lines included - so no history loses its account.
        if ($this->journalVoucherLines()->exists()) {
            return 'This account has ledger entries (including opening balance or cancelled ones) and cannot be deleted.';
        }

        $schema = DB::getSchemaBuilder();

        foreach (self::REFERENCES as $table => $columns) {
            if (! $schema->hasTable($table)) {
                continue;
            }

            foreach (explode(',', $columns) as $column) {
                if ($schema->hasColumn($table, $column) && DB::table($table)->where($column, $this->getKey())->exists()) {
                    return 'This account is in use by a customer, supplier, agent, fixed asset, item or document ('.str_replace('_', ' ', $table).') and cannot be deleted.';
                }
            }
        }

        return null;
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
            if ($blocker = $account->deletionBlocker()) {
                throw new InvalidArgumentException($blocker);
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
