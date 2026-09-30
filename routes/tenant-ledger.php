<?php

declare(strict_types=1);

use App\Http\Controllers\Tenant\Accounting\AccountController;
use App\Http\Controllers\Tenant\Accounting\FiscalYearController;
use App\Http\Controllers\Tenant\Accounting\JournalVoucherController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenant: Ledger / Journal Voucher Posting Engine
|--------------------------------------------------------------------------
|
| Fiscal years, journal vouchers, and the account ledger report. Required
| from routes/tenant.php inside its auth:web group. Split into its own
| file per the parallel-work convention (see mem.md gotcha #5).
|
*/

Route::name('tenant.')->group(function () {
    Route::prefix('fiscal-years')->name('fiscal-years.')->group(function () {
        Route::get('/', [FiscalYearController::class, 'index'])->middleware('can:fiscal_year.view')->name('index');
        Route::post('/', [FiscalYearController::class, 'store'])->middleware('can:fiscal_year.create')->name('store');
        // Close, reopen and relock share the owner-only
        // fiscal_year.close_archive key: a non-owner reopening a year only
        // the owner may close would undo the owner's decision.
        Route::post('/{fiscalYear}/close', [FiscalYearController::class, 'close'])
            ->middleware('can:fiscal_year.close_archive')
            ->name('close');
        Route::post('/{fiscalYear}/reopen', [FiscalYearController::class, 'reopen'])
            ->middleware('can:fiscal_year.close_archive')
            ->name('reopen');
        Route::post('/{fiscalYear}/lock', [FiscalYearController::class, 'relock'])
            ->middleware('can:fiscal_year.close_archive')
            ->name('lock');
    });

    // Posting and cancelling a manual journal voucher have their own keys
    // (journal_vouchers.create, cash_bank_vouchers.create,
    // journal_vouchers.cancel): it is the one screen that can move money
    // between any two accounts with no source document behind it, and its
    // cancel path reverses the ledger. Viewing, printing and exporting are
    // separate keys so a role can look a posting up without posting (audit
    // P1, no admin gate on journal vouchers or their cancel paths).
    Route::prefix('journal-vouchers')->name('journal-vouchers.')->group(function () {
        Route::get('/', [JournalVoucherController::class, 'index'])->middleware('can:journal_vouchers.view')->name('index');
        Route::get('/export', [JournalVoucherController::class, 'export'])->middleware('can:journal_vouchers.export')->name('export');
        Route::post('/', [JournalVoucherController::class, 'store'])->middleware('can:journal_vouchers.create')->name('store');
        // Cash/Bank vouchers (T14, CONTRACTS/accounting parity): Cash
        // Receipt, Cash Payment, Bank Receipt, Bank Payment, Contra. Posted
        // through the same controller/model as a manual Journal voucher
        // since neither has a separate owning record - see
        // JournalVoucher::postCashBank().
        Route::post('/cash-bank', [JournalVoucherController::class, 'storeCashBank'])->middleware('can:cash_bank_vouchers.create')->name('cash-bank.store');
        Route::post('/{journalVoucher}/cancel', [JournalVoucherController::class, 'cancel'])->middleware('can:journal_vouchers.cancel')->name('cancel');
        Route::get('/{journalVoucher}/print', [JournalVoucherController::class, 'print'])->middleware('can:journal_vouchers.print')->name('print');
    });

    // Controller-authorized, deliberately no `can:` middleware (ROUTE-MAP
    // shared lookup item 1). Each route needs account_ledger.view/.print/
    // .export (any account, accounting module) OR party_ledger.view/.print/
    // .export when the account belongs to a customer or supplier (core
    // module). A single `can:` cannot express "either key, the second only
    // for party accounts", so AccountController::ledger(), ledgerPrint() and
    // ledgerExport() perform that check and 403 otherwise (P06), and the
    // P09 route-audit test lists these three routes as controller-authorized.
    Route::get('/accounts/{account}/ledger', [AccountController::class, 'ledger'])->name('accounts.ledger');
    Route::get('/accounts/{account}/ledger/print', [AccountController::class, 'ledgerPrint'])->name('accounts.ledger.print');
    Route::get('/accounts/{account}/ledger/export', [AccountController::class, 'ledgerExport'])->name('accounts.ledger.export');
});
