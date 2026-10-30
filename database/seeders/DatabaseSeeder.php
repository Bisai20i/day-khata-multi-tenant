<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Seeds the central database only. Tenant users, roles and accounts live in
 * each tenant's own database and are seeded by Tenant\TenantDatabaseSeeder
 * (tenants:seed, or automatically when a tenant is created), so there is no
 * "users" table here to seed.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // PlatformAdminSeeder uses a known password, so it never runs
        // implicitly in production (use `php artisan platform-admin:create`).
        if (! $this->command->getLaravel()->environment('production')) {
            $this->call(PlatformAdminSeeder::class);
        }
    }
}
