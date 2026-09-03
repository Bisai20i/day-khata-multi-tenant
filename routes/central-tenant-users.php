<?php

use App\Http\Controllers\Central\Tenants\TenantUserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Central: Tenant Users (read-only view)
|--------------------------------------------------------------------------
|
| Lets a platform admin see who exists inside a tenant's own `users` table
| without impersonating. All routes here must be protected by the
| "platform" guard (auth:platform). This file is owned by the
| tenant-users-view work: do not add other tenant-management routes here,
| they belong in central-tenants.php.
|
*/

Route::middleware('auth:platform')->prefix('tenants')->name('central.tenants.')->group(function () {
    Route::get('/{tenant}/users', [TenantUserController::class, 'index'])->name('users');
});
