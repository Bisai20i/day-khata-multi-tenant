<?php

declare(strict_types=1);

use App\Http\Controllers\Tenant\Inventory\StoreController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenant: Stores (multi-location)
|--------------------------------------------------------------------------
|
| Required from routes/tenant.php inside its auth:web group. Owned entirely
| by the Phase 0 multi-store build pass, per the parallel-work file-ownership
| convention (see mem.md gotcha #5). Plain CRUD, mirrors item-categories'
| shape in routes/tenant-business.php.
|
*/

Route::name('tenant.')->group(function () {
    Route::prefix('stores')->name('stores.')->group(function () {
        Route::get('/', [StoreController::class, 'index'])->name('index');
        Route::post('/', [StoreController::class, 'store'])->name('store');
        Route::put('/{store}', [StoreController::class, 'update'])->name('update');
        Route::delete('/{store}', [StoreController::class, 'destroy'])->name('destroy');
    });
});
