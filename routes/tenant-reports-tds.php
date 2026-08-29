<?php

declare(strict_types=1);

use App\Http\Controllers\Tenant\Reports\TdsReportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenant: TDS Report
|--------------------------------------------------------------------------
|
| Required from routes/tenant.php inside its auth:web group. Split into
| its own file per the parallel-work convention (see mem.md gotcha #5) -
| owned entirely by the TDS Report build pass, do not add other
| compliance/accounting report routes here.
|
*/

Route::name('tenant.reports.')->prefix('reports')->group(function () {
    Route::get('/tds', [TdsReportController::class, 'index'])->name('tds');
});
