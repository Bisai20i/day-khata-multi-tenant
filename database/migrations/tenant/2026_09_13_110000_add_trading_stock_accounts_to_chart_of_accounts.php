<?php

use App\Models\Account;
use App\Models\AccountGroup;
use Illuminate\Database\Migrations\Migration;

/**
 * Inventory is periodic in this app's ledger (CONTRACTS C10): no stock
 * document posts a journal, so stock only ever reaches the books through
 * the year-end trading entries App\Models\FiscalYear::close() posts. Those
 * entries need three accounts, and a tenant provisioned before this
 * migration has only one of them - under the wrong name.
 *
 * - AS11 was seeded as "Opening Stock" but filed under Current Assets ->
 *   Stock, i.e. it is the BALANCE SHEET asset, not the trading-account
 *   expense. It was never posted to by anything (audit P0-17), so renaming
 *   it to "Stock in Hand" only fixes the label; no balance moves.
 * - EXE9 "Opening Stock" is new, under Purchase Accounts (head "Expenses",
 *   is_profit_and_loss = true): the trading-account debit for stock brought
 *   in from last year.
 * - INI22 "Closing Stock" is new, under Sales Accounts (head "Income"):
 *   the trading-account credit that takes unsold stock back out of cost of
 *   sales.
 *
 * EXE9 and INI22 are the next free codes in the two series
 * ChartOfAccountsSeeder already uses (EXE8/EXE81/EXE20/EXE21/EXE22 and
 * INI20/INI21/INI30), confirmed unused anywhere in the codebase.
 *
 * Idempotent throughout: every write is guarded by a lookup, so a re-run
 * changes nothing. Nothing here reads a money column, so the SQLite REAL
 * affinity trap that bit the Phase 2 backfills does not apply.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Account::query()
            ->where('code', 'AS11')
            ->where('name', 'Opening Stock')
            ->update(['name' => 'Stock in Hand']);

        $this->createAccount('EXE9', 'Opening Stock', 'Purchase Accounts');
        $this->createAccount('INI22', 'Closing Stock', 'Sales Accounts');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Only ever removes an account that carries no ledger history: a
        // tenant that has already closed a year through these accounts keeps
        // them, because dropping them would orphan posted voucher lines.
        foreach (['EXE9', 'INI22'] as $code) {
            $account = Account::where('code', $code)->first();

            if ($account && ! $account->journalVoucherLines()->exists()) {
                $account->delete();
            }
        }

        Account::query()
            ->where('code', 'AS11')
            ->where('name', 'Stock in Hand')
            ->update(['name' => 'Opening Stock']);
    }

    /**
     * Creates one account under a named AccountGroup, doing nothing when the
     * code already exists or the group is missing (a tenant whose chart was
     * customised away from the seeded groups is left untouched rather than
     * half-migrated).
     */
    private function createAccount(string $code, string $name, string $groupName): void
    {
        if (Account::where('code', $code)->exists()) {
            return;
        }

        $group = AccountGroup::where('name', $groupName)->first();

        if (! $group) {
            return;
        }

        Account::create([
            'account_group_id' => $group->id,
            'account_subgroup_id' => null,
            'code' => $code,
            'name' => $name,
        ]);
    }
};
