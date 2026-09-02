<?php

declare(strict_types=1);

use App\Http\Controllers\Tenant\Admin\NoticeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenant: Dashboard Notices
|--------------------------------------------------------------------------
|
| Required from routes/tenant.php inside its auth:web group. Owned entirely
| by the Phase 1 Dashboard Notices build pass, per the parallel-work
| file-ownership convention (see mem.md gotcha #5). CRUD is admin-only;
| every authenticated user sees active notices on the dashboard itself
| (see DashboardController), not through this route group.
|
*/

Route::name('tenant.')->middleware('role:admin')->group(function () {
    Route::prefix('notices')->name('notices.')->group(function () {
        Route::get('/', [NoticeController::class, 'index'])->name('index');
        Route::post('/', [NoticeController::class, 'store'])->name('store');
        Route::put('/{notice}', [NoticeController::class, 'update'])->name('update');
        Route::delete('/{notice}', [NoticeController::class, 'destroy'])->name('destroy');
    });
});
