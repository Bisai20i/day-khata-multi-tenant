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

// Permission-gated per route (audit P1, missing role gates) with keys kept
// apart from the operational report keys: financial_statements.*,
// day_book.*, cash_bank_book.* and cancelled_documents.*. The financial
// statements and the three books expose the whole company's position -
// margins, capital, every bank balance - which is a different sensitivity
// class from the operational sales/stock reports a counter user needs.
Route::name('tenant.reports.')->prefix('reports')->group(function () {
    Route::get('/trial-balance', [AccountingReportController::class, 'trialBalance'])->middleware('can:financial_statements.view')->name('trial-balance');
    Route::get('/trial-balance/print', [AccountingReportController::class, 'trialBalancePdf'])->middleware('can:financial_statements.print')->name('trial-balance.print');
    Route::get('/trial-balance/export', [AccountingReportController::class, 'trialBalanceExport'])->middleware('can:financial_statements.export')->name('trial-balance.export');

    Route::get('/income-statement', [AccountingReportController::class, 'incomeStatement'])->middleware('can:financial_statements.view')->name('income-statement');
    Route::get('/income-statement/print', [AccountingReportController::class, 'incomeStatementPdf'])->middleware('can:financial_statements.print')->name('income-statement.print');
    Route::get('/income-statement/export', [AccountingReportController::class, 'incomeStatementExport'])->middleware('can:financial_statements.export')->name('income-statement.export');

    Route::get('/balance-sheet', [AccountingReportController::class, 'balanceSheet'])->middleware('can:financial_statements.view')->name('balance-sheet');
    Route::get('/balance-sheet/print', [AccountingReportController::class, 'balanceSheetPdf'])->middleware('can:financial_statements.print')->name('balance-sheet.print');
    Route::get('/balance-sheet/export', [AccountingReportController::class, 'balanceSheetExport'])->middleware('can:financial_statements.export')->name('balance-sheet.export');

    Route::get('/day-book', [AccountingReportController::class, 'dayBook'])->middleware('can:day_book.view')->name('day-book');
    Route::get('/day-book/print', [AccountingReportController::class, 'dayBookPdf'])->middleware('can:day_book.print')->name('day-book.print');
    Route::get('/day-book/export', [AccountingReportController::class, 'dayBookExport'])->middleware('can:day_book.export')->name('day-book.export');

    Route::get('/cash-book', [AccountingReportController::class, 'cashBook'])->middleware('can:cash_bank_book.view')->name('cash-book');
    Route::get('/cash-book/print', [AccountingReportController::class, 'cashBookPdf'])->middleware('can:cash_bank_book.print')->name('cash-book.print');
    Route::get('/cash-book/export', [AccountingReportController::class, 'cashBookExport'])->middleware('can:cash_bank_book.export')->name('cash-book.export');

    Route::get('/bank-book', [AccountingReportController::class, 'bankBook'])->middleware('can:cash_bank_book.view')->name('bank-book');
    Route::get('/bank-book/print', [AccountingReportController::class, 'bankBookPdf'])->middleware('can:cash_bank_book.print')->name('bank-book.print');
    Route::get('/bank-book/export', [AccountingReportController::class, 'bankBookExport'])->middleware('can:cash_bank_book.export')->name('bank-book.export');

    // Cancelled Documents (T14 task 4): every cancelled sale, purchase,
    // return, receipt, payment, capital document and journal/cash-bank
    // voucher in one audit list.
    Route::get('/cancelled-documents', [AccountingReportController::class, 'cancelledDocuments'])->middleware('can:cancelled_documents.view')->name('cancelled-documents');
    Route::get('/cancelled-documents/export', [AccountingReportController::class, 'cancelledDocumentsExport'])->middleware('can:cancelled_documents.export')->name('cancelled-documents.export');
});
