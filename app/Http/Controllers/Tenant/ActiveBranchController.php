<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Support\Branches\ActiveBranchSelection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Stores the signed-in user's top-bar branch choice in their session (plan 12,
 * section 5.10). Self-service like ProfileController: it changes nothing but
 * the caller's own view, and the choice is re-checked against the branches
 * they may use on every request (SetBranchContext), so it carries no
 * permission gate. A tenant without the `branches` module has no switcher, so
 * the endpoint does not exist for it.
 */
class ActiveBranchController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        abort_unless(ActiveBranchSelection::moduleEnabled(), 404);

        $request->validate([
            'branch' => ['required'],
        ]);

        $choice = $request->input('branch');

        if (! is_string($choice) && ! is_int($choice)) {
            $this->refuse();
        }

        $normalized = ActiveBranchSelection::normalize($choice);

        if ($normalized === null || ! ActiveBranchSelection::isSelectable($request, $normalized)) {
            $this->refuse();
        }

        $request->session()->put(ActiveBranchSelection::SESSION_KEY, $normalized);

        return back();
    }

    /**
     * @throws ValidationException
     */
    private function refuse(): never
    {
        throw ValidationException::withMessages([
            'branch' => 'You cannot switch to that '.strtolower(ActiveBranchSelection::label()).'.',
        ]);
    }
}
