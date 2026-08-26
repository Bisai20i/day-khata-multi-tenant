<?php

declare(strict_types=1);

use App\Http\Controllers\Tenant\Inventory\StockAdjustmentController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenant: Stock Adjustments
|--------------------------------------------------------------------------
|
| Required from routes/tenant.php inside its auth:web group. Split into
| its own file per the parallel-work convention (see mem.md gotcha #5) -
| owned entirely by the Stock Adjustment build pass.
|
*/

Route::name('tenant.')->group(function () {
    Route::prefix('stock-adjustments')->name('stock-adjustments.')->group(function () {
        Route::get('/', [StockAdjustmentController::class, 'index'])->name('index');
        Route::post('/', [StockAdjustmentController::class, 'store'])->name('store');
        Route::post('/{stock_adjustment}/cancel', [StockAdjustmentController::class, 'cancel'])->name('cancel');
    });
});
