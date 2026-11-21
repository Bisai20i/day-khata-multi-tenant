<?php

use App\Models\Account;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Employer social security and CIT contributions in payroll: the two expense
 * accounts the payroll voucher debits for the employer's share (the employee's
 * withholding comes out of gross pay; the employer's share is an extra cost).
 *
 * - EXE32 Employer SSF Contribution
 * - EXE33 Employer CIT Contribution
 *
 * Both sit in group "Indirect Expenses" under the Expenses head, next to EXE25
 * Salaries & Wages. The credits go to the existing LIA23 SSF Payable and LIA24
 * CIT Payable (added by 2026_11_21_100000). ChartOfAccountsSeeder gives new
 * tenants the same rows.
 *
 * Idempotent: an account already present with the same code AND name is left as
 * it is. An account with the same code but a DIFFERENT name is never adopted:
 * payroll would silently post into someone else's account, so the migration
 * stops with an exception naming it and the owner renames or recodes that
 * account first. All collisions are checked before anything is created, so a
 * failed run leaves nothing half-added. A tenant whose Expenses head or
 * Indirect Expenses group is missing is skipped (nothing to attach to).
 *
 * Literal constants only (.ai/rules/migrations.md); no money column is read.
 */
return new class extends Migration
{
    private const HEAD = 'Expenses';

    private const GROUP = 'Indirect Expenses';

    /**
     * @var list<array{code: string, name: string, name_np: string}>
     */
    private const ACCOUNTS = [
        ['code' => 'EXE32', 'name' => 'Employer SSF Contribution', 'name_np' => 'नियोक्ता एसएसएफ योगदान'],
        ['code' => 'EXE33', 'name' => 'Employer CIT Contribution', 'name_np' => 'नियोक्ता सीआईटी योगदान'],
    ];

    /**
     * Every table => columns that point at accounts.id when this was written
     * (a frozen copy of the list in 2026_11_21_100000, itself a copy of
     * Account::REFERENCES plus the ledger lines). An account referenced from any
     * of them is never deleted by down(). Tables or columns missing on a tenant
     * are skipped.
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

    public function up(): void
    {
        if (! Schema::hasTable('accounts')) {
            return;
        }

        $missing = [];

        foreach (self::ACCOUNTS as $account) {
            $existing = DB::table('accounts')->where('code', $account['code'])->first(['id', 'code', 'name']);

            if ($existing === null) {
                $missing[] = $account;

                continue;
            }

            if (strcasecmp(trim((string) $existing->name), $account['name']) !== 0) {
                throw new RuntimeException(
                    "Cannot add system account {$account['code']} \"{$account['name']}\": an account with that code already exists "
                    ."(id {$existing->id}, named \"{$existing->name}\"). Payroll posts the employer contribution to this code, "
                    .'so rename or recode the existing account first, then run the migration again.'
                );
            }
        }

        if ($missing === []) {
            return;
        }

        $headId = DB::table('account_heads')->where('name', self::HEAD)->value('id');
        $groupId = $headId === null
            ? null
            : DB::table('account_groups')->where('account_head_id', $headId)->where('name', self::GROUP)->value('id');

        if ($groupId === null) {
            return;
        }

        foreach ($missing as $account) {
            Account::create([
                'account_group_id' => $groupId,
                'account_subgroup_id' => null,
                'code' => $account['code'],
                'name' => $account['name'],
                'name_np' => $account['name_np'],
            ]);
        }
    }

    /**
     * Removes an account this migration adds (same code and name) only while
     * nothing references it: no ledger line, sub-account, party, document or
     * pay component (see REFERENCES). A
     * query delete, so no model guard refuses it (these codes are system
     * accounts). The Indirect Expenses group is never touched.
     */
    public function down(): void
    {
        if (! Schema::hasTable('accounts')) {
            return;
        }

        foreach (self::ACCOUNTS as $account) {
            $id = DB::table('accounts')->where('code', $account['code'])->where('name', $account['name'])->value('id');

            if ($id !== null && ! $this->isReferenced((int) $id)) {
                DB::table('accounts')->where('id', $id)->delete();
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
