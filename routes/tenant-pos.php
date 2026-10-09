<?php

declare(strict_types=1);

use App\Http\Controllers\Tenant\Sales\PosController;
use App\Http\Controllers\Tenant\Sales\SaleController;
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
| separate endpoint. So pos.view only opens the till; posting the sale
| needs sales.create on that route.
|
| POST /pos/estimate renders the cart as the bill it would become without
| posting anything (SaleController::estimate()), so it stays on pos.view:
| it shows a cashier nothing the till does not already show.
|
*/

Route::name('tenant.')->group(function () {
    Route::get('/pos', [PosController::class, 'index'])->middleware('can:pos.view')->name('pos.index');
    Route::post('/pos/estimate', [SaleController::class, 'estimate'])->middleware('can:pos.view')->name('pos.estimate');
});
