<?php

declare(strict_types=1);

use App\Http\Controllers\Tenant\Inventory\StockTransferController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenant: Stock Transfers
|--------------------------------------------------------------------------
|
| Required from routes/tenant.php inside its auth:web group. Split into
| its own file per the parallel-work convention (see mem.md gotcha #5),
| mirroring routes/tenant-stock-adjustments.php.
|
*/

Route::name('tenant.')->group(function () {
    Route::prefix('stock-transfers')->name('stock-transfers.')->group(function () {
        Route::get('/', [StockTransferController::class, 'index'])->middleware('can:stock_transfers.view')->name('index');
        Route::post('/', [StockTransferController::class, 'store'])->middleware('can:stock_transfers.create')->name('store');
        // Cancelling reverses both halves of the movement, so it has its own
        // key, stock_transfers.cancel (CONTRACTS C5).
        Route::post('/{stock_transfer}/cancel', [StockTransferController::class, 'cancel'])
            ->middleware('can:stock_transfers.cancel')
            ->name('cancel');
        Route::get('/{stock_transfer}/print', [StockTransferController::class, 'print'])->middleware('can:stock_transfers.print')->name('print');
    });
});
