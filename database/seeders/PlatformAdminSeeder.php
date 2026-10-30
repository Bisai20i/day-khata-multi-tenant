<?php

namespace Database\Seeders;

use App\Models\PlatformAdmin;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Seeder for the central "platform_admins" table. DatabaseSeeder runs it
 * outside production; to run it alone:
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
        if (PlatformAdmin::query()->where('email', 'admin@example.com')->exists()) {
            return;
        }

        PlatformAdmin::factory()->create([
            'name' => 'Platform Admin',
            'email' => 'admin@example.com',
            'password' => 'password',
        ]);
    }
}
