<?php

use App\Models\PlatformAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the central root redirects a guest to login', function () {
    // The central domain's "/" no longer renders a static welcome page - it
    // redirects based on "platform" guard auth state (see routes/central.php),
    // same pattern routes/tenant.php's own root route already used.
    $response = $this->get('/');

    $response->assertRedirect(route('login'));
});

test('the central root redirects an authenticated platform admin to the dashboard', function () {
    $admin = PlatformAdmin::factory()->create();

    $response = $this->actingAs($admin, 'platform')->get('/');

    $response->assertRedirect(route('central.dashboard'));
});
