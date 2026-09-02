<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    /**
     * Handle an incoming request.
     *
     * Aborts with a 403 unless the authenticated tenant user's role slug
     * matches the given role.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $role): Response
    {
        // Explicitly the 'web' guard, not the ambiguous default - see
        // routes/tenant.php's root route for why relying on the default
        // guard is unsafe when two named guards (web/platform) exist.
        if ($request->user('web')?->role?->slug !== $role) {
            abort(403);
        }

        return $next($request);
    }
}
