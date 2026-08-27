<?php

use App\Models\PlatformAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use PragmaRX\Google2FA\Google2FA;

uses(RefreshDatabase::class);

test('enrollment: generating a secret then confirming with a valid code enables 2FA and returns recovery codes once', function () {
    $admin = PlatformAdmin::factory()->create();

    $this->actingAs($admin, 'platform')->post('/two-factor')->assertRedirect();

    $admin->refresh();
    expect($admin->two_factor_secret)->not->toBeNull();
    expect($admin->hasTwoFactorEnabled())->toBeFalse();

    $code = (new Google2FA())->getCurrentOtp($admin->two_factor_secret);

    $response = $this->actingAs($admin, 'platform')->post('/two-factor/confirm', ['code' => $code]);

    $response->assertInertia(fn ($page) => $page
        ->component('Central/Auth/TwoFactorSetup')
        ->where('enabled', true)
        ->has('recoveryCodes', 8));

    $admin->refresh();
    expect($admin->hasTwoFactorEnabled())->toBeTrue();
    expect($admin->two_factor_recovery_codes)->toHaveCount(8);
});

test('confirming with an invalid code fails and does not enable 2FA', function () {
    $admin = PlatformAdmin::factory()->create();
    $this->actingAs($admin, 'platform')->post('/two-factor');

    $this->actingAs($admin, 'platform')
        ->post('/two-factor/confirm', ['code' => '000000'])
        ->assertSessionHasErrors('code');

    expect($admin->fresh()->hasTwoFactorEnabled())->toBeFalse();
});

test('disabling 2FA requires the current password', function () {
    $admin = twoFactorTestEnable(PlatformAdmin::factory()->create(['password' => 'password']));

    $this->actingAs($admin, 'platform')
        ->delete('/two-factor', ['password' => 'wrong-password'])
        ->assertSessionHasErrors('password');

    expect($admin->fresh()->hasTwoFactorEnabled())->toBeTrue();

    $this->actingAs($admin, 'platform')
        ->delete('/two-factor', ['password' => 'password'])
        ->assertRedirect(route('central.two-factor.show'));

    $admin->refresh();
    expect($admin->hasTwoFactorEnabled())->toBeFalse();
    expect($admin->two_factor_secret)->toBeNull();
    expect($admin->two_factor_recovery_codes)->toBeNull();
});

test('a login without 2FA enabled is unaffected by this pass', function () {
    $admin = PlatformAdmin::factory()->create();

    $response = $this->post('/login', ['email' => $admin->email, 'password' => 'password']);

    expect(Auth::guard('platform')->check())->toBeTrue();
    $response->assertRedirect(route('central.dashboard'));
});

test('a login with 2FA enabled is redirected to the challenge instead of being logged in', function () {
    $admin = twoFactorTestEnable(PlatformAdmin::factory()->create(['password' => 'password']));

    $response = $this->post('/login', ['email' => $admin->email, 'password' => 'password']);

    expect(Auth::guard('platform')->check())->toBeFalse();
    $response->assertRedirect(route('central.two-factor.challenge'));
});

test('the challenge rejects a wrong code and accepts a correct one', function () {
    $admin = twoFactorTestEnable(PlatformAdmin::factory()->create(['password' => 'password']));
    $this->post('/login', ['email' => $admin->email, 'password' => 'password']);

    $this->post('/two-factor-challenge', ['code' => '000000'])->assertSessionHasErrors('code');
    expect(Auth::guard('platform')->check())->toBeFalse();

    $code = (new Google2FA())->getCurrentOtp($admin->two_factor_secret);
    $response = $this->post('/two-factor-challenge', ['code' => $code]);

    expect(Auth::guard('platform')->check())->toBeTrue();
    expect(Auth::guard('platform')->id())->toBe($admin->id);
    $response->assertRedirect(route('central.dashboard'));
});

test('the challenge accepts a recovery code and consumes it so it cannot be reused', function () {
    $admin = twoFactorTestEnable(PlatformAdmin::factory()->create(['password' => 'password']));
    $recoveryCode = $admin->two_factor_recovery_codes[0];

    $this->post('/login', ['email' => $admin->email, 'password' => 'password']);
    $this->post('/two-factor-challenge', ['code' => $recoveryCode])
        ->assertRedirect(route('central.dashboard'));

    expect(Auth::guard('platform')->check())->toBeTrue();
    expect($admin->fresh()->two_factor_recovery_codes)->not->toContain($recoveryCode);

    Auth::guard('platform')->logout();
    session()->flush();

    $this->post('/login', ['email' => $admin->email, 'password' => 'password']);
    $this->post('/two-factor-challenge', ['code' => $recoveryCode])
        ->assertSessionHasErrors('code');

    expect(Auth::guard('platform')->check())->toBeFalse();
});

test('visiting the challenge page without a pending login redirects to the login form', function () {
    $this->get('/two-factor-challenge')->assertRedirect(route('login'));
});

/**
 * Enrolls and confirms 2FA for the given admin via the real HTTP flow (not a
 * direct model write), so every test exercises the same code path the UI
 * does. Logs the admin back out afterward — actingAs() leaves the platform
 * guard authenticated for the rest of the test, which would make a
 * subsequent plain (non-actingAs) /login POST hit guest:platform's redirect
 * instead of actually exercising the login flow callers expect to test.
 */
function twoFactorTestEnable(PlatformAdmin $admin): PlatformAdmin
{
    test()->actingAs($admin, 'platform')->post('/two-factor');
    $admin->refresh();

    $code = (new Google2FA())->getCurrentOtp($admin->two_factor_secret);
    test()->actingAs($admin, 'platform')->post('/two-factor/confirm', ['code' => $code]);

    $admin->refresh();

    Auth::guard('platform')->logout();
    session()->flush();

    return $admin;
}
