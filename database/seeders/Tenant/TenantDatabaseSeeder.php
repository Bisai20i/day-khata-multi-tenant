<?php

namespace Database\Seeders\Tenant;

use App\Models\AccountSubgroup;
use App\Models\Customer;
use App\Models\Role;
use App\Models\Store;
use App\Support\Permissions\PermissionCatalog;
use App\Support\Permissions\RoleTemplates;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class TenantDatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Admin keeps EVERY grantable key, not just the entitled ones: a
        // module the platform switches on later must light up for the admin
        // with no data change (its keys were dormant, the entitlement gate
        // hides them meanwhile). Manager and Cashier are frozen templates
        // (RoleTemplates) narrowed to the modules this tenant has today; the
        // owner widens them in the role editor once a module is enabled.
        $entitled = tenant()?->entitledModules()
            ?? PermissionCatalog::resolveModules((array) config('permissions.default_modules', []));

        Role::create([
            'name' => 'Admin',
            'slug' => 'admin',
            'permissions' => PermissionCatalog::grantable(),
            'is_system' => true,
        ]);

        Role::create([
            'name' => 'Manager',
            'slug' => 'manager',
            'permissions' => RoleTemplates::forModules(RoleTemplates::MANAGER, $entitled),
        ]);

        Role::create([
            'name' => 'Cashier',
            'slug' => 'cashier',
            'permissions' => RoleTemplates::forModules(RoleTemplates::CASHIER, $entitled),
        ]);

        Store::create(['name' => 'Main Store', 'is_active' => true]);

        $this->call(ChartOfAccountsSeeder::class);

        $this->seedWalkInCustomer();
    }

    /**
     * The one protected, always-available customer for a sale where nobody
     * bothers to record who the buyer was (audit section 3 "Sales", see
     * Customer::walkIn()'s docblock). Must run after ChartOfAccountsSeeder,
     * which is what seeds the "Sundry Debtors" subgroup this customer's
     * ledger account files under.
     *
     * This class uses WithoutModelEvents, so Customer's HasLedgerAccount
     * `creating` hook that normally auto-creates a customer's ledger account
     * never fires here - the account is created explicitly instead, exactly
     * the way that hook would have.
     */
    private function seedWalkInCustomer(): void
    {
        $subgroup = AccountSubgroup::where('name', 'Sundry Debtors')->firstOrFail();
        $account = $subgroup->accounts()->create(['name' => 'Walk-in customer']);

        Customer::create([
            'account_id' => $account->id,
            'name' => 'Walk-in customer',
            'is_walk_in' => true,
        ]);
    }
}
