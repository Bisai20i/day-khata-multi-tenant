<?php

declare(strict_types=1);

use App\Http\Controllers\Tenant\Purchases\PaymentController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenant: Payments
|--------------------------------------------------------------------------
|
| Required from routes/tenant.php inside its auth:web group. Split into
| its own file per the parallel-work convention (see mem.md gotcha #5) -
| owned entirely by the Payment build pass, do not add receipt routes
| here.
|
*/

Route::name('tenant.')->group(function () {
    Route::prefix('payments')->name('payments.')->group(function () {
        Route::get('/', [PaymentController::class, 'index'])->name('index');
        Route::post('/', [PaymentController::class, 'store'])->name('store');
        Route::post('/{payment}/cancel', [PaymentController::class, 'cancel'])->name('cancel');
    });
});
