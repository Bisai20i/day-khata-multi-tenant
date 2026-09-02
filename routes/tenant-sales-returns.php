<?php

declare(strict_types=1);

use App\Http\Controllers\Tenant\Sales\SalesReturnController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenant: Sales Returns
|--------------------------------------------------------------------------
|
| Required from routes/tenant.php inside its auth:web group. Split into
| its own file per the parallel-work convention (see mem.md gotcha #5) -
| owned entirely by the Sales Return build pass, do not add purchase
| return routes here.
|
*/

Route::name('tenant.')->group(function () {
    Route::prefix('sales-returns')->name('sales-returns.')->group(function () {
        Route::get('/', [SalesReturnController::class, 'index'])->name('index');
        Route::post('/', [SalesReturnController::class, 'store'])->name('store');
        Route::post('/{salesReturn}/cancel', [SalesReturnController::class, 'cancel'])->name('cancel');
        Route::get('/{salesReturn}/print', [SalesReturnController::class, 'print'])->name('print');
    });
});
