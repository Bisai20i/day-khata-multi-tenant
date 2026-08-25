<?php

use App\Http\Controllers\Central\Tenants\TenantController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Central: Tenant Management
|--------------------------------------------------------------------------
|
| CRUD for App\Models\Tenant (create/list/suspend/resume/delete) plus
| provisioning, driven from the central database. All routes here must be
| protected by the "platform" guard (auth:platform). This file is owned by
| the tenant-management work: do not add platform-admin auth routes here,
| they belong in central-auth.php.
|
*/

Route::middleware('auth:platform')->prefix('tenants')->name('central.tenants.')->group(function () {
    Route::get('/', [TenantController::class, 'index'])->name('index');
    Route::get('/create', [TenantController::class, 'create'])->name('create');
    Route::post('/', [TenantController::class, 'store'])->name('store');
    Route::get('/{tenant}', [TenantController::class, 'show'])->name('show');
    Route::post('/{tenant}/suspend', [TenantController::class, 'suspend'])->name('suspend');
    Route::post('/{tenant}/resume', [TenantController::class, 'resume'])->name('resume');
    Route::delete('/{tenant}', [TenantController::class, 'destroy'])->name('destroy');
});
