<?php

namespace Database\Seeders\Tenant;

use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\AccountHead;
use App\Models\AccountSubgroup;
use Illuminate\Database\Seeder;

class ChartOfAccountsSeeder extends Seeder
{
    /**
     * Starter chart of accounts every tenant is provisioned with, ported
     * from the legacy app's DefaultMainAccountSeeder (day_khata's
     * database/seeders/DefaultMainAccountSeeder.php) onto the normalized
     * account_heads/account_groups/account_subgroups/accounts hierarchy.
     *
     * TDS default accounts are deliberately left out here - TDS already
     * works via a manually-selected tds_account_id on Sale/Purchase, so a
     * dedicated default isn't load-bearing yet. Fixed-asset and
     * asset-disposal default accounts (Accumulated Depreciation,
     * Depreciation Expense, Gain/Loss on Asset Disposal) ARE seeded below,
     * added when the Fixed Assets phase (migration_plan 05-phase-plan.md
     * Phase 3) was built - see App\Models\FixedAsset.
     *
     * "Sundry Debtors" and "Sundry Creditors" are load-bearing names: they
     * are looked up by exact string in App\Models\Concerns\HasLedgerAccount,
     * used by Customer and Supplier. Do not rename without updating that
     * side too. "Sales Agents" is the same kind of load-bearing name, used
     * by App\Models\Agent - filed under Current Liabilities alongside
     * Sundry Creditors since a posted commission is money the business owes
     * the agent, not an asset. EXE22 "Sales Commission Expense" is the
     * matching expense account Sale::post() debits when a commission is
     * posted (see App\Models\Sale), added when the Sales Agent Commission
     * phase was built.
     *
     * "Income" and "Expenses" are marked is_profit_and_loss=true - the
     * ledger's year-end close (App\Models\FiscalYear::close()) sweeps every
     * account under these two heads to zero and posts the net to the
     * "Profit & Loss" account (code CA2, under Capital) rather than carrying
     * a balance forward. Every other head carries forward as-is.
     */
    public function run(): void
    {
        $profitAndLossHeads = ['Income', 'Expenses'];

        $heads = collect(['Assets', 'Liabilities', 'Income', 'Expenses', 'Capital'])
            ->mapWithKeys(fn (string $name) => [$name => AccountHead::create([
                'name' => $name,
                'is_profit_and_loss' => in_array($name, $profitAndLossHeads, true),
            ])]);

        $groups = collect([
            ['head' => 'Assets', 'name' => 'Current Assets'],
            ['head' => 'Assets', 'name' => 'Fixed Assets'],
            ['head' => 'Liabilities', 'name' => 'Current Liabilities'],
            ['head' => 'Income', 'name' => 'Sales Accounts'],
            ['head' => 'Income', 'name' => 'Indirect Income'],
            ['head' => 'Expenses', 'name' => 'Purchase Accounts'],
            ['head' => 'Expenses', 'name' => 'Indirect Expenses'],
            ['head' => 'Capital', 'name' => 'Capital Account'],
        ])->mapWithKeys(fn (array $group) => [$group['name'] => AccountGroup::create([
            'account_head_id' => $heads[$group['head']]->id,
            'name' => $group['name'],
        ])]);

        $subgroups = collect([
            ['group' => 'Current Assets', 'name' => 'Sundry Debtors'],
            ['group' => 'Current Assets', 'name' => 'Cash-In-Hand'],
            ['group' => 'Current Assets', 'name' => 'Stock'],
            ['group' => 'Current Liabilities', 'name' => 'Sundry Creditors'],
            ['group' => 'Current Liabilities', 'name' => 'Sales Agents'],
            ['group' => 'Capital Account', 'name' => 'Reserve Surplus'],
        ])->mapWithKeys(fn (array $subgroup) => [$subgroup['name'] => AccountSubgroup::create([
            'account_group_id' => $groups[$subgroup['group']]->id,
            'name' => $subgroup['name'],
        ])]);

        $defaultAccounts = [
            ['subgroup' => 'Cash-In-Hand', 'code' => 'AS1', 'name' => 'Cash In Hand'],
            ['subgroup' => 'Reserve Surplus', 'code' => 'CA2', 'name' => 'Profit & Loss'],
            ['subgroup' => 'Stock', 'code' => 'AS11', 'name' => 'Opening Stock'],
            ['group' => 'Sales Accounts', 'code' => 'INI20', 'name' => 'Sales Account'],
            ['group' => 'Sales Accounts', 'code' => 'INI21', 'name' => 'Sales Return'],
            ['group' => 'Purchase Accounts', 'code' => 'EXE8', 'name' => 'Purchases Account'],
            ['group' => 'Purchase Accounts', 'code' => 'EXE81', 'name' => 'Purchase Return'],
            ['group' => 'Current Liabilities', 'code' => 'LIA20', 'name' => 'Vat Payable'],
            ['group' => 'Current Assets', 'code' => 'ASA23', 'name' => 'Vat Receivable'],
            ['group' => 'Fixed Assets', 'code' => 'AS31', 'name' => 'Accumulated Depreciation'],
            ['group' => 'Indirect Expenses', 'code' => 'EXE20', 'name' => 'Depreciation Expense'],
            ['group' => 'Indirect Expenses', 'code' => 'EXE21', 'name' => 'Loss on Asset Disposal'],
            ['group' => 'Indirect Income', 'code' => 'INI30', 'name' => 'Gain on Asset Disposal'],
            ['group' => 'Indirect Expenses', 'code' => 'EXE22', 'name' => 'Sales Commission Expense'],
        ];

        foreach ($defaultAccounts as $account) {
            Account::create([
                'account_group_id' => isset($account['group']) ? $groups[$account['group']]->id : null,
                'account_subgroup_id' => isset($account['subgroup']) ? $subgroups[$account['subgroup']]->id : null,
                'code' => $account['code'],
                'name' => $account['name'],
            ]);
        }
    }
}
