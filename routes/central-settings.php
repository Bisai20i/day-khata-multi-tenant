<?php

use App\Http\Controllers\Central\Settings\PlatformSettingController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Central: Platform Settings
|--------------------------------------------------------------------------
|
| Single-page mail/branding/trial/grace-period configuration for
| App\Models\PlatformSetting. All routes here must be protected by the
| "platform" guard (auth:platform). This file is owned by the settings
| work: do not add tenant-management or auth routes here.
|
*/

Route::middleware('auth:platform')->prefix('settings')->name('central.settings.')->group(function () {
    Route::get('/', [PlatformSettingController::class, 'edit'])->name('edit');

    // Owner-only: viewing settings is fine for "support", changing them isn't.
    Route::middleware('can:platform-owner')->group(function () {
        Route::put('/', [PlatformSettingController::class, 'update'])->name('update');
        Route::post('/test-email', [PlatformSettingController::class, 'sendTestEmail'])->name('test-email');
    });
});
