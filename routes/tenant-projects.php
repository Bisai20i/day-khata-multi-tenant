<?php

declare(strict_types=1);

use App\Http\Controllers\Tenant\Accounting\ProjectController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenant: Projects (cost-centre dimension on journal lines)
|--------------------------------------------------------------------------
|
| Required from routes/tenant.php inside its auth:web group. Plain CRUD in the
| shape of routes/tenant-areas.php: `projects.view` opens the page and
| `projects.manage` changes it. A project that already carries journal lines
| is never hard-deleted by ProjectController (it is completed instead), so
| the project columns on the statements stay meaningful.
|
*/

Route::name('tenant.')->group(function () {
    Route::prefix('projects')->name('projects.')->group(function () {
        Route::get('/', [ProjectController::class, 'index'])->middleware('can:projects.view')->name('index');
        Route::post('/', [ProjectController::class, 'store'])->middleware('can:projects.manage')->name('store');
        Route::put('/{project}', [ProjectController::class, 'update'])->middleware('can:projects.manage')->name('update');
        Route::delete('/{project}', [ProjectController::class, 'destroy'])->middleware('can:projects.manage')->name('destroy');
    });
});
