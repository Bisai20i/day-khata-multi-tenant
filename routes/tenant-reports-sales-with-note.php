<?php

declare(strict_types=1);

use App\Http\Controllers\Tenant\Reports\SalesWithNoteReportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenant: Sales With Note Report
|--------------------------------------------------------------------------
|
| Required from routes/tenant.php inside its auth:web group, next to the
| other `routes/tenant-reports-*.php` files (see mem.md gotcha #5 on the
| parallel-work split-file convention). Multi-tenant equivalent of legacy
| day_khata's `/saleswithnote` + `/saleswithnoteReportDateBetween` routes.
|
| NOTE for whoever wires this in: this file itself is not yet required by
| routes/tenant.php - add:
|     require base_path('routes/tenant-reports-sales-with-note.php');
| alongside the other tenant-reports-*.php requires.
|
*/

Route::name('tenant.reports.')->prefix('reports')->group(function () {
    Route::get('/sales-with-note', [SalesWithNoteReportController::class, 'index'])->name('sales-with-note');
});
