<?php

declare(strict_types=1);

use App\Http\Controllers\Tenant\Purchases\CapitalPurchaseController;
use App\Http\Controllers\Tenant\Purchases\PurchaseController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenant: Purchase
|--------------------------------------------------------------------------
|
| Required from routes/tenant.php inside its auth:web group. Split into
| its own file per the parallel-work convention (see mem.md gotcha #5) -
| owned entirely by the Purchase build pass, do not add Sales routes here.
|
*/

Route::name('tenant.')->group(function () {
    Route::prefix('purchases')->name('purchases.')->group(function () {
        Route::get('/', [PurchaseController::class, 'index'])->name('index');
        Route::post('/', [PurchaseController::class, 'store'])->name('store');
        // Excel export (item 8) - ahead of {purchase}/print in the file only
        // for readability, the two never collide (different segment counts).
        Route::get('/export', [PurchaseController::class, 'export'])->name('export');
        // Cancelling reverses a posted voucher and takes stock back out, so
        // it is an admin action everywhere in this app (CONTRACTS C5).
        Route::post('/{purchase}/cancel', [PurchaseController::class, 'cancel'])
            ->middleware('role:admin')
            ->name('cancel');
        Route::get('/{purchase}/print', [PurchaseController::class, 'print'])->name('print');
    });

    Route::prefix('capital-purchases')->name('capital-purchases.')->group(function () {
        Route::get('/', [CapitalPurchaseController::class, 'index'])->name('index');
        Route::post('/', [CapitalPurchaseController::class, 'store'])->name('store');
        Route::get('/export', [CapitalPurchaseController::class, 'export'])->name('export');
        Route::post('/{capitalPurchase}/cancel', [CapitalPurchaseController::class, 'cancel'])
            ->middleware('role:admin')
            ->name('cancel');
    });
});
