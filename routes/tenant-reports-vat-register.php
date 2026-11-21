<?php

declare(strict_types=1);

use App\Http\Controllers\Tenant\Reports\UnifiedVatRegisterController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenant: Unified VAT Register
|--------------------------------------------------------------------------
|
| Required from routes/tenant.php inside its auth:web group. Split into its
| own file per the parallel-work convention - owned entirely by the Unified
| VAT Register build pass.
|
*/

Route::name('tenant.reports.')->prefix('reports')->group(function () {
    Route::get('/vat-register', [UnifiedVatRegisterController::class, 'index'])->middleware('can:tax_reports.view')->name('vat-register');
    Route::get('/vat-register/export', [UnifiedVatRegisterController::class, 'export'])->middleware(['can:tax_reports.view', 'can:tax_reports.export'])->name('vat-register.export');
});
