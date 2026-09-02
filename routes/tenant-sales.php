<?php

declare(strict_types=1);

use App\Http\Controllers\Tenant\Sales\SaleController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenant: Sales
|--------------------------------------------------------------------------
|
| Required from routes/tenant.php inside its auth:web group. Split into
| its own file per the parallel-work convention (see mem.md gotcha #5) -
| owned entirely by the Sales build pass, do not add Purchase routes here.
|
*/

Route::name('tenant.')->group(function () {
    Route::prefix('sales')->name('sales.')->group(function () {
        Route::get('/', [SaleController::class, 'index'])->name('index');
        Route::post('/', [SaleController::class, 'store'])->name('store');
        Route::post('/{sale}/cancel', [SaleController::class, 'cancel'])->name('cancel');
        Route::get('/{sale}/print', [SaleController::class, 'print'])->name('print');
    });
});
