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
| created_by FK in this app is restrictOnDelete(). The escalation guard and
| owner protection live in UserController (see its docblock).
*/

Route::middleware('can:users.manage')->group(function () {
    Route::get('/admin/users', [UserController::class, 'index'])->name('tenant.admin.users');
    Route::post('/admin/users', [UserController::class, 'store'])->name('tenant.admin.users.store');
    Route::put('/admin/users/{user}', [UserController::class, 'update'])->name('tenant.admin.users.update');
});

// Owner-only (ownership.transfer can never be granted by a role). Throttled
// because the request carries the owner's password: a borrowed, signed-in
// browser must not be able to guess it at leisure.
Route::middleware(['can:ownership.transfer', 'throttle:6,1'])->group(function () {
    Route::post('/admin/users/{user}/transfer-ownership', [UserController::class, 'transferOwnership'])
        ->name('tenant.admin.users.transfer-ownership');
});
