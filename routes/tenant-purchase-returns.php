<?php

declare(strict_types=1);

use App\Http\Controllers\Tenant\Purchases\PurchaseReturnController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenant: Purchase Returns
|--------------------------------------------------------------------------
|
| Required from routes/tenant.php inside its auth:web group. Split into
| its own file per the parallel-work convention (see mem.md gotcha #5) -
| owned entirely by the Purchase Return build pass, do not add sales
| return routes here.
|
*/

Route::name('tenant.')->group(function () {
    Route::prefix('purchase-returns')->name('purchase-returns.')->group(function () {
        Route::get('/', [PurchaseReturnController::class, 'index'])->name('index');
        Route::post('/', [PurchaseReturnController::class, 'store'])->name('store');
        Route::post('/unlinked', [PurchaseReturnController::class, 'storeUnlinked'])->name('store-unlinked');
        Route::get('/export', [PurchaseReturnController::class, 'export'])->name('export');
        // Admin only: cancelling a debit note reverses its voucher and puts
        // the returned stock back (CONTRACTS C5).
        Route::post('/{purchaseReturn}/cancel', [PurchaseReturnController::class, 'cancel'])
            ->middleware('role:admin')
            ->name('cancel');
        Route::get('/{purchaseReturn}/print', [PurchaseReturnController::class, 'print'])->name('print');
    });
});
