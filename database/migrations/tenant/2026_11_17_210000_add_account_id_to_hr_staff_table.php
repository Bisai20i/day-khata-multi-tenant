<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('hr_staff', 'account_id')) {
            Schema::table('hr_staff', function (Blueprint $table) {
                $table->foreignId('account_id')
                    ->nullable()
                    ->after('user_id')
                    ->constrained('accounts')
                    ->nullOnDelete();
            });
        }

        $this->ensureSalaryPayableSubgroupAndBackfill();
    }

    private function ensureSalaryPayableSubgroupAndBackfill(): void
    {
        $subgroupId = DB::table('account_subgroups')
            ->where('name', 'Salary Payable')
            ->value('id');

        if (! $subgroupId) {
            $groupId = DB::table('account_groups')
                ->where('name', 'Current Liabilities')
                ->value('id');

            if ($groupId) {
                $subgroupId = DB::table('account_subgroups')->insertGetId([
                    'account_group_id' => $groupId,
                    'name' => 'Salary Payable',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $parentAccountId = DB::table('accounts')
            ->where('code', 'LIA22')
            ->orWhere(function ($query) {
                $query->where('name', 'Salary Payable')
                    ->whereNull('parent_account_id');
            })
            ->value('id');

        $staffMembers = DB::table('hr_staff')
            ->whereNull('account_id')
            ->get();

        $now = now();

        foreach ($staffMembers as $staff) {
            $accountId = DB::table('accounts')->insertGetId([
                'account_subgroup_id' => $subgroupId,
                'parent_account_id' => $parentAccountId,
                'name' => $staff->name,
                'phone' => $staff->mobile_no,
                'address' => $staff->address,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('hr_staff')
                ->where('id', $staff->id)
                ->update(['account_id' => $accountId]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('hr_staff', 'account_id')) {
            Schema::table('hr_staff', function (Blueprint $table) {
                $table->dropConstrainedForeignId('account_id');
            });
        }
    }
};
