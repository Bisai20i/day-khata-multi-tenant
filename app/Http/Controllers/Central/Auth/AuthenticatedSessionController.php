<?php

namespace App\Http\Controllers\Central\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the platform admin login view.
     */
    public function create(): Response
    {
        return Inertia::render('Central/Auth/Login');
    }

    /**
     * Handle an incoming platform admin authentication request. Credentials
     * are validated without logging in yet (via the guard's provider
     * directly, not attempt()), because a 2FA-enabled admin must clear the
     * challenge step before a session is actually established.
     */
    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $provider = Auth::guard('platform')->getProvider();
        $admin = $provider->retrieveByCredentials($credentials);

        if (! $admin || ! $provider->validateCredentials($admin, $credentials)) {
            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        if ($admin->hasTwoFactorEnabled()) {
            $request->session()->put('platform_two_factor_challenge', [
                'id' => $admin->getKey(),
                'remember' => $request->boolean('remember'),
                'expires_at' => now()->addMinutes(5)->timestamp,
            ]);

            return redirect()->route('central.two-factor.challenge');
        }

        Auth::guard('platform')->login($admin, $request->boolean('remember'));

        $request->session()->regenerate();

        return redirect()->intended(route('central.dashboard'));
    }

    /**
     * Destroy an authenticated platform admin session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('platform')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
