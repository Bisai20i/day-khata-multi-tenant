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
        Route::get('/', [StockAdjustmentController::class, 'index'])->middleware('can:stock_adjustments.view')->name('index');
        Route::post('/', [StockAdjustmentController::class, 'store'])->middleware('can:stock_adjustments.create')->name('store');
        // Cancelling takes posted quantities back out of stock, and for an
        // opening-stock batch it reverses a ledger voucher too, so it has its
        // own key, stock_adjustments.cancel (CONTRACTS C5).
        Route::post('/{stock_adjustment}/cancel', [StockAdjustmentController::class, 'cancel'])
            ->middleware('can:stock_adjustments.cancel')
            ->name('cancel');
        Route::get('/{stock_adjustment}/print', [StockAdjustmentController::class, 'print'])->middleware('can:stock_adjustments.print')->name('print');
        Route::get('/opening-stock/template', [StockAdjustmentController::class, 'openingStockTemplate'])->middleware('can:opening_stock.import')->name('opening-stock.template');
        Route::post('/opening-stock/import', [StockAdjustmentController::class, 'importOpeningStock'])->middleware('can:opening_stock.import')->name('opening-stock.import');
    });
});
