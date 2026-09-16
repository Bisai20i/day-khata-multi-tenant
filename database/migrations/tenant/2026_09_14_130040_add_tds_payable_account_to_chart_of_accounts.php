<?php

use App\Models\Account;
use App\Models\AccountGroup;
use Illuminate\Database\Migrations\Migration;

/**
 * `Purchase::post()`'s TDS-rate feature (item 1) needs a default liability
 * account to withhold into when the clerk picks a rate but does not also
 * pick a TDS account. ChartOfAccountsSeeder never seeded one - its own
 * docblock says so explicitly ("TDS default accounts are deliberately left
 * out here ... a dedicated default isn't load-bearing yet"), because TDS was
 * previously always a manually-selected account.
 *
 * LIA21 is the next free code in the Current Liabilities series after LIA20
 * "Vat Payable" (confirmed unused anywhere in the codebase). Idempotent
 * (guarded by a code lookup, same pattern as
 * 2026_09_13_110000_add_trading_stock_accounts_to_chart_of_accounts.php), so
 * it reaches both existing tenants (via `tenants:migrate`) and brand new
 * ones (migrations run before the seeder during provisioning, so the seeder
 * never collides with this code).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Account::where('code', 'LIA21')->exists()) {
            return;
        }

        $group = AccountGroup::where('name', 'Current Liabilities')->first();

        if (! $group) {
            return;
        }

        Account::create([
            'account_group_id' => $group->id,
            'account_subgroup_id' => null,
            'code' => 'LIA21',
            'name' => 'TDS Payable',
        ]);
    }

    public function down(): void
    {
        $account = Account::where('code', 'LIA21')->first();

        if ($account && ! $account->journalVoucherLines()->exists()) {
            $account->delete();
        }
    }
};
