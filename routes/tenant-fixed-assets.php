<?php

declare(strict_types=1);

use App\Http\Controllers\Tenant\Assets\FixedAssetController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenant: Fixed Assets
|--------------------------------------------------------------------------
|
| Required from routes/tenant.php inside its auth:web group. Owned entirely
| by the Fixed Assets build pass, per the parallel-work file-ownership
| convention (see mem.md gotcha #5).
|
*/

// Admin-only throughout (audit P1, missing role gates). Buying, disposing of
// and depreciating a fixed asset each post straight into the ledger and move
// the balance sheet; the manual depreciation run is the same posting
// FiscalYear::close() performs automatically, which legacy also gated behind
// superadmin.
Route::name('tenant.')->middleware('role:admin')->group(function () {
    Route::prefix('fixed-assets')->name('fixed-assets.')->group(function () {
        Route::get('/', [FixedAssetController::class, 'index'])->name('index');
        Route::post('/', [FixedAssetController::class, 'store'])->name('store');
        // Registers an asset the business already owned before this system
        // went live, with no cash/bank movement (T14) - see
        // FixedAsset::registerExisting().
        Route::post('/existing', [FixedAssetController::class, 'storeExisting'])->name('store-existing');
        Route::post('/{fixedAsset}/dispose', [FixedAssetController::class, 'dispose'])->name('dispose');
        Route::post('/post-depreciation', [FixedAssetController::class, 'postDepreciation'])->name('post-depreciation');
    });
});
