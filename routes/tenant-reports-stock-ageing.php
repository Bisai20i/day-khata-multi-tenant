<?php

declare(strict_types=1);

use App\Http\Controllers\Tenant\Reports\StockAgeingReportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenant: Stock (Item) Ageing Report
|--------------------------------------------------------------------------
|
| Required from routes/tenant.php inside its auth:web group. Own file per
| the parallel-work convention (see mem.md gotcha #5).
|
*/

Route::name('tenant.reports.')->prefix('reports')->group(function () {
    Route::get('/stock-ageing', [StockAgeingReportController::class, 'index'])->middleware('can:stock_ageing.view')->name('stock-ageing');
});
