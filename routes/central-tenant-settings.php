<?php

declare(strict_types=1);

use App\Http\Controllers\Central\Tenants\TenantCompanySettingController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Central: Tenant Company Settings
|--------------------------------------------------------------------------
|
| Lets a platform admin view/edit a tenant's own CompanySetting (company
| info, invoice numbering, logo, stock/discount policy) from the central
| panel - tenants no longer manage this themselves (see the removed
| routes/tenant-settings.php). All routes here must be protected by the
| "platform" guard (auth:platform). This file is owned by the
| tenant-settings-view work: do not add other tenant-management routes
| here, they belong in central-tenants.php.
|
*/

Route::middleware('auth:platform')->prefix('tenants/{tenant}/settings')->name('central.tenants.settings.')->group(function () {
    Route::get('/', [TenantCompanySettingController::class, 'edit'])->name('edit');
    Route::put('/', [TenantCompanySettingController::class, 'update'])->name('update');
    Route::post('/logo', [TenantCompanySettingController::class, 'uploadLogo'])->name('logo');
    Route::post('/starting-number', [TenantCompanySettingController::class, 'setStartingNumber'])->name('starting-number');
});
