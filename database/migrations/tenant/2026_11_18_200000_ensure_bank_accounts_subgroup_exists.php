<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::table('account_subgroups')->where('name', 'Bank Accounts')->exists()) {
            return;
        }

        $groupId = DB::table('account_groups')->where('name', 'Current Assets')->value('id');

        if (! $groupId) {
            return;
        }

        DB::table('account_subgroups')->insert([
            'account_group_id' => $groupId,
            'name' => 'Bank Accounts',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $subgroup = DB::table('account_subgroups')->where('name', 'Bank Accounts')->first();

        if (! $subgroup) {
            return;
        }

        $hasAccounts = DB::table('accounts')->where('account_subgroup_id', $subgroup->id)->exists();

        if (! $hasAccounts) {
            DB::table('account_subgroups')->where('id', $subgroup->id)->delete();
        }
    }
};
