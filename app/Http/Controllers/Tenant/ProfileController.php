<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Self-service account page for the authenticated tenant user: update own
 * name/email and change own password. Distinct from
 * Tenant\Admin\UserController, which is an admin managing other employees'
 * accounts (name/email/role/status) and is gated by `role:admin` - this one
 * is open to every authenticated tenant user managing themselves, so it
 * carries no role gate (see routes/tenant-profile.php).
 */
class ProfileController extends Controller
{
    public function edit(Request $request): Response
    {
        // Eager-load the role relation onto the same User instance that
        // HandleInertiaRequests shares as `auth.user`, so the page can read
        // `auth.user.role` (needed to decide whether the sidebar's ADMIN
        // group renders) without a separate prop - same approach as
        // Tenant\DashboardController.
        $request->user('web')->loadMissing('role');

        return Inertia::render('Tenant/Profile/Edit', [
            'user' => $request->user('web')->only(['id', 'name', 'email']),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user('web');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user)],
        ]);

        $user->name = $data['name'];
        $user->email = $data['email'];
        $user->save();

        return redirect()->route('tenant.profile.edit')->with('status', 'Profile updated.');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password:web'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = $request->user('web');
        $user->password = $data['password'];
        $user->save();

        return redirect()->route('tenant.profile.edit')->with('status', 'Password updated.');
    }
}
