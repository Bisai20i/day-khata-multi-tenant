<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * Set a strict CSP and a small set of standard security headers on every
     * response outside local dev — Vite's dev-mode client injects a
     * cross-origin script tag that a strict script-src would block, and
     * there's no way to verify a dev-mode carve-out without a browser, so
     * local is simply exempted rather than guessed at.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (app()->environment('local')) {
            return $response;
        }

        $response->headers->set('Content-Security-Policy', implode('; ', [
            "default-src 'self'",
            "script-src 'self'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data:",
            "font-src 'self'",
            "connect-src 'self'",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
        ]));

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        return $response;
    }
}
