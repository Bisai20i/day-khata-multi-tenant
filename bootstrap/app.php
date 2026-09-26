<?php

use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use App\Models\FiscalYear;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

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
            SecurityHeaders::class,
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

        // Every posting path resolves the open fiscal year with firstOrFail(),
        // which would otherwise surface as a bare 404. When a tenant simply has
        // none yet, send the user back with a readable error instead.
        $exceptions->map(function (ModelNotFoundException $e) {
            $isMissingOpenYear = $e->getModel() === FiscalYear::class
                && tenancy()->initialized
                && ! request()->isMethod('GET')
                && ! FiscalYear::hasOpen();

            return $isMissingOpenYear
                ? ValidationException::withMessages(['fiscal_year' => FiscalYear::NO_OPEN_YEAR_MESSAGE])
                : $e;
        });
    })->create();
