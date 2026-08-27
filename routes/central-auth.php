<?php

use App\Http\Controllers\Central\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Central\Auth\TwoFactorAuthenticationController;
use App\Http\Controllers\Central\Auth\TwoFactorChallengeController;
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

    // Post-password, pre-session step for a 2FA-enabled admin. Still a
    // "guest" as far as the platform guard is concerned, since login()
    // hasn't been called yet.
    Route::get('/two-factor-challenge', [TwoFactorChallengeController::class, 'create'])
        ->name('central.two-factor.challenge');

    Route::post('/two-factor-challenge', [TwoFactorChallengeController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('central.two-factor.challenge.store');
});

Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth:platform')
    ->name('logout');

Route::get('/admin', function () {
    return Inertia::render('Central/Dashboard');
})->middleware('auth:platform')->name('central.dashboard');

Route::middleware('auth:platform')->prefix('two-factor')->name('central.two-factor.')->group(function () {
    Route::get('/', [TwoFactorAuthenticationController::class, 'show'])->name('show');
    Route::post('/', [TwoFactorAuthenticationController::class, 'generate'])->name('generate');
    Route::post('/confirm', [TwoFactorAuthenticationController::class, 'confirm'])->name('confirm');
    Route::delete('/', [TwoFactorAuthenticationController::class, 'destroy'])->name('destroy');
});
