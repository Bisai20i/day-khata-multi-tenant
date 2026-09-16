<?php

declare(strict_types=1);

use App\Http\Controllers\Tenant\Reports\AccountingReportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenant: Accounting Reports
|--------------------------------------------------------------------------
|
| Required from routes/tenant.php inside its auth:web group. Split into
| its own file per the parallel-work convention (see mem.md gotcha #5) -
| owned entirely by the Accounting Reports build pass, do not add
| sales/purchase/inventory report routes here.
|
*/

// Admin-only throughout (audit P1, missing role gates): the financial
// statements and the three books expose the whole company's position -
// margins, capital, every bank balance - which is a different sensitivity
// class from the operational sales/stock reports a counter user needs.
Route::name('tenant.reports.')->prefix('reports')->middleware('role:admin')->group(function () {
    Route::get('/trial-balance', [AccountingReportController::class, 'trialBalance'])->name('trial-balance');
    Route::get('/trial-balance/print', [AccountingReportController::class, 'trialBalancePdf'])->name('trial-balance.print');
    Route::get('/trial-balance/export', [AccountingReportController::class, 'trialBalanceExport'])->name('trial-balance.export');

    Route::get('/income-statement', [AccountingReportController::class, 'incomeStatement'])->name('income-statement');
    Route::get('/income-statement/print', [AccountingReportController::class, 'incomeStatementPdf'])->name('income-statement.print');
    Route::get('/income-statement/export', [AccountingReportController::class, 'incomeStatementExport'])->name('income-statement.export');

    Route::get('/balance-sheet', [AccountingReportController::class, 'balanceSheet'])->name('balance-sheet');
    Route::get('/balance-sheet/print', [AccountingReportController::class, 'balanceSheetPdf'])->name('balance-sheet.print');
    Route::get('/balance-sheet/export', [AccountingReportController::class, 'balanceSheetExport'])->name('balance-sheet.export');

    Route::get('/day-book', [AccountingReportController::class, 'dayBook'])->name('day-book');
    Route::get('/day-book/print', [AccountingReportController::class, 'dayBookPdf'])->name('day-book.print');
    Route::get('/day-book/export', [AccountingReportController::class, 'dayBookExport'])->name('day-book.export');

    Route::get('/cash-book', [AccountingReportController::class, 'cashBook'])->name('cash-book');
    Route::get('/cash-book/print', [AccountingReportController::class, 'cashBookPdf'])->name('cash-book.print');
    Route::get('/cash-book/export', [AccountingReportController::class, 'cashBookExport'])->name('cash-book.export');

    Route::get('/bank-book', [AccountingReportController::class, 'bankBook'])->name('bank-book');
    Route::get('/bank-book/print', [AccountingReportController::class, 'bankBookPdf'])->name('bank-book.print');
    Route::get('/bank-book/export', [AccountingReportController::class, 'bankBookExport'])->name('bank-book.export');

    // Cancelled Documents (T14 task 4): every cancelled sale, purchase,
    // return, receipt, payment, capital document and journal/cash-bank
    // voucher in one audit list.
    Route::get('/cancelled-documents', [AccountingReportController::class, 'cancelledDocuments'])->name('cancelled-documents');
    Route::get('/cancelled-documents/export', [AccountingReportController::class, 'cancelledDocumentsExport'])->name('cancelled-documents.export');
});
