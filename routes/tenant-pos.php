<?php

declare(strict_types=1);

use App\Http\Controllers\Tenant\Sales\PosController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenant: POS / Walk-in Quick Sale
|--------------------------------------------------------------------------
|
| Required from routes/tenant.php inside its auth:web group. Owned entirely
| by the Phase 1 POS build pass, per the parallel-work file-ownership
| convention (see mem.md gotcha #5). Frontend-only feature - actual sale
| submission goes through the existing POST /sales route
| (App\Http\Controllers\Tenant\Sales\SaleController::store()), not a
| separate endpoint.
|
*/

Route::name('tenant.')->group(function () {
    Route::get('/pos', [PosController::class, 'index'])->name('pos.index');
});
