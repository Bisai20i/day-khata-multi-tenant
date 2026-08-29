<?php

declare(strict_types=1);

use App\Http\Controllers\Tenant\Reports\VatSummaryReportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenant: VAT Summary Report
|--------------------------------------------------------------------------
|
| Required from routes/tenant.php inside its auth:web group. Split into its
| own file per the parallel-work convention (see mem.md gotcha #5) - owned
| entirely by the VAT Summary build pass.
|
*/

Route::name('tenant.reports.')->prefix('reports')->group(function () {
    Route::get('/vat-summary', [VatSummaryReportController::class, 'index'])->name('vat-summary');
});
