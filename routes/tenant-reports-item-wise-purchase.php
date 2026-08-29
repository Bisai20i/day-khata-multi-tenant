<?php

declare(strict_types=1);

use App\Http\Controllers\Tenant\Reports\ItemWisePurchaseReportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenant: Item-wise Purchase Report
|--------------------------------------------------------------------------
|
| Required from routes/tenant.php inside its auth:web group. Split into
| its own file per the parallel-work convention (see mem.md gotcha #5) -
| owned entirely by the Item-wise Purchase Report build pass, do not add
| other report routes here.
|
*/

Route::name('tenant.reports.')->prefix('reports')->group(function () {
    Route::get('/item-wise-purchase', [ItemWisePurchaseReportController::class, 'index'])->name('item-wise-purchase');
});
