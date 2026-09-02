<?php

declare(strict_types=1);

use App\Http\Controllers\Tenant\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Tenant\DashboardController;
use App\Http\Middleware\AbortIfTenantSuspended;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
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
    PreventAccessFromCentralDomains::class,
    AbortIfTenantSuspended::class,
    InitializeTenancyByDomain::class,
])->group(function () {
    Route::get('/', function (Request $request) {
        // Explicitly the 'web' guard, not the ambiguous default - the
        // process-wide "default auth guard" can be temporarily switched to
        // 'platform' (e.g. Auth::shouldUse() in tests, or any code sharing
        // this worker), and this tenant route must never treat a platform
        // admin's session as a tenant user's.
        return $request->user('web')
            ? redirect()->route('tenant.dashboard')
            : redirect()->route('tenant.login');
    });

    Route::middleware('guest:web')->group(function () {
        Route::get('/login', [AuthenticatedSessionController::class, 'create'])
            ->name('tenant.login');

        Route::post('/login', [AuthenticatedSessionController::class, 'store'])
            ->middleware('throttle:5,1')
            ->name('tenant.login.store');
    });

    require base_path('routes/tenant-impersonation.php');

    Route::middleware('auth:web')->group(function () {
        Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
            ->name('tenant.logout');

        Route::get('/dashboard', [DashboardController::class, 'index'])->name('tenant.dashboard');

        require base_path('routes/tenant-business.php');
        require base_path('routes/tenant-stores.php');
        require base_path('routes/tenant-ledger.php');
        require base_path('routes/tenant-sales.php');
        require base_path('routes/tenant-purchase.php');
        require base_path('routes/tenant-sales-returns.php');
        require base_path('routes/tenant-purchase-returns.php');
        require base_path('routes/tenant-stock-adjustments.php');
        require base_path('routes/tenant-reports-accounting.php');
        require base_path('routes/tenant-reports-sales-purchase.php');
        require base_path('routes/tenant-reports-inventory.php');
        require base_path('routes/tenant-reports-tds.php');
        require base_path('routes/tenant-reports-stock-valuation.php');
        require base_path('routes/tenant-reports-item-wise-sales.php');
        require base_path('routes/tenant-reports-item-wise-purchase.php');
        require base_path('routes/tenant-reports-category-wise.php');
        require base_path('routes/tenant-reports-vat-summary.php');
        require base_path('routes/tenant-reports-stock-movement-register.php');
        require base_path('routes/tenant-employees.php');
        require base_path('routes/tenant-fixed-assets.php');
        require base_path('routes/tenant-quotations.php');
        require base_path('routes/tenant-receipts.php');
        require base_path('routes/tenant-payments.php');
        require base_path('routes/tenant-settings.php');
        require base_path('routes/tenant-item-varieties.php');
        require base_path('routes/tenant-backups.php');
        require base_path('routes/tenant-notices.php');
        require base_path('routes/tenant-activity-log.php');
        require base_path('routes/tenant-pos.php');
        require base_path('routes/tenant-fiscal-year-archive.php');
        require base_path('routes/tenant-agents.php');
    });
});
