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
        Route::get('/', [PurchaseReturnController::class, 'index'])->middleware('can:purchase_returns.view')->name('index');
        Route::post('/', [PurchaseReturnController::class, 'store'])->middleware('can:purchase_returns.create')->name('store');
        Route::post('/unlinked', [PurchaseReturnController::class, 'storeUnlinked'])
            ->middleware('can:unlinked_purchase_returns.create')
            ->name('store-unlinked');
        // Read-only price check for the unlinked form (flags G-16); same
        // key as the post it previews (unlinked_purchase_returns.create).
        Route::get('/unlinked/quote', [PurchaseReturnController::class, 'quoteUnlinked'])
            ->middleware('can:unlinked_purchase_returns.create')
            ->name('quote-unlinked');
        Route::get('/export', [PurchaseReturnController::class, 'export'])->middleware('can:purchase_returns.export')->name('export');
        // Own key (purchase_returns.cancel): cancelling a debit note reverses
        // its voucher and puts the returned stock back (CONTRACTS C5).
        Route::post('/{purchaseReturn}/cancel', [PurchaseReturnController::class, 'cancel'])
            ->middleware('can:purchase_returns.cancel')
            ->name('cancel');
        Route::get('/{purchaseReturn}/print', [PurchaseReturnController::class, 'print'])->middleware('can:purchase_returns.print')->name('print');
    });
});
