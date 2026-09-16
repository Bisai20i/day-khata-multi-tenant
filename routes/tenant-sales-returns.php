<?php

declare(strict_types=1);

use App\Http\Controllers\Tenant\Sales\SalesReturnController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenant: Sales Returns
|--------------------------------------------------------------------------
|
| Required from routes/tenant.php inside its auth:web group. Split into
| its own file per the parallel-work convention (see mem.md gotcha #5) -
| owned entirely by the Sales Return build pass, do not add purchase
| return routes here.
|
*/

Route::name('tenant.')->group(function () {
    Route::prefix('sales-returns')->name('sales-returns.')->group(function () {
        Route::get('/', [SalesReturnController::class, 'index'])->name('index');
        Route::post('/', [SalesReturnController::class, 'store'])->name('store');
        // A return with no bill this system ever issued to point at (audit
        // section 3 "Sales", "returns without a bill") - see SalesReturn::
        // postUnlinked(). Always posts directly, so it has no request/
        // approve counterpart.
        Route::post('/unlinked', [SalesReturnController::class, 'storeUnlinked'])->name('store-unlinked');
        // Request/approve/reject: the two-step "request first, post only on
        // approval" workflow (see SalesReturn::request()'s docblock) -
        // request() never posts anything, approve() posts exactly what a
        // direct store() would have, reject() records a reason and posts
        // nothing.
        Route::post('/request', [SalesReturnController::class, 'requestReturn'])->name('request');
        Route::post('/{salesReturn}/approve', [SalesReturnController::class, 'approve'])->name('approve');
        Route::post('/{salesReturn}/reject', [SalesReturnController::class, 'reject'])->name('reject');
        // Cancelling a posted credit note reverses real money, so it is
        // admin-only (CONTRACTS C5) - unlike request/approve/reject, which
        // every tenant user may drive.
        Route::post('/{salesReturn}/cancel', [SalesReturnController::class, 'cancel'])
            ->middleware('role:admin')
            ->name('cancel');
        Route::get('/{salesReturn}/print', [SalesReturnController::class, 'print'])->name('print');
    });
});
