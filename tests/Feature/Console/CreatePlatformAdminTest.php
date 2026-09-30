<?php

use App\Enums\PlatformAdminRole;
use App\Models\PlatformAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('creates an active owner admin with a hashed password', function () {
    $this->artisan('platform-admin:create', ['--name' => 'Ops', '--email' => 'ops@example.com'])
        ->expectsQuestion('Password (min 8 characters)', 'correct-horse')
        ->expectsQuestion('Confirm password', 'correct-horse')
        ->expectsOutputToContain('ops@example.com created (owner)')
        ->assertSuccessful();

    $admin = PlatformAdmin::query()->where('email', 'ops@example.com')->sole();

    expect($admin->role)->toBe(PlatformAdminRole::Owner)
        ->and($admin->is_active)->toBeTrue()
        ->and(Hash::check('correct-horse', $admin->password))->toBeTrue();
});

it('asks for name and email when not passed and can create a support admin', function () {
    $this->artisan('platform-admin:create', ['--support' => true])
        ->expectsQuestion('Name', 'Helper')
        ->expectsQuestion('Email', 'help@example.com')
        ->expectsQuestion('Password (min 8 characters)', 'correct-horse')
        ->expectsQuestion('Confirm password', 'correct-horse')
        ->assertSuccessful();

    expect(PlatformAdmin::query()->where('email', 'help@example.com')->sole()->role)
        ->toBe(PlatformAdminRole::Support);
});

it('refuses a short or mismatched password and a taken email', function (string $email, string $password, string $confirmation) {
    PlatformAdmin::factory()->create(['email' => 'taken@example.com']);

    $this->artisan('platform-admin:create', ['--name' => 'X', '--email' => $email])
        ->expectsQuestion('Password (min 8 characters)', $password)
        ->expectsQuestion('Confirm password', $confirmation)
        ->assertFailed();

    expect(PlatformAdmin::query()->count())->toBe(1);
})->with([
    'short password' => ['new@example.com', 'short', 'short'],
    'mismatch' => ['new@example.com', 'correct-horse', 'correct-horsE'],
    'taken email' => ['taken@example.com', 'correct-horse', 'correct-horse'],
    'bad email' => ['not-an-email', 'correct-horse', 'correct-horse'],
]);
