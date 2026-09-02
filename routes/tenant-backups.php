<?php

declare(strict_types=1);

use App\Http\Controllers\Tenant\Admin\BackupController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenant: Backups
|--------------------------------------------------------------------------
|
| Required from routes/tenant.php inside its auth:web group. Owned entirely
| by the Phase 1 Backup build pass, per the parallel-work file-ownership
| convention (see mem.md gotcha #5). Every route here must require
| authentication - legacy's equivalent had a real bug where backups were
| reachable from the public webroot; do not repeat that.
|
*/

Route::name('tenant.')->middleware('role:admin')->group(function () {
    Route::prefix('backups')->name('backups.')->group(function () {
        Route::get('/', [BackupController::class, 'index'])->name('index');
        Route::post('/', [BackupController::class, 'store'])->name('store');
        Route::get('/{backup}/download', [BackupController::class, 'download'])->name('download');
        Route::delete('/{backup}', [BackupController::class, 'destroy'])->name('destroy');
    });
});
