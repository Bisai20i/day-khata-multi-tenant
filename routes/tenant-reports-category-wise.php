<?php

declare(strict_types=1);

use App\Http\Controllers\Tenant\Reports\CategoryWiseReportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenant: Category-wise Reports
|--------------------------------------------------------------------------
|
| Required from routes/tenant.php inside its auth:web group. Split into
| its own file per the parallel-work convention (see mem.md gotcha #5) -
| owned entirely by the Category-wise Reports build pass, do not add
| item-wise/other report routes here.
|
*/

Route::name('tenant.reports.')->prefix('reports')->group(function () {
    Route::get('/sales-by-category', [CategoryWiseReportController::class, 'salesByCategory'])->name('sales-by-category');
    Route::get('/purchase-by-category', [CategoryWiseReportController::class, 'purchaseByCategory'])->name('purchase-by-category');
    Route::get('/stock-by-category', [CategoryWiseReportController::class, 'stockByCategory'])->name('stock-by-category');
});
