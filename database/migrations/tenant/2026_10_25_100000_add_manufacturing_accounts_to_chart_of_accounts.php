<?php

use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\AccountSubgroup;
use Illuminate\Database\Migrations\Migration;

/**
 * Hybrid perpetual inventory (manufacturing MC2) needs six system accounts
 * that no tenant provisioned before this migration has:
 *
 * - AS12 Raw Material Inventory, AS13 Work in Process, AS14 Finished Goods
 *   Inventory: balance-sheet assets under Current Assets -> subgroup Stock,
 *   next to AS11 "Stock in Hand" (which stays the periodic, trading-item
 *   asset).
 * - EXE10 Cost of Goods Sold: Expenses -> Purchase Accounts (trading section).
 * - EXE23 Inventory Adjustment and EXE24 Production Loss: Expenses ->
 *   Indirect Expenses.
 *
 * Group and subgroup are resolved by name, like the 2026_09_13_110000
 * migration. A tenant whose chart was customised away from the seeded
 * groups is left untouched for that account rather than half-migrated.
 * Idempotent: every write is guarded by a code lookup. No money column is
 * read, so the SQLite REAL-affinity trap does not apply.
 */
return new class extends Migration
{
    /**
     * Code => [name, group name, subgroup name (null for a direct group account)].
     *
     * @var array<string, array{0: string, 1: string, 2: string|null}>
     */
    private const ACCOUNTS = [
        'AS12' => ['Raw Material Inventory', 'Current Assets', 'Stock'],
        'AS13' => ['Work in Process', 'Current Assets', 'Stock'],
        'AS14' => ['Finished Goods Inventory', 'Current Assets', 'Stock'],
        'EXE10' => ['Cost of Goods Sold', 'Purchase Accounts', null],
        'EXE23' => ['Inventory Adjustment', 'Indirect Expenses', null],
        'EXE24' => ['Production Loss', 'Indirect Expenses', null],
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (self::ACCOUNTS as $code => [$name, $groupName, $subgroupName]) {
            $this->createAccount($code, $name, $groupName, $subgroupName);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Only removes an account that carries no ledger history; dropping
        // one with posted lines would orphan those voucher lines. A query
        // delete on purpose: these codes are in Account::SYSTEM_CODES, so a
        // model delete() would be refused by Account's deleting guard.
        Account::query()
            ->whereIn('code', array_keys(self::ACCOUNTS))
            ->whereDoesntHave('journalVoucherLines')
            ->delete();
    }

    private function createAccount(string $code, string $name, string $groupName, ?string $subgroupName): void
    {
        if (Account::where('code', $code)->exists()) {
            return;
        }

        $group = AccountGroup::where('name', $groupName)->first();

        if (! $group) {
            return;
        }

        $subgroupId = null;

        if ($subgroupName !== null) {
            $subgroup = AccountSubgroup::where('account_group_id', $group->id)
                ->where('name', $subgroupName)
                ->first();

            if (! $subgroup) {
                return;
            }

            $subgroupId = $subgroup->id;
        }

        Account::create([
            // The seeder files subgroup accounts with a null group id (the
            // subgroup carries the group); mirror that.
            'account_group_id' => $subgroupId === null ? $group->id : null,
            'account_subgroup_id' => $subgroupId,
            'code' => $code,
            'name' => $name,
        ]);
    }
};
