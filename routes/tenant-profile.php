<?php

declare(strict_types=1);

use App\Http\Controllers\Tenant\ProfileController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenant: Self-Service Profile / Change Password
|--------------------------------------------------------------------------
|
| Required from routes/tenant.php inside its auth:web group. Open to every
| authenticated tenant user - no role:admin gate, unlike
| routes/tenant-employees.php, since this is a user managing their own
| account rather than an admin managing other employees.
*/

Route::name('tenant.')->group(function () {
    Route::prefix('profile')->name('profile.')->group(function () {
        Route::get('/', [ProfileController::class, 'edit'])->name('edit');
        Route::put('/', [ProfileController::class, 'update'])->name('update');
        Route::put('/password', [ProfileController::class, 'updatePassword'])->name('password');
    });
});
