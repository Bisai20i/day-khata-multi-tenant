<?php

declare(strict_types=1);

use App\Http\Controllers\Tenant\Admin\RoleController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenant Role Management Routes
|--------------------------------------------------------------------------
|
| Owner-only role and permission editing (P11). roles.manage is an owner-only
| key in the always-on core module, so the owner can manage roles even with
| the admin module switched off, and no role grant ever opens these routes.
*/

Route::middleware('can:roles.manage')->group(function () {
    Route::get('/admin/roles', [RoleController::class, 'index'])->name('tenant.admin.roles.index');
    Route::get('/admin/roles/create', [RoleController::class, 'create'])->name('tenant.admin.roles.create');
    Route::post('/admin/roles', [RoleController::class, 'store'])->name('tenant.admin.roles.store');
    Route::get('/admin/roles/{role}/edit', [RoleController::class, 'edit'])->name('tenant.admin.roles.edit');
    Route::put('/admin/roles/{role}', [RoleController::class, 'update'])->name('tenant.admin.roles.update');
    Route::post('/admin/roles/{role}/duplicate', [RoleController::class, 'duplicate'])->name('tenant.admin.roles.duplicate');
    Route::delete('/admin/roles/{role}', [RoleController::class, 'destroy'])->name('tenant.admin.roles.destroy');
});
