<?php

declare(strict_types=1);

use App\Http\Controllers\Tenant\Sales\ReceiptController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenant: Receipts
|--------------------------------------------------------------------------
|
| Required from routes/tenant.php inside its auth:web group. Split into
| its own file per the parallel-work convention (see mem.md gotcha #5) -
| owned entirely by the Receipt build pass, do not add payment routes
| here.
|
*/

Route::name('tenant.')->group(function () {
    Route::prefix('receipts')->name('receipts.')->group(function () {
        Route::get('/', [ReceiptController::class, 'index'])->name('index');
        Route::post('/', [ReceiptController::class, 'store'])->name('store');
        Route::post('/{receipt}/cancel', [ReceiptController::class, 'cancel'])->name('cancel');
    });
});
