<?php

namespace App\Console\Commands;

use App\Enums\PlatformAdminRole;
use App\Models\PlatformAdmin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Creates a central platform admin from the command line, which is how the
 * first login on a fresh server is made (the central UI needs an admin to
 * sign in first). PlatformAdminSeeder cannot do this in production: it uses a
 * factory, and Faker is a dev dependency, and it seeds a known password.
 * Validation mirrors PlatformAdminController::store(). The password is always
 * asked for interactively so it never lands in shell history.
 */
class CreatePlatformAdmin extends Command
{
    /**
     * @var string
     */
    protected $signature = 'platform-admin:create
        {--name= : Display name}
        {--email= : Login email}
        {--support : Create a support admin instead of an owner}';

    /**
     * @var string
     */
    protected $description = 'Create a central platform admin (use it for the first login on a fresh server)';

    public function handle(): int
    {
        $data = [
            'name' => $this->option('name') ?? $this->ask('Name'),
            'email' => $this->option('email') ?? $this->ask('Email'),
            'password' => $this->secret('Password (min 8 characters)'),
            'password_confirmation' => $this->secret('Confirm password'),
        ];

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('platform_admins')],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $role = $this->option('support') ? PlatformAdminRole::Support : PlatformAdminRole::Owner;

        PlatformAdmin::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'role' => $role,
            'is_active' => true,
        ]);

        $this->info("Platform admin {$data['email']} created ({$role->value}).");

        return self::SUCCESS;
    }
}
