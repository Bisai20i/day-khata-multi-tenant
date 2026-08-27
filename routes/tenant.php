<?php

declare(strict_types=1);

use App\Http\Controllers\Tenant\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Tenant\DashboardController;
use App\Http\Middleware\AbortIfTenantSuspended;
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
    AbortIfTenantSuspended::class,
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

        Route::get('/dashboard', [DashboardController::class, 'index'])->name('tenant.dashboard');

        Route::get('/admin/users', function (Request $request) {
            $request->user()->loadMissing('role');

            return Inertia::render('Tenant/Admin/Users');
        })->middleware('role:admin')->name('tenant.admin.users');

        require base_path('routes/tenant-business.php');
        require base_path('routes/tenant-ledger.php');
        require base_path('routes/tenant-sales.php');
        require base_path('routes/tenant-purchase.php');
        require base_path('routes/tenant-sales-returns.php');
        require base_path('routes/tenant-purchase-returns.php');
        require base_path('routes/tenant-stock-adjustments.php');
        require base_path('routes/tenant-reports-accounting.php');
        require base_path('routes/tenant-reports-sales-purchase.php');
        require base_path('routes/tenant-reports-inventory.php');
    });
});
