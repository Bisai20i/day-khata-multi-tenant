<?php

namespace App\Http\Middleware;

use App\Support\Branches\ActiveBranchSelection;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hands the user's top-bar branch choice to BranchContext for this request
 * (plan 12, section 5.7 and 5.10).
 *
 * The session value is checked against the branches the user may use right
 * now; a stale or forged one is dropped, which leaves the context on its own
 * fallback (the user's default branch, then Main). The choice only narrows:
 * what a restricted user may read or post is decided by BranchContext's
 * allowed set, never by this value.
 *
 * Registered in the web group ahead of HandleInertiaRequests. It does nothing
 * on central routes, for guests, or for a tenant without the `branches`
 * module beyond clearing any selection a previous request left on the scoped
 * BranchContext, and it never throws (a tenant with missing branch tables
 * just gets no selection).
 */
class SetBranchContext
{
    public function handle(Request $request, Closure $next): Response
    {
        ActiveBranchSelection::apply($request);

        return $next($request);
    }
}
