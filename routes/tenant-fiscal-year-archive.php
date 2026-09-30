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
| Required from routes/tenant.php inside its auth:web group. Gated per
| route: archiving uses the owner-only fiscal_year.close_archive key (same
| as close/reopen/relock), browsing uses fiscal_year_archive.view - a
| closed year's cold-storage ledger is a financial-history concern, same
| sensitivity class as backups.
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

Route::name('tenant.')->group(function () {
    Route::post('/fiscal-years/{fiscalYear}/archive', [FiscalYearArchiveController::class, 'store'])
        ->middleware('can:fiscal_year.close_archive')
        ->name('fiscal-years.archive');

    Route::prefix('fiscal-year-archives')->name('fiscal-year-archives.')->group(function () {
        Route::get('/{fiscalYearArchive}', [ArchivedFiscalYearController::class, 'show'])->middleware('can:fiscal_year_archive.view')->name('show');
        Route::get('/{fiscalYearArchive}/vouchers/{voucherId}', [ArchivedFiscalYearController::class, 'voucher'])
            ->whereNumber('voucherId')
            ->middleware('can:fiscal_year_archive.view')
            ->name('voucher');
    });
});
