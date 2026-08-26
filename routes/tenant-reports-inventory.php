<?php

declare(strict_types=1);

use App\Http\Controllers\Tenant\Reports\InventoryReportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenant: Inventory Reports
|--------------------------------------------------------------------------
|
| Required from routes/tenant.php inside its auth:web group. Split into
| its own file per the parallel-work convention (see mem.md gotcha #5) -
| owned entirely by the Inventory Reports build pass, do not add
| accounting/sales/purchase report routes here.
|
*/

Route::name('tenant.reports.')->prefix('reports')->group(function () {
    Route::get('/stock-summary', [InventoryReportController::class, 'stockSummary'])->name('stock-summary');
});
