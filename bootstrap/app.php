<?php

use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => EnsureUserHasRole::class,
        ]);

        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);

        // Laravel's default guest redirect always targets the route named
        // "login". That's the central platform-admin login here, so an
        // unauthenticated visit to a tenant route needs to be sent to the
        // tenant login instead. Tenancy is already initialized by this point
        // (its middleware is the highest-priority group), so that's the
        // signal to tell the two contexts apart.
        $middleware->redirectGuestsTo(
            fn (Request $request) => tenancy()->initialized ? route('tenant.login') : route('login'),
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
