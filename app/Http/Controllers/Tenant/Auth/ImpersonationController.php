<?php

namespace App\Http\Controllers\Tenant\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ImpersonationController extends Controller
{
    /**
     * Log the platform admin in as the given tenant admin user and land on
     * the tenant dashboard.
     *
     * Only ever reached via the short-lived, single-purpose signed URL that
     * App\Http\Controllers\Central\Tenants\TenantController::impersonate()
     * generates - the `signed` route middleware (routes/tenant-impersonation.php)
     * already rejects a tampered or expired link before this method runs, so
     * no further authorization check is needed here.
     */
    public function login(Request $request, User $user): RedirectResponse
    {
        Auth::guard('web')->login($user);

        $request->session()->regenerate();

        return redirect()->route('tenant.dashboard');
    }
}
