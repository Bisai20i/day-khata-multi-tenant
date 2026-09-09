<?php

use App\Http\Controllers\Central\Tenants\TenantController;
use App\Http\Controllers\Central\Tenants\TenantDomainController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Central: Tenant Management
|--------------------------------------------------------------------------
|
| CRUD for App\Models\Tenant (create/list/suspend/resume/delete) plus
| provisioning, driven from the central database. All routes here must be
| protected by the "platform" guard (auth:platform). This file is owned by
| the tenant-management work: do not add platform-admin auth routes here,
| they belong in central-auth.php.
|
*/

Route::middleware('auth:platform')->prefix('tenants')->name('central.tenants.')->group(function () {
    Route::get('/', [TenantController::class, 'index'])->name('index');
    Route::get('/create', [TenantController::class, 'create'])->name('create');
    Route::post('/', [TenantController::class, 'store'])->name('store');
    Route::get('/{tenant}', [TenantController::class, 'show'])->name('show');
    Route::get('/{tenant}/edit', [TenantController::class, 'edit'])->name('edit');
    Route::put('/{tenant}', [TenantController::class, 'update'])->name('update');
    Route::post('/{tenant}/suspend', [TenantController::class, 'suspend'])->name('suspend');
    Route::post('/{tenant}/resume', [TenantController::class, 'resume'])->name('resume');
    Route::post('/{tenant}/impersonate', [TenantController::class, 'impersonate'])->name('impersonate');
    Route::post('/{tenant}/retry-provisioning', [TenantController::class, 'retryProvisioning'])->name('retry-provisioning');

    Route::post('/{tenant}/domains', [TenantDomainController::class, 'store'])->name('domains.store');
    Route::delete('/{tenant}/domains/{domain}', [TenantDomainController::class, 'destroy'])->name('domains.destroy');

    // Owner-only: a stuck-provisioning tenant being forced Active without its
    // database/admin user actually existing is a real footgun, not routine
    // day-to-day support work.
    Route::post('/{tenant}/force-active', [TenantController::class, 'forceActive'])
        ->middleware('can:platform-owner')
        ->name('force-active');

    // Owner-only per explicit sign-off - unlike company_name/contact_email
    // (plain update(), any admin), trial-expiry management is deliberately
    // its own gated action.
    Route::put('/{tenant}/trial', [TenantController::class, 'updateTrial'])
        ->middleware('can:platform-owner')
        ->name('update-trial');

    // Owner-only: deleting a tenant is a real DROP DATABASE, not a "support"-level action.
    Route::delete('/{tenant}', [TenantController::class, 'destroy'])
        ->middleware('can:platform-owner')
        ->name('destroy');
});
