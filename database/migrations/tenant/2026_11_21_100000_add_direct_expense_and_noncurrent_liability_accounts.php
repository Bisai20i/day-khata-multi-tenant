<?php

use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\AccountHead;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lets the default chart express the reference ERP's statement structure
 * (todo/erp-parity, chunk S1): a place for direct costs above Gross Profit and
 * a non-current liabilities group. ChartOfAccountsSeeder gives new tenants the
 * same rows.
 *
 * - Group "Direct Expenses" under the Expenses head (sibling of Purchase
 *   Accounts and Indirect Expenses): EXE28 Direct Wages, EXE29 Freight
 *   Inward, EXE30 Sub-contract Charges, EXE31 Equipment Hire.
 * - Group "Non-Current Liabilities" under the Liabilities head: LIA25
 *   Long-term Loans.
 * - Under Current Liabilities: LIA23 SSF Payable, LIA24 CIT Payable.
 *
 * EXE28-EXE31 and LIA23-LIA25 are the next free codes after EXE27 and LIA22
 * (EXE11 is the inter-branch account), confirmed unused anywhere in the
 * codebase. Nothing posts to these accounts yet.
 *
 * Idempotent: a group or account already present (a group by head and name,
 * an account by code and name) is left exactly as it is, and an account whose
 * target group or head is missing is skipped rather than half-created. A code
 * that is already taken by an account with a DIFFERENT name stops the migration
 * with a clear message instead: these codes are system accounts (payroll posts
 * to them by code), so quietly adopting a tenant's own account would send
 * postings to the wrong place. Nothing here reads a money column. Literal
 * constants only, so the result does not depend on when it runs.
 */
return new class extends Migration
{
    /**
     * @var list<array{head: string, name: string}>
     */
    private const GROUPS = [
        ['head' => 'Expenses', 'name' => 'Direct Expenses'],
        ['head' => 'Liabilities', 'name' => 'Non-Current Liabilities'],
    ];

    /**
     * @var list<array{code: string, name: string, head: string, group: string}>
     */
    private const ACCOUNTS = [
        ['code' => 'EXE28', 'name' => 'Direct Wages', 'head' => 'Expenses', 'group' => 'Direct Expenses'],
        ['code' => 'EXE29', 'name' => 'Freight Inward', 'head' => 'Expenses', 'group' => 'Direct Expenses'],
        ['code' => 'EXE30', 'name' => 'Sub-contract Charges', 'head' => 'Expenses', 'group' => 'Direct Expenses'],
        ['code' => 'EXE31', 'name' => 'Equipment Hire', 'head' => 'Expenses', 'group' => 'Direct Expenses'],
        ['code' => 'LIA23', 'name' => 'SSF Payable', 'head' => 'Liabilities', 'group' => 'Current Liabilities'],
        ['code' => 'LIA24', 'name' => 'CIT Payable', 'head' => 'Liabilities', 'group' => 'Current Liabilities'],
        ['code' => 'LIA25', 'name' => 'Long-term Loans', 'head' => 'Liabilities', 'group' => 'Non-Current Liabilities'],
    ];

    /**
     * Every table => columns that point at accounts.id when this was written
     * (a frozen copy of Account::REFERENCES plus the ledger lines). An account
     * referenced from any of them is never deleted by down(). Tables or
     * columns missing on a tenant are skipped.
     *
     * @var array<string, string>
     */
    private const REFERENCES = [
        'journal_voucher_lines' => 'account_id',
        'accounts' => 'parent_account_id',
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
     * Run the migrations.
     */
    public function up(): void
    {
        $this->assertNoCodeCollisions();

        foreach (self::GROUPS as $group) {
            $headId = AccountHead::where('name', $group['head'])->value('id');

            if ($headId === null) {
                continue;
            }

            if (! AccountGroup::where('account_head_id', $headId)->where('name', $group['name'])->exists()) {
                AccountGroup::create([
                    'account_head_id' => $headId,
                    'name' => $group['name'],
                ]);
            }
        }

        foreach (self::ACCOUNTS as $account) {
            $existing = Account::where('code', $account['code'])->first(['id', 'name']);

            if ($existing !== null) {
                continue;
            }

            $headId = AccountHead::where('name', $account['head'])->value('id');
            $groupId = $headId === null
                ? null
                : AccountGroup::where('account_head_id', $headId)->where('name', $account['group'])->value('id');

            if ($groupId === null) {
                continue;
            }

            Account::create([
                'account_group_id' => $groupId,
                'account_subgroup_id' => null,
                'code' => $account['code'],
                'name' => $account['name'],
            ]);
        }
    }

    /**
     * Reverse the migrations.
     *
     * Removes only an account this migration seeds (same code and same name),
     * and only when nothing references it: no ledger line, sub-account,
     * party, document or pay component. Written as query deletes because
     * Account's deleting guard may list these codes as system accounts later.
     * The two groups are deliberately left in place: an empty group cannot be
     * told apart from one the tenant created itself, an empty group costs
     * nothing, and up() simply finds it again.
     */
    public function down(): void
    {
        foreach (self::ACCOUNTS as $account) {
            $id = Account::where('code', $account['code'])->where('name', $account['name'])->value('id');

            if ($id !== null && ! $this->isReferenced((int) $id)) {
                Account::query()->whereKey($id)->delete();
            }
        }
    }

    /**
     * Checks every code before anything is created, so a collision stops the
     * run with no group or account half-added. Names compare case-insensitively
     * and ignore surrounding spaces.
     */
    private function assertNoCodeCollisions(): void
    {
        foreach (self::ACCOUNTS as $account) {
            $existing = Account::where('code', $account['code'])->first(['id', 'name']);

            if ($existing !== null && strcasecmp(trim((string) $existing->name), $account['name']) !== 0) {
                throw new RuntimeException(
                    "Account code {$account['code']} is already used by \"{$existing->name}\". "
                    ."The default chart reserves it for \"{$account['name']}\", which payroll and the statements post to by code. "
                    .'Give that account a different code, then run the migration again.'
                );
            }
        }
    }

    private function isReferenced(int $accountId): bool
    {
        foreach (self::REFERENCES as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach (explode(',', $columns) as $column) {
                if (Schema::hasColumn($table, $column) && DB::table($table)->where($column, $accountId)->exists()) {
                    return true;
                }
            }
        }

        return false;
    }
};
