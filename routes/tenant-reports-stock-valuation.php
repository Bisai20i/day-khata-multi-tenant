<?php

declare(strict_types=1);

use App\Http\Controllers\Tenant\Reports\StockValuationReportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenant: Stock Valuation Report
|--------------------------------------------------------------------------
|
| Required from routes/tenant.php inside its auth:web group. Split into
| its own file per the parallel-work convention (see mem.md gotcha #5) -
| owned entirely by the Stock Valuation Report build pass, do not add
| accounting/sales/purchase/inventory report routes here.
|
*/

Route::name('tenant.reports.')->prefix('reports')->group(function () {
    Route::get('/stock-valuation', [StockValuationReportController::class, 'index'])->name('stock-valuation');
});
