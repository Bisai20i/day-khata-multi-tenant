<?php

namespace Database\Seeders;

use App\Models\PlatformAdmin;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Standalone seeder for the central "platform_admins" table. Not wired into
 * DatabaseSeeder — run manually:
 *   php artisan db:seed --class=PlatformAdminSeeder
 */
class PlatformAdminSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        PlatformAdmin::factory()->create([
            'name' => 'Platform Admin',
            'email' => 'admin@example.com',
            'password' => 'password',
        ]);
    }
}
