<?php

use App\Models\PlatformAdmin;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('db:seed seeds the central platform admin without touching a users table', function () {
    $this->seed(DatabaseSeeder::class);

    expect(PlatformAdmin::query()->where('email', 'admin@example.com')->count())->toBe(1);
});

test('db:seed can be run repeatedly', function () {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    expect(PlatformAdmin::query()->count())->toBe(1);
});
