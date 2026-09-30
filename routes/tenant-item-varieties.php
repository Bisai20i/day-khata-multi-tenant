<?php

declare(strict_types=1);

use App\Http\Controllers\Tenant\Inventory\ItemVarietyController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenant: Item Varieties
|--------------------------------------------------------------------------
|
| Required from routes/tenant.php inside its auth:web group. Owned entirely
| by the Phase 1 Item Varieties build pass, per the parallel-work
| file-ownership convention (see mem.md gotcha #5). Plain CRUD, mirrors
| item-categories' shape in routes/tenant-business.php.
|
*/

Route::name('tenant.')->group(function () {
    Route::prefix('item-varieties')->name('item-varieties.')->group(function () {
        Route::get('/', [ItemVarietyController::class, 'index'])->middleware('can:item_varieties.view')->name('index');
        Route::post('/', [ItemVarietyController::class, 'store'])->middleware('can:item_varieties.manage')->name('store');
        Route::put('/{itemVariety}', [ItemVarietyController::class, 'update'])->middleware('can:item_varieties.manage')->name('update');
        Route::delete('/{itemVariety}', [ItemVarietyController::class, 'destroy'])->middleware('can:item_varieties.manage')->name('destroy');
    });
});
