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
| Every route carries a `can:<permission>` gate from config/permissions.php
| (see todo/permissions/ROUTE-MAP.md). Cancellation has its own key on both
| series (sales.cancel, capital_sales.cancel; CONTRACTS C5): a cancellation
| posts a reversing voucher into the live books and voids an issued tax
| invoice, which the audit found any signed-in user could do.
|
*/

Route::name('tenant.')->group(function () {
    Route::prefix('sales')->name('sales.')->group(function () {
        Route::get('/', [SaleController::class, 'index'])->middleware('can:sales.view')->name('index');
        Route::get('/export', [SaleController::class, 'export'])->middleware('can:sales.export')->name('export');
        Route::post('/', [SaleController::class, 'store'])->middleware('can:sales.create')->name('store');
        Route::post('/{sale}/cancel', [SaleController::class, 'cancel'])->middleware('can:sales.cancel')->name('cancel');
        Route::get('/{sale}/print', [SaleController::class, 'print'])->middleware('can:sales.print')->name('print');
        // Sale::create()'s saved-note picker (audit section 4 polish, "note
        // templates") - a simple CRUD kept inside the Sales module rather
        // than under Settings, per the task's own "or a simple page under
        // Sales" option. Gated by sales.create: anyone who can create a sale
        // manages the picker (ROUTE-MAP shared lookup item 4).
        Route::post('/note-templates', [SaleController::class, 'storeNoteTemplate'])->middleware('can:sales.create')->name('note-templates.store');
        Route::delete('/note-templates/{saleNoteTemplate}', [SaleController::class, 'destroyNoteTemplate'])->middleware('can:sales.create')->name('note-templates.destroy');
    });

    Route::prefix('capital-sales')->name('capital-sales.')->group(function () {
        Route::get('/', [CapitalSaleController::class, 'index'])->middleware('can:capital_sales.view')->name('index');
        Route::post('/', [CapitalSaleController::class, 'store'])->middleware('can:capital_sales.create')->name('store');
        Route::post('/{capitalSale}/cancel', [CapitalSaleController::class, 'cancel'])->middleware('can:capital_sales.cancel')->name('cancel');
        Route::get('/{capitalSale}/print', [CapitalSaleController::class, 'print'])->middleware('can:capital_sales.print')->name('print');
    });
});
