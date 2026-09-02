<?php

namespace Database\Seeders\Tenant;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class TenantDatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Starter permission set for a freshly provisioned tenant.
     *
     * This is a small, sane MVP baseline, not a full port of any legacy
     * privilege-key list. That is separate future work.
     *
     * @var array<int, array{name: string, slug: string}>
     */
    protected array $permissions = [
        ['name' => 'Manage Users', 'slug' => 'manage-users'],
        ['name' => 'Manage Roles', 'slug' => 'manage-roles'],
        ['name' => 'View Reports', 'slug' => 'view-reports'],
        ['name' => 'Manage Settings', 'slug' => 'manage-settings'],
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $permissions = collect($this->permissions)->map(
            fn (array $permission) => Permission::create($permission),
        );

        $admin = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        $admin->permissions()->attach($permissions->pluck('id'));

        Role::create(['name' => 'Staff', 'slug' => 'staff']);

        Store::create(['name' => 'Main Store', 'is_active' => true]);

        $this->call(ChartOfAccountsSeeder::class);
    }
}
