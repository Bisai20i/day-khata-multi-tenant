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

Route::name('tenant.')->group(function () {
    Route::prefix('fixed-assets')->name('fixed-assets.')->group(function () {
        Route::get('/', [FixedAssetController::class, 'index'])->name('index');
        Route::post('/', [FixedAssetController::class, 'store'])->name('store');
        Route::post('/{fixedAsset}/dispose', [FixedAssetController::class, 'dispose'])->name('dispose');

        // Manual trigger for the same posting FiscalYear::close() runs
        // automatically - admin-only, mirroring legacy's superadmin-gated
        // "Post Depreciation" action.
        Route::post('/post-depreciation', [FixedAssetController::class, 'postDepreciation'])
            ->middleware('role:admin')
            ->name('post-depreciation');
    });
});
