<?php

namespace App\Http\Controllers\Central\Auth;

use App\Http\Controllers\Controller;
use App\Models\PlatformAdmin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use PragmaRX\Google2FA\Google2FA;

/**
 * The post-password, pre-session step for a platform admin who has 2FA
 * enabled. AuthenticatedSessionController::store() validates credentials and
 * stashes the pending admin id here instead of logging in directly.
 */
class TwoFactorChallengeController extends Controller
{
    private const SESSION_KEY = 'platform_two_factor_challenge';

    public function create(Request $request): Response|RedirectResponse
    {
        if ($this->pendingAdminId($request) === null) {
            return redirect()->route('login');
        }

        return Inertia::render('Central/Auth/TwoFactorChallenge');
    }

    public function store(Request $request): RedirectResponse
    {
        $adminId = $this->pendingAdminId($request);

        if ($adminId === null) {
            return redirect()->route('login');
        }

        $validated = $request->validate(['code' => ['required', 'string']]);

        /** @var PlatformAdmin $admin */
        $admin = PlatformAdmin::findOrFail($adminId);

        if (! $this->verifyCode($admin, $validated['code'])) {
            throw ValidationException::withMessages(['code' => 'The provided code is invalid.']);
        }

        $remember = $request->session()->pull(self::SESSION_KEY.'.remember', false);
        $request->session()->forget(self::SESSION_KEY);

        Auth::guard('platform')->login($admin, $remember);
        $request->session()->regenerate();

        return redirect()->intended(route('central.dashboard'));
    }

    /**
     * Reads the pending admin id, treating an expired or missing challenge
     * the same way (send back to the login form).
     */
    private function pendingAdminId(Request $request): ?int
    {
        $challenge = $request->session()->get(self::SESSION_KEY);

        if (! $challenge || $challenge['expires_at'] < now()->timestamp) {
            $request->session()->forget(self::SESSION_KEY);

            return null;
        }

        return $challenge['id'];
    }

    /**
     * Accepts either a live TOTP code or a one-time recovery code, consuming
     * the recovery code on use so it can't be replayed.
     */
    private function verifyCode(PlatformAdmin $admin, string $code): bool
    {
        if ((new Google2FA())->verifyKey($admin->two_factor_secret, $code)) {
            return true;
        }

        $recoveryCodes = $admin->two_factor_recovery_codes ?? [];
        $normalizedCode = strtoupper($code);

        if (! in_array($normalizedCode, $recoveryCodes, true)) {
            return false;
        }

        $admin->forceFill([
            'two_factor_recovery_codes' => array_values(array_diff($recoveryCodes, [$normalizedCode])),
        ])->save();

        return true;
    }
}
