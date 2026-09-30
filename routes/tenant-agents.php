<?php

declare(strict_types=1);

use App\Http\Controllers\Tenant\Sales\AgentController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenant: Sales Agents
|--------------------------------------------------------------------------
|
| Required from routes/tenant.php inside its auth:web group. Owned entirely
| by the Phase 1 Sales Agent Commission build pass, per the parallel-work
| file-ownership convention (see mem.md gotcha #5). Plain CRUD, mirrors
| customers/suppliers' shape in routes/tenant-business.php.
|
*/

Route::name('tenant.')->group(function () {
    Route::prefix('agents')->name('agents.')->group(function () {
        Route::get('/', [AgentController::class, 'index'])->middleware('can:agents.view')->name('index');
        Route::post('/', [AgentController::class, 'store'])->middleware('can:agents.create')->name('store');
        Route::put('/{agent}', [AgentController::class, 'update'])->middleware('can:agents.edit')->name('update');
        Route::delete('/{agent}', [AgentController::class, 'destroy'])->middleware('can:agents.delete')->name('destroy');
    });
});
