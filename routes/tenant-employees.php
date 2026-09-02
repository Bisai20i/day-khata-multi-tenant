<?php

declare(strict_types=1);

use App\Http\Controllers\Tenant\Admin\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenant Employee/User Management Routes
|--------------------------------------------------------------------------
|
| Real CRUD (minus destroy - see UserController's docblock) resolving the
| phase-plan's "employee/user/privilege management has no owning phase"
| open item. Deactivation, not deletion, is the lifecycle action: every
| created_by FK in this app is restrictOnDelete().
*/

Route::middleware('role:admin')->group(function () {
    Route::get('/admin/users', [UserController::class, 'index'])->name('tenant.admin.users');
    Route::post('/admin/users', [UserController::class, 'store'])->name('tenant.admin.users.store');
    Route::put('/admin/users/{user}', [UserController::class, 'update'])->name('tenant.admin.users.update');
});
