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
    Route::get('/sales-register', [SalesPurchaseReportController::class, 'salesRegister'])->middleware('can:sales_reports.view')->name('sales-register');
    Route::get('/purchase-register', [SalesPurchaseReportController::class, 'purchaseRegister'])->middleware('can:purchase_reports.view')->name('purchase-register');
    Route::get('/sales-vat-book', [SalesPurchaseReportController::class, 'salesVatBook'])->middleware('can:tax_reports.view')->name('sales-vat-book');
    Route::get('/sales-vat-book/export', [SalesPurchaseReportController::class, 'salesVatBookExport'])->middleware('can:tax_reports.export')->name('sales-vat-book.export');
    Route::get('/purchase-vat-book', [SalesPurchaseReportController::class, 'purchaseVatBook'])->middleware('can:tax_reports.view')->name('purchase-vat-book');
    Route::get('/purchase-vat-book/export', [SalesPurchaseReportController::class, 'purchaseVatBookExport'])->middleware('can:tax_reports.export')->name('purchase-vat-book.export');
    Route::get('/sales-return-register', [SalesPurchaseReportController::class, 'salesReturnRegister'])->middleware('can:sales_reports.view')->name('sales-return-register');
    Route::get('/sales-return-register/export', [SalesPurchaseReportController::class, 'salesReturnRegisterExport'])->middleware('can:sales_reports.export')->name('sales-return-register.export');
    Route::get('/purchase-return-register', [SalesPurchaseReportController::class, 'purchaseReturnRegister'])->middleware('can:purchase_reports.view')->name('purchase-return-register');
    Route::get('/purchase-return-register/export', [SalesPurchaseReportController::class, 'purchaseReturnRegisterExport'])->middleware('can:purchase_reports.export')->name('purchase-return-register.export');
    Route::get('/aged-receivables', [SalesPurchaseReportController::class, 'agedReceivables'])->middleware('can:receivables_reports.view')->name('aged-receivables');
    Route::get('/aged-payables', [SalesPurchaseReportController::class, 'agedPayables'])->middleware('can:payables_reports.view')->name('aged-payables');
    Route::get('/debtors', [SalesPurchaseReportController::class, 'debtors'])->middleware('can:receivables_reports.view')->name('debtors');
    Route::get('/debtors/export', [SalesPurchaseReportController::class, 'debtorsExport'])->middleware('can:receivables_reports.export')->name('debtors.export');
    Route::get('/creditors', [SalesPurchaseReportController::class, 'creditors'])->middleware('can:payables_reports.view')->name('creditors');
    Route::get('/creditors/export', [SalesPurchaseReportController::class, 'creditorsExport'])->middleware('can:payables_reports.export')->name('creditors.export');
});
