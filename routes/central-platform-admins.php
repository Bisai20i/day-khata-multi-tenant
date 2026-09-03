<?php

use App\Http\Controllers\Central\PlatformAdmins\PlatformAdminController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Central: Platform Admin Management
|--------------------------------------------------------------------------
|
| CRUD-minus-delete for App\Models\PlatformAdmin (role/is_active only -
| deactivation, never a hard delete, see PlatformAdminController). index()
| is viewable by any platform admin; create/store/edit/update are
| owner-only (can:platform-owner). This file is owned by the platform-admin
| management work: do not add tenant-management or settings routes here.
|
*/

Route::middleware('auth:platform')->prefix('platform-admins')->name('central.platform-admins.')->group(function () {
    Route::get('/', [PlatformAdminController::class, 'index'])->name('index');

    Route::middleware('can:platform-owner')->group(function () {
        Route::get('/create', [PlatformAdminController::class, 'create'])->name('create');
        Route::post('/', [PlatformAdminController::class, 'store'])->name('store');
        Route::get('/{platformAdmin}/edit', [PlatformAdminController::class, 'edit'])->name('edit');
        Route::put('/{platformAdmin}', [PlatformAdminController::class, 'update'])->name('update');
    });
});
