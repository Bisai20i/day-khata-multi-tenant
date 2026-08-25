<?php

use App\Models\PlatformAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

test('the login screen can be rendered', function () {
    $response = $this->get('/login');

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page->component('Central/Auth/Login'));
});

test('a platform admin can authenticate using the login screen', function () {
    $admin = PlatformAdmin::factory()->create();

    $response = $this->post('/login', [
        'email' => $admin->email,
        'password' => 'password',
    ]);

    expect(Auth::guard('platform')->check())->toBeTrue();
    expect(Auth::guard('platform')->id())->toBe($admin->id);
    $response->assertRedirect(route('central.dashboard'));
});

test('a platform admin cannot authenticate with an invalid password', function () {
    $admin = PlatformAdmin::factory()->create();

    $this->post('/login', [
        'email' => $admin->email,
        'password' => 'wrong-password',
    ]);

    expect(Auth::guard('platform')->check())->toBeFalse();
});

test('a platform admin can logout', function () {
    $admin = PlatformAdmin::factory()->create();

    $this->actingAs($admin, 'platform');

    expect(Auth::guard('platform')->check())->toBeTrue();

    $response = $this->post('/logout');

    expect(Auth::guard('platform')->check())->toBeFalse();
    $response->assertRedirect(route('login'));
});

test('the protected dashboard redirects unauthenticated visitors to login', function () {
    $response = $this->get('/admin');

    $response->assertRedirect(route('login'));
});

test('an authenticated platform admin can view the dashboard', function () {
    $admin = PlatformAdmin::factory()->create();

    $response = $this->actingAs($admin, 'platform')->get('/admin');

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page->component('Central/Dashboard'));
});
