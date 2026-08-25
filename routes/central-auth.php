<?php

use App\Http\Controllers\Central\Auth\AuthenticatedSessionController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Central: Platform Admin Authentication
|--------------------------------------------------------------------------
|
| Login/logout for App\Models\PlatformAdmin via the "platform" guard
| (config/auth.php). This file is owned by the central-auth work: do not
| add tenant-management routes here, they belong in central-tenants.php.
|
*/

Route::middleware('guest:platform')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');

    Route::post('/login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('login.store');
});

Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth:platform')
    ->name('logout');

Route::get('/admin', function () {
    return Inertia::render('Central/Dashboard');
})->middleware('auth:platform')->name('central.dashboard');
