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
        Route::get('/', [FiscalYearController::class, 'index'])->name('index');
        Route::post('/', [FiscalYearController::class, 'store'])->name('store');
        Route::post('/{fiscalYear}/close', [FiscalYearController::class, 'close'])
            ->middleware('role:admin')
            ->name('close');
        Route::post('/{fiscalYear}/reopen', [FiscalYearController::class, 'reopen'])
            ->middleware('role:admin')
            ->name('reopen');
        Route::post('/{fiscalYear}/lock', [FiscalYearController::class, 'relock'])
            ->middleware('role:admin')
            ->name('lock');
    });

    // Posting and cancelling a manual journal voucher is admin-only: it is the
    // one screen that can move money between any two accounts with no source
    // document behind it, and its cancel path reverses the ledger. Viewing the
    // list stays open to staff so they can look a posting up (audit P1, "No
    // role:admin on journal vouchers ... or cancel paths").
    Route::prefix('journal-vouchers')->name('journal-vouchers.')->group(function () {
        Route::get('/', [JournalVoucherController::class, 'index'])->name('index');
        Route::post('/', [JournalVoucherController::class, 'store'])->middleware('role:admin')->name('store');
        // Cash/Bank vouchers (T14, CONTRACTS/accounting parity): Cash
        // Receipt, Cash Payment, Bank Receipt, Bank Payment, Contra. Posted
        // through the same controller/model as a manual Journal voucher
        // since neither has a separate owning record - see
        // JournalVoucher::postCashBank().
        Route::post('/cash-bank', [JournalVoucherController::class, 'storeCashBank'])->middleware('role:admin')->name('cash-bank.store');
        Route::post('/{journalVoucher}/cancel', [JournalVoucherController::class, 'cancel'])->middleware('role:admin')->name('cancel');
        Route::get('/{journalVoucher}/print', [JournalVoucherController::class, 'print'])->name('print');
    });

    Route::get('/accounts/{account}/ledger', [AccountController::class, 'ledger'])->name('accounts.ledger');
    Route::get('/accounts/{account}/ledger/print', [AccountController::class, 'ledgerPrint'])->name('accounts.ledger.print');
    Route::get('/accounts/{account}/ledger/export', [AccountController::class, 'ledgerExport'])->name('accounts.ledger.export');
});
