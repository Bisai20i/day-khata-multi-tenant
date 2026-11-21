<?php

declare(strict_types=1);

use App\Http\Controllers\Tenant\Reports\StockPotentialProfitReportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenant: Stock with Potential Profit
|--------------------------------------------------------------------------
|
| Required from routes/tenant.php inside its auth:web group, next to the
| stock valuation routes. It shows the same per-item cost as Stock Valuation, so
| it is gated by stock_valuation.view, exactly like Stock Valuation.
|
*/

Route::name('tenant.reports.')->prefix('reports')->group(function () {
    Route::get('/stock-potential-profit', [StockPotentialProfitReportController::class, 'index'])->middleware('can:stock_valuation.view')->name('stock-potential-profit');
    Route::get('/stock-potential-profit/export', [StockPotentialProfitReportController::class, 'export'])->middleware(['can:stock_valuation.view', 'can:stock_reports.export'])->name('stock-potential-profit.export');
});
