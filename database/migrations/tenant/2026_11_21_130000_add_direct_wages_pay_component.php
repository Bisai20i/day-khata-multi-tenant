<?php

use App\Models\Account;
use App\Models\PayComponent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the "Direct Wages" starter pay component (labour / site workers) to a
 * tenant whose chart already has EXE28 Direct Wages (added by
 * 2026_11_21_100000_add_direct_expense_and_noncurrent_liability_accounts). Its
 * amounts are debited to EXE28, a Direct Expense above Gross Profit, while the
 * Salary component keeps posting monthly salaries to EXE25 under Indirect
 * Expenses (todo/erp-parity, chunk H1). New tenants get the same row from
 * ChartOfAccountsSeeder via HrAccounts::STARTER_COMPONENTS.
 *
 * Literal constants only (.ai/rules/migrations.md). Idempotent: skipped when
 * the component code already exists, or when EXE28 is missing or is not filed
 * under the Expenses head (a tenant whose chart was customised is left
 * untouched rather than given a component that would post somewhere odd).
 * Placed after the tenant's current last component so custom components keep
 * their order.
 */
return new class extends Migration
{
    private const CODE = 'DIRECT_WAGES';

    private const NAME = 'Direct Wages';

    private const ACCOUNT_CODE = 'EXE28';

    public function up(): void
    {
        if (! Schema::hasTable('hr_pay_components') || PayComponent::where('code', self::CODE)->exists()) {
            return;
        }

        $accountId = Account::query()
            ->where('code', self::ACCOUNT_CODE)
            ->where(fn (Builder $query) => $query
                ->whereHas('group.accountHead', fn (Builder $head) => $head->where('name', 'Expenses'))
                ->orWhereHas('subgroup.accountGroup.accountHead', fn (Builder $head) => $head->where('name', 'Expenses')))
            ->value('id');

        if ($accountId === null) {
            return;
        }

        PayComponent::create([
            'code' => self::CODE,
            'name' => self::NAME,
            'kind' => 'earning',
            'expense_account_id' => $accountId,
            'is_system' => true,
            'is_active' => true,
            'sort_order' => (int) PayComponent::query()->max('sort_order') + 1,
        ]);
    }

    /**
     * Removes the component only while nothing uses it: no payslip line item
     * and no staff pay item references it. A query delete, so no model guard
     * or observer can refuse it.
     */
    public function down(): void
    {
        if (! Schema::hasTable('hr_pay_components')) {
            return;
        }

        $id = DB::table('hr_pay_components')->where('code', self::CODE)->value('id');

        if ($id === null) {
            return;
        }

        $inUse = DB::table('hr_payroll_line_items')->where('pay_component_id', $id)->exists()
            || DB::table('hr_staff_pay_items')->where('pay_component_id', $id)->exists();

        if (! $inUse) {
            DB::table('hr_pay_components')->where('id', $id)->delete();
        }
    }
};
