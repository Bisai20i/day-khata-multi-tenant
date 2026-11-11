<?php

use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\PayComponent;
use Illuminate\Database\Migrations\Migration;

/**
 * Adds the five HR payroll accounts and the three starter pay components to a
 * tenant whose chart of accounts already exists (new tenants get them from
 * ChartOfAccountsSeeder, which runs after the migrations).
 *
 * The lists are frozen here as literals (.ai/rules/migrations.md): the result
 * must not depend on when this migration runs. Groups are resolved by name;
 * a tenant whose chart was customised away from the seeded groups is left
 * untouched rather than half-migrated. Idempotent: every row is guarded by a
 * code lookup, so a re-run adds nothing.
 */
return new class extends Migration
{
    /**
     * @var list<array{code: string, name: string, group: string}>
     */
    private const ACCOUNTS = [
        ['code' => 'LIA22', 'name' => 'Salary Payable', 'group' => 'Current Liabilities'],
        ['code' => 'ASA24', 'name' => 'Staff Advances Receivable', 'group' => 'Current Assets'],
        ['code' => 'EXE25', 'name' => 'Salaries & Wages', 'group' => 'Indirect Expenses'],
        ['code' => 'EXE26', 'name' => 'Staff Bonus', 'group' => 'Indirect Expenses'],
        ['code' => 'EXE27', 'name' => 'Staff Allowances', 'group' => 'Indirect Expenses'],
    ];

    /**
     * @var list<array{code: string, name: string, account: string}>
     */
    private const COMPONENTS = [
        ['code' => 'SALARY', 'name' => 'Salary', 'account' => 'EXE25'],
        ['code' => 'BONUS', 'name' => 'Bonus', 'account' => 'EXE26'],
        ['code' => 'ALLOWANCE', 'name' => 'Allowance', 'account' => 'EXE27'],
    ];

    public function up(): void
    {
        foreach (self::ACCOUNTS as $spec) {
            if (Account::where('code', $spec['code'])->exists()) {
                continue;
            }

            $group = AccountGroup::where('name', $spec['group'])->first();

            if (! $group) {
                continue;
            }

            Account::create([
                'account_group_id' => $group->id,
                'account_subgroup_id' => null,
                'code' => $spec['code'],
                'name' => $spec['name'],
            ]);
        }

        foreach (self::COMPONENTS as $index => $spec) {
            if (PayComponent::where('code', $spec['code'])->exists()) {
                continue;
            }

            $accountId = Account::where('code', $spec['account'])->value('id');

            if ($accountId === null) {
                continue;
            }

            PayComponent::create([
                'code' => $spec['code'],
                'name' => $spec['name'],
                'kind' => 'earning',
                'expense_account_id' => $accountId,
                'is_system' => true,
                'is_active' => true,
                'sort_order' => $index + 1,
            ]);
        }
    }

    public function down(): void
    {
        // Components first (they reference the expense accounts). Only
        // accounts with no ledger history are removed; a query delete on
        // purpose, because every code here is in Account::SYSTEM_CODES and a
        // model delete() would be refused by Account's deleting guard.
        PayComponent::query()
            ->whereIn('code', array_column(self::COMPONENTS, 'code'))
            ->whereDoesntHave('payrollLineItems')
            ->whereDoesntHave('staffPayItems')
            ->delete();

        Account::query()
            ->whereIn('code', array_column(self::ACCOUNTS, 'code'))
            ->whereDoesntHave('journalVoucherLines')
            ->whereNotIn('id', PayComponent::query()->select('expense_account_id'))
            ->delete();
    }
};
