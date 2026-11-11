<?php

declare(strict_types=1);

use App\Http\Controllers\Tenant\Parties\AreaController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenant: Areas (customer / supplier areas)
|--------------------------------------------------------------------------
|
| Required from routes/tenant.php inside its auth:web group. Plain CRUD in the
| shape of routes/tenant-stores.php. The store route also serves the
| "+ New area" quick-add (`quick_add`) used on the customer / supplier forms
| and the report filters. Assigning areas in bulk lives with the parties, in
| routes/tenant-business.php (customers.assign-area, suppliers.assign-area).
|
*/

Route::name('tenant.')->group(function () {
    Route::prefix('areas')->name('areas.')->group(function () {
        Route::get('/', [AreaController::class, 'index'])->middleware('can:areas.view')->name('index');
        Route::post('/', [AreaController::class, 'store'])->middleware('can:areas.manage')->name('store');
        Route::put('/{area}', [AreaController::class, 'update'])->middleware('can:areas.manage')->name('update');
        Route::delete('/{area}', [AreaController::class, 'destroy'])->middleware('can:areas.manage')->name('destroy');
    });
});
