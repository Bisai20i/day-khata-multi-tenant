<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Walk-in customer (audit section 3 "Sales", ported from legacy day_khata's
 * `DefaultMainAccountSeeder.php:60-77`): an always-available, un-deletable
 * customer record for a cash-and-carry sale where nobody bothers to record
 * who the buyer was. POS and abbreviated tax invoices default to it (see
 * PosController::index()/SaleController::index() and the Vue forms), and
 * because Sale::post() snapshots `buyer_name` straight from `customer->name`,
 * simply naming this row "Walk-in customer" is what makes every such bill's
 * buyer snapshot read that way with no extra code.
 *
 * `is_walk_in` marks the one protected row so CustomerController::destroy()
 * can refuse to delete it (a normal customer has no such flag, so this never
 * touches existing data or behaviour). The migration itself seeds the row
 * for both a fresh tenant, in case TenantDatabaseSeeder ever changes, and an
 * existing tenant already provisioned before this feature existed - run
 * exactly once, keyed by the flag itself, so a re-run never creates a second
 * one. Uses DB::table() rather than the Eloquent models (this repo's own
 * migration convention - a model's shape can change out from under an old
 * migration, a raw insert cannot).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('is_walk_in')->default(false)->after('citizenship');
        });

        $this->seedWalkInCustomer();
    }

    private function seedWalkInCustomer(): void
    {
        if (DB::table('customers')->where('is_walk_in', true)->exists()) {
            return;
        }

        $subgroupId = DB::table('account_subgroups')->where('name', 'Sundry Debtors')->value('id');

        // A tenant mid-provisioning (ChartOfAccountsSeeder not run yet) has no
        // customers at all either - TenantDatabaseSeeder's own ordering
        // guarantees this exists for a normal tenant; this is only a guard
        // against an unusual seeding order.
        if (! $subgroupId) {
            return;
        }

        $now = now();

        $accountId = DB::table('accounts')->insertGetId([
            'account_subgroup_id' => $subgroupId,
            'name' => 'Walk-in customer',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('customers')->insert([
            'account_id' => $accountId,
            'name' => 'Walk-in customer',
            'is_walk_in' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('is_walk_in');
        });
    }
};
