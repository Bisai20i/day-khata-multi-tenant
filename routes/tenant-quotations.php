<?php

declare(strict_types=1);

use App\Http\Controllers\Tenant\Sales\QuotationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenant: Quotations
|--------------------------------------------------------------------------
|
| Required from routes/tenant.php inside its auth:web group. Owned entirely
| by the Quotations build pass, per the parallel-work file-ownership
| convention (see mem.md gotcha #5).
|
*/

Route::name('tenant.')->group(function () {
    Route::prefix('quotations')->name('quotations.')->group(function () {
        Route::get('/', [QuotationController::class, 'index'])->middleware('can:quotations.view')->name('index');
        Route::post('/', [QuotationController::class, 'store'])->middleware('can:quotations.create')->name('store');
        Route::put('/{quotation}', [QuotationController::class, 'update'])->middleware('can:quotations.edit')->name('update');
        Route::delete('/{quotation}', [QuotationController::class, 'destroy'])->middleware('can:quotations.delete')->name('destroy');
        Route::get('/{quotation}/print', [QuotationController::class, 'print'])->middleware('can:quotations.print')->name('print');
        Route::post('/{quotation}/cancel', [QuotationController::class, 'cancel'])->middleware('can:quotations.cancel')->name('cancel');
        // Gated by a quotations-module key so disabling the module closes it;
        // the action creates a sale, so QuotationController::convertToSale()
        // must also check sales.create (P05; ROUTE-MAP shared lookup item 6).
        Route::post('/{quotation}/convert-to-sale', [QuotationController::class, 'convertToSale'])->middleware('can:quotations.edit')->name('convert-to-sale');
    });
});
