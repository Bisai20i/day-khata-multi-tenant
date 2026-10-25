<?php

declare(strict_types=1);

use App\Http\Controllers\Tenant\Admin\CompanySettingController;
use Illuminate\Support\Facades\Route;

Route::prefix('settings')->name('tenant.settings.')->group(function () {
    Route::get('/', [CompanySettingController::class, 'edit'])->middleware('can:settings.manage')->name('edit');
    Route::put('/', [CompanySettingController::class, 'update'])->middleware('can:settings.manage')->name('update');
    Route::post('/logo', [CompanySettingController::class, 'uploadLogo'])->middleware('can:settings.manage')->name('logo.upload');
    Route::delete('/logo', [CompanySettingController::class, 'deleteLogo'])->middleware('can:settings.manage')->name('logo.delete');
    Route::post('/starting-number', [CompanySettingController::class, 'setStartingNumber'])->middleware('can:settings.manage')->name('starting-number.set');
});
