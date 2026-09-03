<?php

use App\Http\Controllers\Central\ActivityLogController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Central: Platform-Admin Activity Log
|--------------------------------------------------------------------------
|
| Read-only listing of App\Models\PlatformAdminActivityLog rows (tenant
| create/update/suspend/resume/delete/impersonate, settings changes,
| platform-admin management). All routes here must be protected by the
| "platform" guard (auth:platform). This file is owned by the
| activity-log-UI work: do not add tenant-management or settings routes
| here.
|
*/

Route::middleware('auth:platform')->prefix('activity-log')->name('central.activity-log.')->group(function () {
    Route::get('/', [ActivityLogController::class, 'index'])->name('index');
});
