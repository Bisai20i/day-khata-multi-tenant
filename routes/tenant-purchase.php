<?php

declare(strict_types=1);

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
        Route::post('/{purchase}/cancel', [PurchaseController::class, 'cancel'])->name('cancel');
        Route::get('/{purchase}/print', [PurchaseController::class, 'print'])->name('print');
    });
});
