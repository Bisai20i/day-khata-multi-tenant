<?php

declare(strict_types=1);

use App\Http\Controllers\Tenant\Purchases\CapitalPurchaseController;
use App\Http\Controllers\Tenant\Purchases\CapitalPurchaseSettlementController;
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
        Route::get('/', [PurchaseController::class, 'index'])->middleware('can:purchases.view')->name('index');
        Route::post('/', [PurchaseController::class, 'store'])->middleware('can:purchases.create')->name('store');
        // Excel export (item 8) - ahead of {purchase}/print in the file only
        // for readability, the two never collide (different segment counts).
        Route::get('/export', [PurchaseController::class, 'export'])->middleware('can:purchases.export')->name('export');
        // Cancelling reverses a posted voucher and takes stock back out, so
        // it has its own key, purchases.cancel (CONTRACTS C5).
        Route::post('/{purchase}/cancel', [PurchaseController::class, 'cancel'])
            ->middleware('can:purchases.cancel')
            ->name('cancel');
        Route::get('/{purchase}/print', [PurchaseController::class, 'print'])->middleware('can:purchases.print')->name('print');
    });

    Route::prefix('capital-purchases')->name('capital-purchases.')->group(function () {
        Route::get('/', [CapitalPurchaseController::class, 'index'])->middleware('can:capital_purchases.view')->name('index');
        Route::post('/', [CapitalPurchaseController::class, 'store'])->middleware('can:capital_purchases.create')->name('store');
        Route::get('/export', [CapitalPurchaseController::class, 'export'])->middleware('can:capital_purchases.export')->name('export');
        Route::get('/{capitalPurchase}/print', [CapitalPurchaseController::class, 'print'])->middleware('can:capital_purchases.print')->name('print');
        Route::post('/{capitalPurchase}/cancel', [CapitalPurchaseController::class, 'cancel'])
            ->middleware('can:capital_purchases.cancel')
            ->name('cancel');
        Route::post('/{capitalPurchase}/settlements', [CapitalPurchaseSettlementController::class, 'store'])
            ->middleware('can:capital_purchase_settlements.create')
            ->name('settlements.store');
        Route::post('/settlements/{settlement}/cancel', [CapitalPurchaseSettlementController::class, 'cancel'])
            ->middleware('can:capital_purchase_settlements.cancel')
            ->name('settlements.cancel');
    });
});
