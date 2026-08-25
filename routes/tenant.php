<?php

declare(strict_types=1);

use App\Http\Controllers\Tenant\Auth\AuthenticatedSessionController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

/*
|--------------------------------------------------------------------------
| Tenant Routes
|--------------------------------------------------------------------------
|
| Here you can register the tenant routes for your application.
| These routes are loaded by the TenantRouteServiceProvider.
|
| Feel free to customize them however you want. Good luck!
|
*/

Route::middleware([
    'web',
    InitializeTenancyByDomain::class,
    PreventAccessFromCentralDomains::class,
])->group(function () {
    Route::get('/', function () {
        return 'This is your multi-tenant application. The id of the current tenant is '.tenant('id');
    });

    Route::middleware('guest:web')->group(function () {
        Route::get('/login', [AuthenticatedSessionController::class, 'create'])
            ->name('tenant.login');

        Route::post('/login', [AuthenticatedSessionController::class, 'store'])
            ->middleware('throttle:5,1')
            ->name('tenant.login.store');
    });

    Route::middleware('auth:web')->group(function () {
        Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
            ->name('tenant.logout');

        Route::get('/dashboard', function (Request $request) {
            // Eager-load the role relation onto the same User instance that
            // HandleInertiaRequests shares as `auth.user`, so the page can
            // read `auth.user.role` without a separate prop.
            $request->user()->loadMissing('role');

            return Inertia::render('Tenant/Dashboard');
        })->name('tenant.dashboard');

        Route::get('/admin/users', function (Request $request) {
            $request->user()->loadMissing('role');

            return Inertia::render('Tenant/Admin/Users');
        })->middleware('role:admin')->name('tenant.admin.users');
    });
});
