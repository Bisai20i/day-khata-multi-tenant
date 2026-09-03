<?php

use App\Mail\TestMail;
use App\Models\PlatformAdmin;
use App\Models\PlatformAdminActivityLog;
use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

test('a platform admin can view the settings page', function () {
    $admin = PlatformAdmin::factory()->create();

    $this->actingAs($admin, 'platform')
        ->get(route('central.settings.edit'))
        ->assertOk();
});

test('guests cannot access the settings routes', function () {
    $this->get(route('central.settings.edit'))->assertRedirect(route('login'));

    $this->put(route('central.settings.update'), [
        'default_trial_days' => 14,
        'default_grace_period_days' => 30,
    ])->assertRedirect(route('login'));
});

test('a platform admin can update platform settings', function () {
    $admin = PlatformAdmin::factory()->create();

    $this->actingAs($admin, 'platform')
        ->put(route('central.settings.update'), [
            'platform_name' => 'Day Khata',
            'support_email' => 'support@daykhata.test',
            'mail_mailer' => 'smtp',
            'mail_host' => 'smtp.example.test',
            'mail_port' => 587,
            'mail_username' => 'apikey',
            'mail_password' => 'secret-password',
            'mail_encryption' => 'tls',
            'mail_from_address' => 'noreply@daykhata.test',
            'mail_from_name' => 'Day Khata',
            'default_trial_days' => 21,
            'default_grace_period_days' => 45,
        ])
        ->assertRedirect(route('central.settings.edit'));

    $settings = PlatformSetting::current();

    expect($settings->platform_name)->toBe('Day Khata')
        ->and($settings->mail_host)->toBe('smtp.example.test')
        ->and($settings->mail_port)->toBe(587)
        ->and($settings->mail_password)->toBe('secret-password')
        ->and($settings->default_trial_days)->toBe(21)
        ->and($settings->default_grace_period_days)->toBe(45);
});

test('leaving the mail password blank on update keeps the previously stored password', function () {
    $admin = PlatformAdmin::factory()->create();
    PlatformSetting::current()->update(['mail_password' => 'already-set']);

    $this->actingAs($admin, 'platform')->put(route('central.settings.update'), [
        'mail_password' => '',
        'default_trial_days' => 14,
        'default_grace_period_days' => 30,
    ]);

    expect(PlatformSetting::current()->mail_password)->toBe('already-set');
});

test('updating settings requires trial and grace period days', function () {
    $admin = PlatformAdmin::factory()->create();

    $this->actingAs($admin, 'platform')
        ->put(route('central.settings.update'), [])
        ->assertSessionHasErrors(['default_trial_days', 'default_grace_period_days']);
});

test('a successful settings update records a platform admin activity log entry', function () {
    $admin = PlatformAdmin::factory()->create();

    $this->actingAs($admin, 'platform')->put(route('central.settings.update'), [
        'default_trial_days' => 14,
        'default_grace_period_days' => 30,
    ]);

    expect(PlatformAdminActivityLog::where('action', 'settings.update')
        ->where('platform_admin_id', $admin->id)
        ->whereNull('tenant_id')
        ->exists())->toBeTrue();
});

test('sending a test email sends to the current platform admin and reports success', function () {
    Mail::fake();

    $admin = PlatformAdmin::factory()->create(['email' => 'owner@platform.test']);

    $this->actingAs($admin, 'platform')
        ->post(route('central.settings.test-email'))
        ->assertRedirect(route('central.settings.edit'));

    Mail::assertSent(TestMail::class, fn (TestMail $mail) => $mail->hasTo('owner@platform.test'));
});

test('a support admin can view but not update settings or send a test email', function () {
    $support = PlatformAdmin::factory()->support()->create();

    $this->actingAs($support, 'platform')->get(route('central.settings.edit'))->assertOk();

    $this->actingAs($support, 'platform')->put(route('central.settings.update'), [
        'default_trial_days' => 14,
        'default_grace_period_days' => 30,
    ])->assertForbidden();

    $this->actingAs($support, 'platform')->post(route('central.settings.test-email'))->assertForbidden();
});

test('a tenant-side user cannot access the settings routes', function () {
    $webUser = User::factory()->make();

    Auth::guard('platform')->logout();

    $this->actingAs($webUser, 'web')
        ->get(route('central.settings.edit'))
        ->assertRedirect(route('login'));
});
