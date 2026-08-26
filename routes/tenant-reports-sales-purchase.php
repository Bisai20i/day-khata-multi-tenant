<?php

declare(strict_types=1);

use App\Http\Controllers\Tenant\Reports\SalesPurchaseReportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenant: Sales/Purchase Reports
|--------------------------------------------------------------------------
|
| Required from routes/tenant.php inside its auth:web group. Split into
| its own file per the parallel-work convention (see mem.md gotcha #5) -
| owned entirely by the Sales/Purchase Reports build pass, do not add
| accounting/inventory report routes here.
|
*/

Route::name('tenant.reports.')->prefix('reports')->group(function () {
    Route::get('/sales-register', [SalesPurchaseReportController::class, 'salesRegister'])->name('sales-register');
    Route::get('/purchase-register', [SalesPurchaseReportController::class, 'purchaseRegister'])->name('purchase-register');
    Route::get('/sales-vat-book', [SalesPurchaseReportController::class, 'salesVatBook'])->name('sales-vat-book');
    Route::get('/purchase-vat-book', [SalesPurchaseReportController::class, 'purchaseVatBook'])->name('purchase-vat-book');
});
