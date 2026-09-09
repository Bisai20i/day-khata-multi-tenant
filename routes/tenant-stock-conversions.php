<?php

declare(strict_types=1);

use App\Http\Controllers\Tenant\Inventory\StockConversionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenant: Stock Conversions (Production / Refining)
|--------------------------------------------------------------------------
|
| Required from routes/tenant.php inside its auth:web group. Split into
| its own file per the parallel-work convention (see mem.md gotcha #5),
| mirroring routes/tenant-stock-adjustments.php. One resource backs both
| the Production and Refining screens - see StockConversion's own docblock
| for why they share a single model/controller distinguished only by
| `type`, the same way CapitalPurchase's `type in:capital,service` does.
|
*/

Route::name('tenant.')->group(function () {
    Route::prefix('stock-conversions')->name('stock-conversions.')->group(function () {
        Route::get('/', [StockConversionController::class, 'index'])->name('index');
        Route::post('/', [StockConversionController::class, 'store'])->name('store');
        Route::post('/{stock_conversion}/cancel', [StockConversionController::class, 'cancel'])->name('cancel');
        Route::get('/{stock_conversion}/print', [StockConversionController::class, 'print'])->name('print');
    });
});
