<?php

use App\Models\Account;
use App\Models\AccountGroup;
use Illuminate\Database\Migrations\Migration;

/**
 * Round Off (signed +/- adjustment on a sales or purchase bill) posts to one
 * dedicated system account, INI31, under Indirect Income. One account serves
 * both modules and both signs: income when it nets to a credit, a loss when
 * it nets to a debit, and the fiscal-year close sweeps it into Profit & Loss
 * with every other Income-head account.
 *
 * The group is resolved by name, like the manufacturing accounts migration.
 * A tenant whose chart was customised away from the seeded groups is left
 * untouched rather than half-migrated. Idempotent: guarded by a code lookup.
 */
return new class extends Migration
{
    private const CODE = 'INI31';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Account::where('code', self::CODE)->exists()) {
            return;
        }

        $group = AccountGroup::where('name', 'Indirect Income')->first();

        if (! $group) {
            return;
        }

        Account::create([
            'account_group_id' => $group->id,
            'account_subgroup_id' => null,
            'code' => self::CODE,
            'name' => 'Round Off',
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Only removes an account that carries no ledger history. A query
        // delete on purpose: the code is in Account::SYSTEM_CODES, so a model
        // delete() would be refused by Account's deleting guard.
        Account::query()
            ->where('code', self::CODE)
            ->whereDoesntHave('journalVoucherLines')
            ->delete();
    }
};
