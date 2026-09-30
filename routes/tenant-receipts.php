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
        Route::get('/', [ReceiptController::class, 'index'])->middleware('can:receipts.view')->name('index');
        Route::post('/', [ReceiptController::class, 'store'])->middleware('can:receipts.create')->name('store');
        Route::get('/{receipt}/print', [ReceiptController::class, 'print'])->middleware('can:receipts.print')->name('print');
        // Cancelling a receipt reverses money already collected, so it has
        // its own key, receipts.cancel (CONTRACTS C5).
        Route::post('/{receipt}/cancel', [ReceiptController::class, 'cancel'])
            ->middleware('can:receipts.cancel')
            ->name('cancel');
    });
});
