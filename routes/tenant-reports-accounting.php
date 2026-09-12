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
    Route::get('/income-statement', [AccountingReportController::class, 'incomeStatement'])->name('income-statement');
    Route::get('/balance-sheet', [AccountingReportController::class, 'balanceSheet'])->name('balance-sheet');
    Route::get('/day-book', [AccountingReportController::class, 'dayBook'])->name('day-book');
    Route::get('/cash-book', [AccountingReportController::class, 'cashBook'])->name('cash-book');
    Route::get('/bank-book', [AccountingReportController::class, 'bankBook'])->name('bank-book');
});
