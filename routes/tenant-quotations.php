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
        Route::get('/', [QuotationController::class, 'index'])->name('index');
        Route::post('/', [QuotationController::class, 'store'])->name('store');
        Route::put('/{quotation}', [QuotationController::class, 'update'])->name('update');
        Route::delete('/{quotation}', [QuotationController::class, 'destroy'])->name('destroy');
        Route::get('/{quotation}/print', [QuotationController::class, 'print'])->name('print');
        Route::post('/{quotation}/cancel', [QuotationController::class, 'cancel'])->name('cancel');
        Route::post('/{quotation}/convert-to-sale', [QuotationController::class, 'convertToSale'])->name('convert-to-sale');
    });
});
