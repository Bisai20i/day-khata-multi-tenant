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
        // Cancelling takes posted quantities back out of stock, and for an
        // opening-stock batch it reverses a ledger voucher too, so it is an
        // admin action everywhere in this app (CONTRACTS C5).
        Route::post('/{stock_adjustment}/cancel', [StockAdjustmentController::class, 'cancel'])
            ->middleware('role:admin')
            ->name('cancel');
        Route::get('/{stock_adjustment}/print', [StockAdjustmentController::class, 'print'])->name('print');
        Route::get('/opening-stock/template', [StockAdjustmentController::class, 'openingStockTemplate'])->name('opening-stock.template');
        Route::post('/opening-stock/import', [StockAdjustmentController::class, 'importOpeningStock'])->name('opening-stock.import');
    });
});
