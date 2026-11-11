<?php

use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\AccountHead;
use App\Models\AccountSubgroup;
use App\Support\Branches\BranchContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Adds the three inter-branch system accounts (plan 12, BR17) to a tenant
 * whose chart of accounts already exists (new tenants get them from
 * ChartOfAccountsSeeder, which runs after the migrations, so on a fresh
 * database the groups are missing and this adds nothing):
 *
 * - ASA25 "Inter-Branch Clearing" under Current Assets (head Assets),
 * - INI23 "Stock Transferred to Branches" under Sales Accounts (head Income),
 * - EXE11 "Stock Received from Branches" under Purchase Accounts (head
 *   Expenses).
 *
 * Why these codes and groups: App\Support\Branches\BranchAccounts. The lists
 * are frozen here as literals (.ai/rules/migrations.md), not read from that
 * class or from Account, so the result never depends on when this runs.
 * Groups are found by head name AND group name (account_groups is unique on
 * the pair, not on the name), as ChartOfAccountsSeeder files them.
 *
 * Names are compared "loosely" (case-insensitive, surrounding spaces
 * ignored, sameName()): that is how MySQL's collation compared them when the
 * pre-BR17 posting engine found the clearing account by name. Whenever this
 * migration accepts an account as a control account it writes the EXACT
 * control name onto it, because the posting paths compare names strictly
 * (BranchAccounts::codeConflictMessage()).
 *
 * Code collision: ChartOfAccountsSeeder never used these three codes, but an
 * administrator may have given one of them to an account of their own. Many
 * paths find the control accounts by code alone (the year-end I3 check, the
 * inter-branch balances and branch comparison reports, the pickers' exclude(),
 * assertNotControlAccounts()), so leaving that account on the code would turn
 * it into a control account: hidden from pickers, refused in manual entries,
 * and counted in the I3 check that blocks the year-end close. Nor could the
 * administrator re-code it, since the code is in Account::SYSTEM_CODES. So:
 *
 * - an account with the code and the control name (loosely) IS the control
 *   account: its name is normalised and it is refiled into the target group
 *   when that is safe (settle(), canRefile());
 * - any other account with the code is moved out of the way (moveAside()):
 *   it gets the first free code of "{code}-1", "{code}-2", ..., keeping its
 *   name, group and lines, and a warning names the old and new code. The
 *   control account is then created (or adopted) as usual.
 *
 * Refiling (canRefile()): an account is moved between groups only when it
 * has no ledger lines AND nothing in REFERENCES points at it. Code keys on
 * group names (Fixed Assets, Current Assets: CapitalPurchase, FixedAsset,
 * SalesReturn, ProductionDirectCost, ItemController, the sales/purchase
 * report), so moving an account with history or a dependent record would
 * silently change how that history or record behaves. Otherwise the account
 * stays where it is and a warning says so.
 *
 * Adoption: until now the posting engine found the clearing account by its
 * NAME, and its "missing account" message told an administrator to add an
 * account called "Inter-Branch Clearing". A tenant that did so may already
 * have clearing lines on it, and creating a second account would split that
 * history from every future line. So when no account has the code (or the
 * one that had it was moved aside) and exactly one account has the name
 * (loosely) and no code, that account is given the code and the exact name
 * instead of a new one being created, provided it is filed where the code
 * belongs:
 *
 * - ASA25: anywhere under the target group's account head (Assets). It is
 *   moved directly into Current Assets when it sits elsewhere under Assets
 *   and canRefile() allows; otherwise it is adopted where it is (keeping the
 *   clearing history on one account) with a warning.
 * - INI23 / EXE11: only when filed DIRECTLY in Sales Accounts / Purchase
 *   Accounts, because AccountingReportController::tradingCodes() picks the
 *   trading accounts by their direct group. One filed anywhere else is not
 *   moved: that would reclassify its past lines between the trading and the
 *   profit and loss account.
 *
 * Declined adoption (several same-named accounts, a same-named account that
 * already has another code, or one filed outside where the code belongs):
 * the candidates are left untouched, the control account is created anyway
 * (no account holds the code at that point: a holder was either settled or
 * moved aside above) and a warning names the declined accounts and why, so
 * an administrator can merge or rename them. Every successful adoption that
 * renames or moves an account is logged too.
 *
 * Writes to existing accounts are query updates on purpose: Account's
 * updating guard refuses any change to a SYSTEM_CODES account (and the
 * account being written either holds or receives such a code). They bump
 * updated_at, which down() relies on to recognise an adopted account.
 *
 * Idempotent: a re-run finds each control account by its code under its
 * exact name and changes nothing; a moved-aside account no longer holds the
 * code. Each account's writes run in one transaction. Plain Eloquent queries
 * (LOWER/TRIM are common to SQLite and MySQL), so both behave alike.
 */
return new class extends Migration
{
    /**
     * `direct`: the account must be filed directly in the group (not in one
     * of its subgroups), as tradingCodes() requires of the trading accounts.
     *
     * @var list<array{code: string, name: string, head: string, group: string, direct: bool}>
     */
    private const ACCOUNTS = [
        ['code' => 'ASA25', 'name' => 'Inter-Branch Clearing', 'head' => 'Assets', 'group' => 'Current Assets', 'direct' => false],
        ['code' => 'INI23', 'name' => 'Stock Transferred to Branches', 'head' => 'Income', 'group' => 'Sales Accounts', 'direct' => true],
        ['code' => 'EXE11', 'name' => 'Stock Received from Branches', 'head' => 'Expenses', 'group' => 'Purchase Accounts', 'direct' => true],
    ];

    /**
     * Every table => columns pointing at accounts.id when this was written
     * (a frozen copy of Account::REFERENCES). An account referenced from any
     * of them is never refiled or deleted here. Tables or columns missing on
     * a tenant are skipped.
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

    /**
     * Columns read from an account; everything the checks below need.
     *
     * @var list<string>
     */
    private const COLUMNS = ['id', 'code', 'name', 'account_group_id', 'account_subgroup_id'];

    public function up(): void
    {
        foreach (self::ACCOUNTS as $spec) {
            DB::transaction(fn () => $this->addAccount($spec));
        }
    }

    public function down(): void
    {
        // Removes only an account this migration plainly created: the code,
        // the exact name, filed in the target group, no ledger lines (any
        // branch: unrestricted), nothing in REFERENCES pointing at it, and
        // created_at = updated_at (up() bumps updated_at whenever it adopts,
        // renames or moves an account, so an adopted one never matches).
        // Anything else stays, with a warning. A query delete on purpose:
        // every code here is in Account::SYSTEM_CODES, so a model delete()
        // would be refused by Account's deleting guard. An account up() moved
        // aside carries "{code}-N", so it is never matched here, and its old
        // code is not restored (that would re-create the collision).
        BranchContext::unrestricted(function (): void {
            foreach (self::ACCOUNTS as $spec) {
                $account = Account::query()->where('code', $spec['code'])->first([...self::COLUMNS, 'created_at', 'updated_at']);

                if (! $account) {
                    continue;
                }

                $reason = $this->rollbackBlocker($account, $spec);

                if ($reason !== null) {
                    Log::warning(sprintf(
                        'Rollback left inter-branch account %s "%s" (id %d) in place: %s.',
                        $spec['code'],
                        $account->name,
                        $account->id,
                        $reason,
                    ));

                    continue;
                }

                try {
                    Account::query()->whereKey($account->id)->delete();
                } catch (QueryException $exception) {
                    Log::warning(sprintf(
                        'Rollback left inter-branch account %s (id %d) in place: the database refused the delete (%s).',
                        $spec['code'],
                        $account->id,
                        $exception->getMessage(),
                    ));
                }
            }
        });
    }

    /**
     * Why down() must keep $account, or null when it may be deleted.
     *
     * @param  array{code: string, name: string, head: string, group: string, direct: bool}  $spec
     */
    private function rollbackBlocker(Account $account, array $spec): ?string
    {
        if ($account->name !== $spec['name']) {
            return 'its name is not exactly "'.$spec['name'].'"';
        }

        $group = $this->targetGroup($spec);

        if (! $group || ! $this->inTargetGroup($account, $group, $spec['direct'])) {
            return 'it is not filed in "'.$spec['group'].'"';
        }

        if ($account->hasAnyLedgerLine()) {
            return 'it has ledger lines';
        }

        if ($this->isReferenced($account)) {
            return 'a customer, supplier, item, fixed asset or document points at it';
        }

        $createdAt = $account->getRawOriginal('created_at');

        if ($createdAt === null || $createdAt !== $account->getRawOriginal('updated_at')) {
            return 'it existed before this migration or was changed since (adopted, renamed or edited)';
        }

        return null;
    }

    /**
     * Ensures one control account exists for $spec (see the class docblock).
     *
     * @param  array{code: string, name: string, head: string, group: string, direct: bool}  $spec
     */
    private function addAccount(array $spec): void
    {
        $group = $this->targetGroup($spec);
        $coded = Account::where('code', $spec['code'])->first(self::COLUMNS);
        $movedAsideId = null;

        if ($coded) {
            if ($this->sameName((string) $coded->name, $spec['name'])) {
                $this->settle($coded, $spec, $group);

                return;
            }

            $movedAsideId = $this->moveAside($coded, $spec);
        }

        if (! $group) {
            // A fresh database has no groups yet (the seeder adds them and
            // the accounts later); only a customised chart is worth a warning.
            if (AccountGroup::query()->exists()) {
                Log::warning(sprintf(
                    'Inter-branch account %s "%s" was not added: no group "%s" under head "%s". '
                    .'Add the group and run this migration again, or create the account by hand.',
                    $spec['code'],
                    $spec['name'],
                    $spec['group'],
                    $spec['head'],
                ));
            }

            return;
        }

        $sameName = Account::query()
            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($spec['name'])])
            ->when($movedAsideId !== null, fn ($query) => $query->whereKeyNot($movedAsideId))
            ->get(self::COLUMNS);

        if ($sameName->isNotEmpty()) {
            $declined = $this->adopt($sameName, $spec, $group);

            if ($declined === null) {
                return;
            }

            Log::warning(sprintf(
                'Inter-branch account %s "%s" was created new instead of adopting %s: %s. '
                .'Those accounts were left unchanged; merge or rename them so only one carries the name.',
                $spec['code'],
                $spec['name'],
                $sameName->map(fn (Account $account): string => sprintf(
                    '"%s" (id %d%s)',
                    $account->name,
                    $account->id,
                    $account->code === null ? '' : ', code '.$account->code,
                ))->implode(', '),
                $declined,
            ));
        }

        Account::create([
            'account_group_id' => $group->id,
            'account_subgroup_id' => null,
            'code' => $spec['code'],
            'name' => $spec['name'],
        ]);
    }

    /**
     * The account already holding the code is the control account: writes
     * the exact control name onto it and, when it is filed outside the
     * target group, moves it directly into that group if canRefile() allows;
     * otherwise leaves it where it is and logs why. Nothing to do (and no
     * warning) when the group row is missing.
     *
     * @param  array{code: string, name: string, head: string, group: string, direct: bool}  $spec
     */
    private function settle(Account $coded, array $spec, ?AccountGroup $group): void
    {
        $changes = [];

        if ($coded->name !== $spec['name']) {
            $changes['name'] = $spec['name'];

            Log::warning(sprintf(
                'Inter-branch account %s (id %d) renamed from "%s" to "%s", the exact name the posting engine expects.',
                $spec['code'],
                $coded->id,
                $coded->name,
                $spec['name'],
            ));
        }

        if ($group && ! $this->inTargetGroup($coded, $group, $spec['direct'])) {
            $changes += $this->refileChanges($coded, $group, $spec);
        }

        if ($changes !== []) {
            Account::query()->whereKey($coded->id)->update($changes);
        }
    }

    /**
     * Gives a tenant's own account that holds a reserved code the first free
     * code of "{code}-1", "{code}-2", ... (accounts.code is unique), keeping
     * everything else about it, and logs the old and new code. Returns its id.
     *
     * @param  array{code: string, name: string, head: string, group: string, direct: bool}  $spec
     */
    private function moveAside(Account $coded, array $spec): int
    {
        $suffix = 1;

        while (Account::where('code', $spec['code'].'-'.$suffix)->exists()) {
            $suffix++;
        }

        $newCode = $spec['code'].'-'.$suffix;

        Account::query()->whereKey($coded->id)->update(['code' => $newCode]);

        Log::warning(sprintf(
            'Account "%s" (id %d) re-coded from %s to %s: code %s is now reserved for the system account "%s" '
            .'(entries between branches). Its name, group and ledger lines are unchanged.',
            $coded->name,
            $coded->id,
            $spec['code'],
            $newCode,
            $spec['code'],
            $spec['name'],
        ));

        return (int) $coded->id;
    }

    /**
     * Gives the code (and the exact control name) to the single uncoded,
     * same-named account when it is filed where the code belongs (see the
     * class docblock). Returns null when it adopted the account, otherwise
     * why it declined (the caller then creates the control account).
     *
     * @param  Collection<int, Account>  $sameName
     * @param  array{code: string, name: string, head: string, group: string, direct: bool}  $spec
     */
    private function adopt(Collection $sameName, array $spec, AccountGroup $group): ?string
    {
        if ($sameName->count() !== 1) {
            return 'several accounts carry that name';
        }

        $existing = $sameName->first();

        if ($existing->code !== null) {
            return 'it already has its own code';
        }

        if ($spec['direct']) {
            if (! $this->inTargetGroup($existing, $group, true)) {
                return sprintf(
                    'it is not filed directly in "%s", and moving it would reclassify its lines between the trading and profit and loss accounts',
                    $spec['group'],
                );
            }

            $changes = [];
        } else {
            if ($this->headIdOf($existing) !== (int) $group->account_head_id) {
                return sprintf('it is not filed under the "%s" head', $spec['head']);
            }

            $changes = $this->inTargetGroup($existing, $group, false)
                ? []
                : $this->refileChanges($existing, $group, $spec);
        }

        Account::query()->whereKey($existing->id)->update(['code' => $spec['code'], 'name' => $spec['name']] + $changes);

        Log::warning(sprintf(
            'Existing account "%s" (id %d) adopted as inter-branch account %s "%s"%s%s.',
            $existing->name,
            $existing->id,
            $spec['code'],
            $spec['name'],
            $existing->name !== $spec['name'] ? ', renamed to the exact name the posting engine expects' : '',
            isset($changes['account_group_id']) ? ', moved directly into "'.$spec['group'].'"' : '',
        ));

        return null;
    }

    /**
     * The columns that move $account directly into $group when canRefile()
     * allows; otherwise none, with a warning that it was left where it is.
     *
     * @param  array{code: string, name: string, head: string, group: string, direct: bool}  $spec
     * @return array{account_group_id?: int, account_subgroup_id?: null}
     */
    private function refileChanges(Account $account, AccountGroup $group, array $spec): array
    {
        $blocker = $this->refileBlocker($account);

        if ($blocker === null) {
            return ['account_group_id' => (int) $group->id, 'account_subgroup_id' => null];
        }

        Log::warning(sprintf(
            'Inter-branch account %s "%s" (id %d) is filed outside "%s" but %s, so it was left where it is: '
            .'moving it would change how that history or record is reported.',
            $spec['code'],
            $spec['name'],
            $account->id,
            $spec['group'],
            $blocker,
        ));

        return [];
    }

    /**
     * Why $account may not be moved between groups, or null when it may: it
     * has ledger lines (any branch), or a REFERENCES row points at it.
     */
    private function refileBlocker(Account $account): ?string
    {
        if ($account->hasAnyLedgerLine()) {
            return 'it already has ledger lines';
        }

        if ($this->isReferenced($account)) {
            return 'a customer, supplier, item, fixed asset or document points at it';
        }

        return null;
    }

    /**
     * Whether any REFERENCES column holds the account's id. Modelled on
     * Account::deletionBlocker()'s loop, which cannot be reused: it returns
     * early for system codes, which every account here holds or receives.
     */
    private function isReferenced(Account $account): bool
    {
        $schema = DB::getSchemaBuilder();

        foreach (self::REFERENCES as $table => $columns) {
            if (! $schema->hasTable($table)) {
                continue;
            }

            foreach (explode(',', $columns) as $column) {
                if ($schema->hasColumn($table, $column) && DB::table($table)->where($column, $account->id)->exists()) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The group $spec files its account in, found by head name and group
     * name; null when the tenant's chart has no such group.
     *
     * @param  array{code: string, name: string, head: string, group: string, direct: bool}  $spec
     */
    private function targetGroup(array $spec): ?AccountGroup
    {
        return AccountGroup::query()
            ->where('name', $spec['group'])
            ->whereIn('account_head_id', AccountHead::query()->where('name', $spec['head'])->select('id'))
            ->first();
    }

    /**
     * Whether two account names are the same ignoring case and surrounding
     * spaces, as the pre-BR17 name lookup matched them on MySQL.
     */
    private function sameName(string $name, string $expected): bool
    {
        return mb_strtolower(trim($name)) === mb_strtolower($expected);
    }

    /**
     * Whether the account is filed in $group: directly, or (unless $direct)
     * through one of that group's subgroups.
     */
    private function inTargetGroup(Account $account, AccountGroup $group, bool $direct): bool
    {
        if ($account->account_group_id !== null) {
            return (int) $account->account_group_id === (int) $group->id;
        }

        if ($direct || $account->account_subgroup_id === null) {
            return false;
        }

        return (int) AccountSubgroup::whereKey($account->account_subgroup_id)->value('account_group_id') === (int) $group->id;
    }

    /**
     * The account head an account is filed under, through its group or its
     * subgroup's group; null when neither resolves.
     */
    private function headIdOf(Account $account): ?int
    {
        $groupId = $account->account_group_id
            ?? ($account->account_subgroup_id !== null
                ? AccountSubgroup::whereKey($account->account_subgroup_id)->value('account_group_id')
                : null);

        if ($groupId === null) {
            return null;
        }

        $headId = AccountGroup::whereKey($groupId)->value('account_head_id');

        return $headId === null ? null : (int) $headId;
    }
};
