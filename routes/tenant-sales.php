<?php

declare(strict_types=1);

use App\Http\Controllers\Tenant\Sales\CapitalSaleController;
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
| Cancellation is admin-only on both series (CONTRACTS C5): a cancellation
| posts a reversing voucher into the live books and voids an issued tax
| invoice, which the audit found any signed-in user could do.
|
*/

Route::name('tenant.')->group(function () {
    Route::prefix('sales')->name('sales.')->group(function () {
        Route::get('/', [SaleController::class, 'index'])->name('index');
        Route::post('/', [SaleController::class, 'store'])->name('store');
        Route::post('/{sale}/cancel', [SaleController::class, 'cancel'])->middleware('role:admin')->name('cancel');
        Route::get('/{sale}/print', [SaleController::class, 'print'])->name('print');
    });

    Route::prefix('capital-sales')->name('capital-sales.')->group(function () {
        Route::get('/', [CapitalSaleController::class, 'index'])->name('index');
        Route::post('/', [CapitalSaleController::class, 'store'])->name('store');
        Route::post('/{capitalSale}/cancel', [CapitalSaleController::class, 'cancel'])->middleware('role:admin')->name('cancel');
        Route::get('/{capitalSale}/print', [CapitalSaleController::class, 'print'])->name('print');
    });
});
