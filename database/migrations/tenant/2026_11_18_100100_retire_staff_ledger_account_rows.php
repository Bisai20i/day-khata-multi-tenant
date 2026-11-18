<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retires the per-staff ledger account rows added by
 * 2026_11_17_210000_add_account_id_to_hr_staff_table.
 *
 * Staff balances live in the HR sub-ledger (StaffBalances), reconciled against
 * the Salary Payable (LIA22) and Staff Advances Receivable (ASA24) control
 * accounts. Nothing ever posted to the per-staff rows, so they were zero-balance
 * duplicates of an identity the sub-ledger already holds. The link column is
 * dropped, then each staff account is deleted only when it has no ledger line,
 * no sub-account and nothing else pointing at it (a database restriction
 * refusing the delete leaves that account in place). Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('hr_staff', 'account_id')) {
            return;
        }

        $accountIds = DB::table('hr_staff')->whereNotNull('account_id')->pluck('account_id')->all();

        Schema::table('hr_staff', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('account_id');
        });

        foreach ($accountIds as $accountId) {
            $hasLines = DB::table('journal_voucher_lines')->where('account_id', $accountId)->exists();
            $hasChildren = DB::table('accounts')->where('parent_account_id', $accountId)->exists();

            if ($hasLines || $hasChildren) {
                continue;
            }

            try {
                DB::table('accounts')->where('id', $accountId)->delete();
            } catch (QueryException) {
                // Referenced elsewhere: keep the account.
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('hr_staff', 'account_id')) {
            return;
        }

        Schema::table('hr_staff', function (Blueprint $table): void {
            $table->foreignId('account_id')->nullable()->after('user_id')->constrained('accounts')->nullOnDelete();
        });
    }
};
