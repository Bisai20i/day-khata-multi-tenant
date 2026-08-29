<?php

use App\Models\AccountHead;
use Illuminate\Database\Migrations\Migration;

/**
 * The `is_profit_and_loss` column was added to `account_heads` by
 * 2026_08_25_100009_add_is_profit_and_loss_to_account_heads_table with a
 * schema default of false and no backfill - fine for any tenant provisioned
 * afterwards (ChartOfAccountsSeeder sets the flag correctly at seed time),
 * but any tenant already provisioned before that migration ran was left
 * with `is_profit_and_loss=false` on every head, including "Income" and
 * "Expenses", forever. That silently breaks FiscalYear::close(): with no
 * head flagged as profit-and-loss, postClosingEntries() finds nothing to
 * zero and never posts a ClosingEntry, so year-end closing runs "successfully"
 * but does nothing - no P&L account is zeroed and no net profit sweeps into
 * "Profit & Loss" (CA2), leaving a closed year's Balance Sheet silently
 * unbalanced. Found via a real HTTP-driven smoke test on a pre-existing dev
 * tenant, not by any automated test (RefreshDatabase always seeds fresh, so
 * every test tenant already gets the correct flag and never exercises this
 * gap). Portable data-only fix: flip the flag for exactly the two head names
 * ChartOfAccountsSeeder itself uses, wherever it's still false.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        AccountHead::query()
            ->whereIn('name', ['Income', 'Expenses'])
            ->where('is_profit_and_loss', false)
            ->update(['is_profit_and_loss' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Deliberately not reversible: there's no way to tell apart a head
        // this migration flipped from one that was already correct, and
        // reversing would just reintroduce the bug this fixes.
    }
};
