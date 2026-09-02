<?php

declare(strict_types=1);

use App\Http\Controllers\Tenant\Accounting\ArchivedFiscalYearController;
use App\Http\Controllers\Tenant\Accounting\FiscalYearArchiveController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenant: Fiscal Year Archive
|--------------------------------------------------------------------------
|
| Required from routes/tenant.php inside its auth:web group. Admin-only
| throughout (mirrors routes/tenant-backups.php's gating) - archiving and
| browsing a closed year's cold-storage ledger is a financial-history
| concern, same sensitivity class as backups.
|
| FiscalYearArchiveController (POST .../archive) triggers
| App\Support\FiscalYear\FiscalYearArchiver::archive() - a synchronous copy
| of a closed fiscal year's ledger out to its own standalone SQLite file.
| ArchivedFiscalYearController (GET .../fiscal-year-archives/*) browses an
| already-archived year read-only via FiscalYearArchiver::connectionFor().
|
| No new top-level nav entry for this - entry points are surfaced from the
| existing Fiscal Years page (resources/js/pages/Tenant/Accounting/
| FiscalYears/Index.vue) instead, to avoid a nav-items.js conflict with the
| other Phase 1 modules building in the same wave.
|
*/

Route::name('tenant.')->middleware('role:admin')->group(function () {
    Route::post('/fiscal-years/{fiscalYear}/archive', [FiscalYearArchiveController::class, 'store'])
        ->name('fiscal-years.archive');

    Route::prefix('fiscal-year-archives')->name('fiscal-year-archives.')->group(function () {
        Route::get('/{fiscalYearArchive}', [ArchivedFiscalYearController::class, 'show'])->name('show');
        Route::get('/{fiscalYearArchive}/vouchers/{voucherId}', [ArchivedFiscalYearController::class, 'voucher'])
            ->whereNumber('voucherId')
            ->name('voucher');
    });
});
