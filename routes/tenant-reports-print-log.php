<?php

declare(strict_types=1);

use App\Http\Controllers\Tenant\Reports\PrintLogReportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenant: Print Log Report
|--------------------------------------------------------------------------
|
| Required from routes/tenant.php inside its auth:web group, next to the
| other `routes/tenant-reports-*.php` files (see mem.md gotcha #5 on the
| parallel-work split-file convention). Backs contract C9's print log: who
| reprinted which document, when, and as which copy.
|
| Admin-only: this is an audit trail over other users' reprints, not a
| working report, so it sits behind the same `role:admin` gate as the
| activity log.
|
| NOTE for whoever wires this in: this file itself is not yet required by
| routes/tenant.php - add:
|     require base_path('routes/tenant-reports-print-log.php');
| alongside the other tenant-reports-*.php requires.
|
*/

Route::name('tenant.reports.')->prefix('reports')->middleware('role:admin')->group(function () {
    Route::get('/print-log', [PrintLogReportController::class, 'index'])->name('print-log');
});
