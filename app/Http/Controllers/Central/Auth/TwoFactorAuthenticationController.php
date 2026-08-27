<?php

namespace App\Http\Controllers\Central\Auth;

use App\Http\Controllers\Controller;
use App\Models\PlatformAdmin;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorAuthenticationController extends Controller
{
    /**
     * Show the current admin's 2FA status. If a secret has been generated but
     * not yet confirmed, also render the QR code (self-rendered inline SVG
     * data URI — no external QR image API, so no CSP img-src exception needed)
     * and the plaintext secret for manual entry.
     */
    public function show(Request $request): Response
    {
        /** @var PlatformAdmin $admin */
        $admin = $request->user('platform');

        $props = ['enabled' => $admin->hasTwoFactorEnabled()];

        if ($admin->two_factor_secret && ! $admin->hasTwoFactorEnabled()) {
            $props['pendingSecret'] = $admin->two_factor_secret;
            $props['qrCodeDataUri'] = $this->qrCodeDataUri(
                (new Google2FA())->getQRCodeUrl('Day Khata', $admin->email, $admin->two_factor_secret),
            );
        }

        return Inertia::render('Central/Auth/TwoFactorSetup', $props);
    }

    /**
     * Generate a fresh, unconfirmed secret, discarding any prior unconfirmed
     * secret or (if 2FA was previously enabled and is being regenerated)
     * recovery codes.
     */
    public function generate(Request $request): RedirectResponse
    {
        /** @var PlatformAdmin $admin */
        $admin = $request->user('platform');

        $admin->forceFill([
            'two_factor_secret' => (new Google2FA())->generateSecretKey(),
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        return back();
    }

    /**
     * Confirm enrollment by verifying a code against the pending secret, then
     * issue recovery codes (shown once, in this same response, since they
     * can't be recovered later).
     */
    public function confirm(Request $request): Response
    {
        /** @var PlatformAdmin $admin */
        $admin = $request->user('platform');

        $validated = $request->validate(['code' => ['required', 'string']]);

        if (! $admin->two_factor_secret) {
            throw ValidationException::withMessages(['code' => 'Generate a QR code first.']);
        }

        if (! (new Google2FA())->verifyKey($admin->two_factor_secret, $validated['code'])) {
            throw ValidationException::withMessages(['code' => 'The provided code is invalid.']);
        }

        $recoveryCodes = collect(range(1, 8))
            ->map(fn () => Str::upper(Str::random(10).'-'.Str::random(10)))
            ->all();

        $admin->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => $recoveryCodes,
        ])->save();

        return Inertia::render('Central/Auth/TwoFactorSetup', [
            'enabled' => true,
            'recoveryCodes' => $recoveryCodes,
        ]);
    }

    /**
     * Disable 2FA. Requires re-entering the current password so a hijacked,
     * still-open session can't silently strip this protection.
     */
    public function destroy(Request $request): RedirectResponse
    {
        /** @var PlatformAdmin $admin */
        $admin = $request->user('platform');

        $request->validate([
            'password' => ['required', 'string', 'current_password:platform'],
        ]);

        $admin->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        return redirect()
            ->route('central.two-factor.show')
            ->with('status', 'Two-factor authentication disabled.');
    }

    private function qrCodeDataUri(string $otpauthUrl): string
    {
        $renderer = new ImageRenderer(new RendererStyle(240), new SvgImageBackEnd());
        $svg = (new Writer($renderer))->writeString($otpauthUrl);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
