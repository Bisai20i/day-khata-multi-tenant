<?php

namespace Database\Seeders\Tenant;

use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\AccountHead;
use App\Models\AccountSubgroup;
use App\Models\PayComponent;
use App\Support\Branches\BranchAccounts;
use App\Support\Branches\BranchBalancer;
use App\Support\Hr\HrAccounts;
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
     * Inventory is periodic in this ledger (CONTRACTS C10): no stock
     * document posts a journal, so stock only reaches the books through the
     * trading entries App\Models\FiscalYear::close() posts on the year's
     * last day. Three accounts carry that, and their codes are constants on
     * FiscalYear (STOCK_IN_HAND_CODE / OPENING_STOCK_CODE /
     * CLOSING_STOCK_CODE) because close() and AccountingReportController
     * both look them up by code:
     *
     * - AS11 "Stock in Hand", a Current Assets -> Stock balance-sheet
     *   account. It was seeded as "Opening Stock" originally, which was a
     *   misnomer - it is the asset, not the trading expense - and it was
     *   never posted to at all (audit P0-17).
     * - EXE9 "Opening Stock", under Purchase Accounts: the trading-account
     *   debit for stock carried in from last year.
     * - INI22 "Closing Stock", under Sales Accounts: the trading-account
     *   credit that takes unsold stock back out of cost of sales.
     *
     * Existing tenants get all three from
     * 2026_09_13_110000_add_trading_stock_accounts_to_chart_of_accounts.
     *
     * "Income" and "Expenses" are marked is_profit_and_loss=true - the
     * ledger's year-end close (App\Models\FiscalYear::close()) sweeps every
     * account under these two heads to zero and posts the net to the
     * "Profit & Loss" account (code CA2, under Capital) rather than carrying
     * a balance forward. Every other head carries forward as-is.
     *
     * Every head, group, subgroup and account also carries a Nepali display
     * name (name_np). Existing tenants get the same values from
     * 2026_11_21_120100_backfill_nepali_names_for_default_accounts.
     */
    public function run(): void
    {
        $profitAndLossHeads = ['Income', 'Expenses'];

        $heads = collect([
            'Assets' => 'सम्पत्ति',
            'Liabilities' => 'दायित्व',
            'Income' => 'आय',
            'Expenses' => 'खर्च',
            'Capital' => 'पुँजी',
        ])->mapWithKeys(fn (string $nameNp, string $name) => [$name => AccountHead::create([
            'name' => $name,
            'name_np' => $nameNp,
            'is_profit_and_loss' => in_array($name, $profitAndLossHeads, true),
        ])]);

        $groups = collect([
            ['head' => 'Assets', 'name' => 'Current Assets', 'name_np' => 'चालु सम्पत्ति'],
            ['head' => 'Assets', 'name' => 'Fixed Assets', 'name_np' => 'स्थिर सम्पत्ति'],
            ['head' => 'Liabilities', 'name' => 'Current Liabilities', 'name_np' => 'चालु दायित्व'],
            ['head' => 'Liabilities', 'name' => 'Non-Current Liabilities', 'name_np' => 'गैर-चालु दायित्व'],
            ['head' => 'Income', 'name' => 'Sales Accounts', 'name_np' => 'बिक्री खाताहरू'],
            ['head' => 'Income', 'name' => 'Indirect Income', 'name_np' => 'अप्रत्यक्ष आय'],
            ['head' => 'Expenses', 'name' => 'Purchase Accounts', 'name_np' => 'खरिद खाताहरू'],
            ['head' => 'Expenses', 'name' => 'Direct Expenses', 'name_np' => 'प्रत्यक्ष खर्च'],
            ['head' => 'Expenses', 'name' => 'Indirect Expenses', 'name_np' => 'अप्रत्यक्ष खर्च'],
            ['head' => 'Capital', 'name' => 'Capital Account', 'name_np' => 'पुँजी खाता'],
        ])->mapWithKeys(fn (array $group) => [$group['name'] => AccountGroup::create([
            'account_head_id' => $heads[$group['head']]->id,
            'name' => $group['name'],
            'name_np' => $group['name_np'],
        ])]);

        $subgroups = collect([
            ['group' => 'Current Assets', 'name' => 'Sundry Debtors', 'name_np' => 'विविध देनदार'],
            ['group' => 'Current Assets', 'name' => 'Cash-In-Hand', 'name_np' => 'हातमा नगद'],
            ['group' => 'Current Assets', 'name' => 'Bank Accounts', 'name_np' => 'बैंक खाताहरू'],
            ['group' => 'Current Assets', 'name' => 'Stock', 'name_np' => 'मौज्दात'],
            ['group' => 'Current Liabilities', 'name' => 'Sundry Creditors', 'name_np' => 'विविध लेनदार'],
            ['group' => 'Current Liabilities', 'name' => 'Sales Agents', 'name_np' => 'बिक्री एजेन्टहरू'],
            ['group' => 'Current Liabilities', 'name' => 'Salary Payable', 'name_np' => 'तलब भुक्तानी योग्य'],
            ['group' => 'Capital Account', 'name' => 'Reserve Surplus', 'name_np' => 'जगेडा तथा बचत'],
        ])->mapWithKeys(fn (array $subgroup) => [$subgroup['name'] => AccountSubgroup::create([
            'account_group_id' => $groups[$subgroup['group']]->id,
            'name' => $subgroup['name'],
            'name_np' => $subgroup['name_np'],
        ])]);

        $defaultAccounts = [
            ['subgroup' => 'Cash-In-Hand', 'code' => 'AS1', 'name' => 'Cash In Hand', 'name_np' => 'हातमा नगद'],
            ['subgroup' => 'Reserve Surplus', 'code' => 'CA2', 'name' => 'Profit & Loss', 'name_np' => 'नाफा नोक्सान'],
            ['subgroup' => 'Stock', 'code' => 'AS11', 'name' => 'Stock in Hand', 'name_np' => 'हातमा रहेको मौज्दात'],
            ['subgroup' => 'Stock', 'code' => 'AS12', 'name' => 'Raw Material Inventory', 'name_np' => 'कच्चा पदार्थ मौज्दात'],
            ['subgroup' => 'Stock', 'code' => 'AS13', 'name' => 'Work in Process', 'name_np' => 'प्रक्रियामा रहेको काम'],
            ['subgroup' => 'Stock', 'code' => 'AS14', 'name' => 'Finished Goods Inventory', 'name_np' => 'तयारी मालसामान मौज्दात'],
            ['group' => 'Sales Accounts', 'code' => 'INI20', 'name' => 'Sales Account', 'name_np' => 'बिक्री'],
            ['group' => 'Sales Accounts', 'code' => 'INI21', 'name' => 'Sales Return', 'name_np' => 'बिक्री फिर्ता'],
            ['group' => 'Sales Accounts', 'code' => 'INI22', 'name' => 'Closing Stock', 'name_np' => 'अन्तिम मौज्दात'],
            ['group' => 'Purchase Accounts', 'code' => 'EXE8', 'name' => 'Purchases Account', 'name_np' => 'खरिद'],
            ['group' => 'Purchase Accounts', 'code' => 'EXE81', 'name' => 'Purchase Return', 'name_np' => 'खरिद फिर्ता'],
            ['group' => 'Purchase Accounts', 'code' => 'EXE9', 'name' => 'Opening Stock', 'name_np' => 'प्रारम्भिक मौज्दात'],
            ['group' => 'Purchase Accounts', 'code' => 'EXE10', 'name' => 'Cost of Goods Sold', 'name_np' => 'बिक्री गरिएको मालको लागत'],
            ['group' => 'Current Liabilities', 'code' => 'LIA20', 'name' => 'Vat Payable', 'name_np' => 'भ्याट भुक्तानी योग्य'],
            ['group' => 'Current Liabilities', 'code' => 'LIA21', 'name' => 'TDS Payable', 'name_np' => 'टीडीएस भुक्तानी योग्य'],
            ['group' => 'Current Assets', 'code' => 'ASA23', 'name' => 'Vat Receivable', 'name_np' => 'भ्याट प्राप्य'],
            ['group' => 'Fixed Assets', 'code' => 'AS31', 'name' => 'Accumulated Depreciation', 'name_np' => 'सञ्चित ह्रासकट्टी'],
            ['group' => 'Indirect Expenses', 'code' => 'EXE20', 'name' => 'Depreciation Expense', 'name_np' => 'ह्रासकट्टी खर्च'],
            ['group' => 'Indirect Expenses', 'code' => 'EXE21', 'name' => 'Loss on Asset Disposal', 'name_np' => 'सम्पत्ति बिक्रीमा नोक्सान'],
            ['group' => 'Indirect Income', 'code' => 'INI30', 'name' => 'Gain on Asset Disposal', 'name_np' => 'सम्पत्ति बिक्रीमा नाफा'],
            ['group' => 'Indirect Income', 'code' => 'INI31', 'name' => 'Round Off', 'name_np' => 'पूर्णांकन (राउन्ड अफ)'],
            ['group' => 'Indirect Expenses', 'code' => 'EXE22', 'name' => 'Sales Commission Expense', 'name_np' => 'बिक्री कमिसन खर्च'],
            ['group' => 'Indirect Expenses', 'code' => 'EXE23', 'name' => 'Inventory Adjustment', 'name_np' => 'मौज्दात समायोजन'],
            ['group' => 'Indirect Expenses', 'code' => 'EXE24', 'name' => 'Production Loss', 'name_np' => 'उत्पादन नोक्सान'],
            ['group' => 'Current Liabilities', 'code' => 'LIA22', 'name' => 'Salary Payable', 'name_np' => 'तलब भुक्तानी योग्य'],
            ['group' => 'Current Assets', 'code' => 'ASA24', 'name' => 'Staff Advances Receivable', 'name_np' => 'कर्मचारी पेश्की प्राप्य'],
            ['group' => 'Indirect Expenses', 'code' => 'EXE25', 'name' => 'Salaries & Wages', 'name_np' => 'तलब तथा ज्याला'],
            ['group' => 'Indirect Expenses', 'code' => 'EXE26', 'name' => 'Staff Bonus', 'name_np' => 'कर्मचारी बोनस'],
            ['group' => 'Indirect Expenses', 'code' => 'EXE27', 'name' => 'Staff Allowances', 'name_np' => 'कर्मचारी भत्ता'],
            // Employer SSF / CIT contributions posted by payroll. Existing
            // tenants get them from 2026_11_21_180000_add_employer_contribution_expense_accounts.
            ['group' => 'Indirect Expenses', 'code' => HrAccounts::EMPLOYER_SSF_EXPENSE, 'name' => 'Employer SSF Contribution', 'name_np' => 'नियोक्ता एसएसएफ योगदान'],
            ['group' => 'Indirect Expenses', 'code' => HrAccounts::EMPLOYER_CIT_EXPENSE, 'name' => 'Employer CIT Contribution', 'name_np' => 'नियोक्ता सीआईटी योगदान'],
            // ERP-parity chart additions (todo/erp-parity S1). Existing tenants
            // get them from 2026_11_21_100000_add_direct_expense_and_noncurrent_liability_accounts.
            ['group' => 'Direct Expenses', 'code' => HrAccounts::DIRECT_WAGES, 'name' => 'Direct Wages', 'name_np' => 'प्रत्यक्ष ज्याला'],
            ['group' => 'Direct Expenses', 'code' => 'EXE29', 'name' => 'Freight Inward', 'name_np' => 'आवक ढुवानी'],
            ['group' => 'Direct Expenses', 'code' => 'EXE30', 'name' => 'Sub-contract Charges', 'name_np' => 'उप-ठेक्का शुल्क'],
            ['group' => 'Direct Expenses', 'code' => 'EXE31', 'name' => 'Equipment Hire', 'name_np' => 'उपकरण भाडा'],
            ['group' => 'Current Liabilities', 'code' => HrAccounts::SSF_PAYABLE, 'name' => 'SSF Payable', 'name_np' => 'एसएसएफ भुक्तानी योग्य'],
            ['group' => 'Current Liabilities', 'code' => HrAccounts::CIT_PAYABLE, 'name' => 'CIT Payable', 'name_np' => 'सीआईटी भुक्तानी योग्य'],
            ['group' => 'Non-Current Liabilities', 'code' => 'LIA25', 'name' => 'Long-term Loans', 'name_np' => 'दीर्घकालीन ऋण'],
            // Inter-branch accounts (plan 12, BR17; codes and placement
            // explained on App\Support\Branches\BranchAccounts). Existing
            // tenants get them from 2026_11_10_300000_add_inter_branch_accounts.
            ['group' => 'Current Assets', 'code' => BranchAccounts::CLEARING_ACCOUNT_CODE, 'name' => BranchBalancer::CLEARING_ACCOUNT_NAME, 'name_np' => 'अन्तर-शाखा क्लियरिङ'],
            ['group' => 'Sales Accounts', 'code' => BranchAccounts::STOCK_TRANSFERRED_CODE, 'name' => BranchAccounts::STOCK_TRANSFERRED_NAME, 'name_np' => 'शाखामा हस्तान्तरित मौज्दात'],
            ['group' => 'Purchase Accounts', 'code' => BranchAccounts::STOCK_RECEIVED_CODE, 'name' => BranchAccounts::STOCK_RECEIVED_NAME, 'name_np' => 'शाखाबाट प्राप्त मौज्दात'],
        ];

        foreach ($defaultAccounts as $account) {
            Account::create([
                'account_group_id' => isset($account['group']) ? $groups[$account['group']]->id : null,
                'account_subgroup_id' => isset($account['subgroup']) ? $subgroups[$account['subgroup']]->id : null,
                'code' => $account['code'],
                'name' => $account['name'],
                'name_np' => $account['name_np'],
            ]);
        }

        // The HR module's starter pay components (Salary, Bonus, Allowance),
        // each mapped to its expense account above. Existing tenants get the
        // same rows from 2026_11_05_100100_add_hr_accounts_and_pay_components.
        foreach (HrAccounts::STARTER_COMPONENTS as $sortOrder => $component) {
            PayComponent::create([
                'code' => $component['code'],
                'name' => $component['name'],
                'kind' => PayComponent::KIND_EARNING,
                'expense_account_id' => Account::where('code', $component['account'])->value('id'),
                'is_system' => true,
                'is_active' => true,
                'is_contribution_base' => $component['contribution_base'],
                'sort_order' => $sortOrder + 1,
            ]);
        }
    }
}
