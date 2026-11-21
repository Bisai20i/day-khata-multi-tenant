<?php

declare(strict_types=1);

use App\Http\Controllers\Tenant\Reports\StockMovementRegisterController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenant: Stock Movement Register Report
|--------------------------------------------------------------------------
|
| Required from routes/tenant.php inside its auth:web group. Split into
| its own file per the parallel-work convention (see mem.md gotcha #5) -
| owned entirely by the Stock Movement Register build pass, do not add
| accounting/sales/purchase/other-inventory report routes here.
|
*/

Route::name('tenant.reports.')->prefix('reports')->group(function () {
    Route::get('/stock-movement-register', [StockMovementRegisterController::class, 'index'])->middleware('can:stock_reports.view')->name('stock-movement-register');
    Route::get('/stock-movement-register/export', [StockMovementRegisterController::class, 'export'])->middleware(['can:stock_reports.view', 'can:stock_reports.export'])->name('stock-movement-register.export');
});
