<?php

declare(strict_types=1);

use App\Http\Controllers\Tenant\Admin\ActivityLogController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenant: Activity Log
|--------------------------------------------------------------------------
|
| Required from routes/tenant.php inside its auth:web group. Owned entirely
| by the Phase 1 Activity Log build pass, per the parallel-work
| file-ownership convention (see mem.md gotcha #5). Read-only audit trail.
|
*/

Route::name('tenant.')->middleware('role:admin')->group(function () {
    Route::get('/activity-log', [ActivityLogController::class, 'index'])->name('activity-log.index');
});
