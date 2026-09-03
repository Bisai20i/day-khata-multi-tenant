<?php

namespace App\Http\Controllers\Central\Settings;

use App\Http\Controllers\Controller;
use App\Mail\TestMail;
use App\Models\PlatformAdminActivityLog;
use App\Models\PlatformSetting;
use App\Support\PlatformMailer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class PlatformSettingController extends Controller
{
    public function edit(): Response
    {
        $settings = PlatformSetting::current();

        return Inertia::render('Central/Settings/Edit', [
            'settings' => [
                ...$settings->only([
                    'mail_mailer', 'mail_host', 'mail_port', 'mail_username', 'mail_encryption',
                    'mail_from_address', 'mail_from_name', 'platform_name', 'support_email',
                    'default_trial_days', 'default_grace_period_days',
                ]),
                // mail_password is write-only from the UI's point of view -
                // never round-tripped back into the form, same convention
                // as this app's 2FA recovery codes never being re-displayed.
                'mail_password_set' => $settings->mail_password !== null,
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'mail_mailer' => ['nullable', 'string', 'max:255'],
            'mail_host' => ['nullable', 'string', 'max:255'],
            'mail_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'mail_username' => ['nullable', 'string', 'max:255'],
            'mail_password' => ['nullable', 'string', 'max:255'],
            'mail_encryption' => ['nullable', 'string', 'max:255'],
            'mail_from_address' => ['nullable', 'string', 'email', 'max:255'],
            'mail_from_name' => ['nullable', 'string', 'max:255'],
            'platform_name' => ['nullable', 'string', 'max:255'],
            'support_email' => ['nullable', 'string', 'email', 'max:255'],
            'default_trial_days' => ['required', 'integer', 'min:0', 'max:365'],
            'default_grace_period_days' => ['required', 'integer', 'min:0', 'max:365'],
        ]);

        // A blank password field means "leave the stored password alone",
        // not "clear it" - the edit form never round-trips the real value.
        if (($data['mail_password'] ?? '') === '') {
            unset($data['mail_password']);
        }

        PlatformSetting::current()->update($data);

        PlatformAdminActivityLog::record('settings.update');

        return redirect()->route('central.settings.edit')->with('status', 'Settings updated.');
    }

    public function sendTestEmail(): RedirectResponse
    {
        $admin = Auth::guard('platform')->user();

        PlatformMailer::apply();

        try {
            Mail::to($admin->email)->send(new TestMail(PlatformSetting::current()->platform_name ?? config('app.name')));
        } catch (Throwable $e) {
            return redirect()->route('central.settings.edit')->with('status', "Test email failed: {$e->getMessage()}");
        }

        return redirect()->route('central.settings.edit')->with('status', "Test email sent to {$admin->email}.");
    }
}
