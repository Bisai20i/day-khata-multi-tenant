<?php

declare(strict_types=1);

use App\Http\Controllers\Tenant\Reports\ItemWiseSalesReportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenant: Item-wise Sales Report
|--------------------------------------------------------------------------
|
| Required from routes/tenant.php inside its auth:web group. Split into
| its own file per the parallel-work convention (see mem.md gotcha #5) -
| owned entirely by the Item-wise Sales Report build pass, do not add
| other report routes here.
|
*/

Route::name('tenant.reports.')->prefix('reports')->group(function () {
    Route::get('/item-wise-sales', [ItemWiseSalesReportController::class, 'index'])->name('item-wise-sales');
});
