<?php

declare(strict_types=1);

use App\Http\Controllers\Tenant\Admin\SettingsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenant: Settings / Invoice Setup
|--------------------------------------------------------------------------
|
| Required from routes/tenant.php inside its auth:web group. Owned entirely
| by the Phase 1 Settings build pass, per the parallel-work file-ownership
| convention (see mem.md gotcha #5). Singleton settings record - edit/update
| only, no index/create/destroy.
|
*/

Route::name('tenant.')->middleware('role:admin')->group(function () {
    Route::prefix('settings')->name('settings.')->group(function () {
        Route::get('/', [SettingsController::class, 'edit'])->name('edit');
        Route::put('/', [SettingsController::class, 'update'])->name('update');
    });
});
