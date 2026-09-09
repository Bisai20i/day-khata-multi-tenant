<?php

declare(strict_types=1);

use App\Http\Controllers\Tenant\Reports\DamageLostStockReportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenant: Damage & Lost Stock Report
|--------------------------------------------------------------------------
|
| Required from routes/tenant.php inside its auth:web group. Split into
| its own file per the parallel-work convention (see mem.md gotcha #5) -
| owned entirely by the Damage & Lost Stock Report build pass, do not add
| accounting/sales/purchase/other-inventory report routes here.
|
*/

Route::name('tenant.reports.')->prefix('reports')->group(function () {
    Route::get('/damage-lost-stock', [DamageLostStockReportController::class, 'index'])->name('damage-lost-stock');
});
