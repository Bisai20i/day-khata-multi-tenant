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
    Route::get('/sales-vat-book/export', [SalesPurchaseReportController::class, 'salesVatBookExport'])->name('sales-vat-book.export');
    Route::get('/purchase-vat-book', [SalesPurchaseReportController::class, 'purchaseVatBook'])->name('purchase-vat-book');
    Route::get('/purchase-vat-book/export', [SalesPurchaseReportController::class, 'purchaseVatBookExport'])->name('purchase-vat-book.export');
    Route::get('/sales-return-register', [SalesPurchaseReportController::class, 'salesReturnRegister'])->name('sales-return-register');
    Route::get('/sales-return-register/export', [SalesPurchaseReportController::class, 'salesReturnRegisterExport'])->name('sales-return-register.export');
    Route::get('/purchase-return-register', [SalesPurchaseReportController::class, 'purchaseReturnRegister'])->name('purchase-return-register');
    Route::get('/purchase-return-register/export', [SalesPurchaseReportController::class, 'purchaseReturnRegisterExport'])->name('purchase-return-register.export');
    Route::get('/aged-receivables', [SalesPurchaseReportController::class, 'agedReceivables'])->name('aged-receivables');
    Route::get('/aged-payables', [SalesPurchaseReportController::class, 'agedPayables'])->name('aged-payables');
    Route::get('/debtors', [SalesPurchaseReportController::class, 'debtors'])->name('debtors');
    Route::get('/debtors/export', [SalesPurchaseReportController::class, 'debtorsExport'])->name('debtors.export');
    Route::get('/creditors', [SalesPurchaseReportController::class, 'creditors'])->name('creditors');
    Route::get('/creditors/export', [SalesPurchaseReportController::class, 'creditorsExport'])->name('creditors.export');
});
