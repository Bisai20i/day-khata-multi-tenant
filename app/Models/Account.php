<?php

namespace App\Models;

use App\Support\Branches\BranchAccounts;
use App\Support\Branches\BranchContext;
use App\Support\Hr\HrAccounts;
use App\Support\Manufacturing\ManufacturingAccounts;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

#[Fillable(['account_group_id', 'account_subgroup_id', 'code', 'name', 'phone', 'address', 'parent_account_id'])]
class Account extends Model
{
    use HasFactory;

    /**
     * Accounts the posting engine looks up by code (CA2 is "Profit & Loss",
     * also looked up by that name). They cannot be deleted, recoded, renamed
     * or moved (audit JE-02). EXE9 and INI22 are the opening and closing stock
     * accounts FiscalYear's year-end stock entry resolves by code (confirmed
     * list, flags D3). AS12-AS14, EXE10, EXE23 and EXE24 are the perpetual
     * manufacturing inventory accounts (ManufacturingAccounts). LIA22, ASA24
     * and EXE25-EXE27 are the HR payroll accounts (HrAccounts). ASA25
     * (Inter-Branch Clearing), INI23 (Stock Transferred to Branches) and
     * EXE11 (Stock Received from Branches) are the inter-branch accounts
     * (BranchAccounts), looked up by code by BranchBalancer and the year-end
     * I3 check.
     *
     * @var list<string>
     */
    public const SYSTEM_CODES = [
        'AS1', 'AS11', 'AS12', 'AS13', 'AS14', 'AS31', 'ASA23', 'ASA24', 'ASA25', 'CA2', 'EXE8', 'EXE9', 'EXE10',
        'EXE11', 'EXE20', 'EXE21', 'EXE22', 'EXE23', 'EXE24', 'EXE25', 'EXE26', 'EXE27',
        'INI20', 'INI22', 'INI23', 'INI30', 'INI31', 'LIA20', 'LIA21', 'LIA22',
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
        'hr_pay_components' => 'expense_account_id',
        'hr_payroll_payments' => 'bank_account_id',
        'hr_staff_advances' => 'bank_account_id',
        'production_direct_costs' => 'credit_account_id',
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

        if ($this->exists && $this->subAccounts()->exists()) {
            return 'This account has sub-accounts and cannot be deleted.';
        }

        // ANY line counts - posted, cancelled or reversed, any fiscal year,
        // opening-balance lines included - so no history loses its account.
        // Unrestricted: a line filed in a branch the actor cannot see still
        // ties the account to history (plan 12, section 5.7).
        if ($this->hasAnyLedgerLine()) {
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
     * Whether any journal line, in any branch, posts to this account. Read
     * inside BranchContext::unrestricted() because the line access scope would
     * otherwise hide other branches' lines from a restricted user and let them
     * delete or re-head an account that has history.
     */
    public function hasAnyLedgerLine(): bool
    {
        return BranchContext::unrestricted(fn (): bool => $this->journalVoucherLines()->exists());
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

            if ($account->isDirty(['account_group_id', 'account_subgroup_id']) && $account->hasAnyLedgerLine()) {
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
            if ($account->parent_account_id !== null) {
                if ((int) $account->parent_account_id === (int) $account->id || in_array((int) $account->parent_account_id, $account->allSubAccountIds(), true)) {
                    throw new InvalidArgumentException('An account cannot have itself or one of its sub-accounts as parent.');
                }
            }

            if (($account->account_group_id === null) === ($account->account_subgroup_id === null)) {
                throw new InvalidArgumentException(
                    'An account must belong to exactly one of account_group_id or account_subgroup_id.'
                );
            }
        });
    }

    /**
     * The parent account this sub-account belongs to, if any.
     *
     * @return BelongsTo<Account, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'parent_account_id');
    }

    /**
     * Direct sub-accounts belonging to this account.
     *
     * @return HasMany<Account, $this>
     */
    public function subAccounts(): HasMany
    {
        return $this->hasMany(Account::class, 'parent_account_id');
    }

    /**
     * IDs of all direct and nested sub-accounts.
     *
     * @return list<int>
     */
    public function allSubAccountIds(): array
    {
        if (! $this->exists) {
            return [];
        }

        $ids = [];
        $queue = $this->subAccounts()->pluck('id')->map(fn ($id): int => (int) $id)->all();

        while (! empty($queue)) {
            $currentId = array_shift($queue);
            if (! in_array($currentId, $ids, true)) {
                $ids[] = $currentId;
                $children = static::query()
                    ->where('parent_account_id', $currentId)
                    ->pluck('id')
                    ->map(fn ($id): int => (int) $id)
                    ->all();

                foreach ($children as $childId) {
                    if (! in_array($childId, $ids, true) && ! in_array($childId, $queue, true)) {
                        $queue[] = $childId;
                    }
                }
            }
        }

        return $ids;
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

    /**
     * Accounts that represent bank accounts (Assets / Liabilities balance sheet accounts,
     * excluding control accounts, party subgroups, and non-bank categories).
     *
     * @return Builder<Account>
     */
    public static function bankAccountQuery(): Builder
    {
        return BranchAccounts::exclude(static::query())
            ->where(fn (Builder $q) => $q
                ->whereHas('group.accountHead', fn (Builder $h) => $h->where('is_profit_and_loss', false))
                ->orWhereHas('subgroup.accountGroup.accountHead', fn (Builder $h) => $h->where('is_profit_and_loss', false))
            )
            ->whereDoesntHave('subgroup', fn (Builder $sq) => $sq->whereIn('name', [
                'Sundry Debtors',
                'Sundry Creditors',
                'Sales Agents',
                'Salary Payable',
                'Cash-In-Hand',
                'Stock',
                'Reserve Surplus',
            ]))
            ->whereDoesntHave('group', fn (Builder $gq) => $gq->where('name', 'Fixed Assets'))
            ->where(fn (Builder $q) => $q->whereNull('code')->orWhereNotIn('code', [
                ...BranchAccounts::CONTROL_CODES,
                ...HrAccounts::CONTROL_CODES,
                ...ManufacturingAccounts::CONTROL_CODES,
                'AS1', 'CA2', 'AS11', 'AS12', 'AS13', 'AS14', 'ASA23', 'LIA20', 'LIA21', 'LIA22', 'AS31',
            ]))
            ->where(function (Builder $q) {
                $q->whereHas('subgroup', function (Builder $sq) {
                    $sq->whereIn('name', ['Bank Accounts', 'Bank Account', 'Bank', 'Banks'])
                        ->orWhereRaw('LOWER(name) LIKE ?', ['%bank%']);
                })
                    ->orWhereHas('group', function (Builder $gq) {
                        $gq->whereIn('name', ['Bank Accounts', 'Bank Account', 'Bank', 'Banks', 'Bank Loans'])
                            ->orWhereRaw('LOWER(name) LIKE ?', ['%bank%']);
                    })
                    ->orWhereRaw('LOWER(name) LIKE ?', ['%bank%'])
                    ->orWhere('code', 'like', 'BANK%')
                    ->orWhereHas('parent', function (Builder $pq) {
                        $pq->whereRaw('LOWER(name) LIKE ?', ['%bank%'])
                            ->orWhere('code', 'like', 'BANK%');
                    });
            });
    }
}
