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
        Route::get('/', [SalesReturnController::class, 'index'])->middleware('can:sales_returns.view')->name('index');
        Route::post('/', [SalesReturnController::class, 'store'])->middleware('can:sales_returns.create')->name('store');
        // A return with no bill this system ever issued to point at (audit
        // section 3 "Sales", "returns without a bill") - see SalesReturn::
        // postUnlinked(). Always posts directly, so it has no request/
        // approve counterpart.
        Route::post('/unlinked', [SalesReturnController::class, 'storeUnlinked'])
            ->middleware('can:unlinked_sales_returns.create')
            ->name('store-unlinked');
        // Request/approve/reject: the two-step "request first, post only on
        // approval" workflow (see SalesReturn::request()'s docblock) -
        // request() never posts anything, approve() posts exactly what a
        // direct store() would have, reject() records a reason and posts
        // nothing.
        Route::post('/request', [SalesReturnController::class, 'requestReturn'])->middleware('can:sales_return_requests.create')->name('request');
        Route::post('/{salesReturn}/approve', [SalesReturnController::class, 'approve'])
            ->middleware('can:sales_return_requests.manage')
            ->name('approve');
        Route::post('/{salesReturn}/reject', [SalesReturnController::class, 'reject'])
            ->middleware('can:sales_return_requests.manage')
            ->name('reject');
        // Cancelling a posted credit note reverses real money, so it has its
        // own key (sales_returns.cancel, CONTRACTS C5), separate from
        // request (sales_return_requests.create) and approve/reject
        // (sales_return_requests.manage).
        Route::post('/{salesReturn}/cancel', [SalesReturnController::class, 'cancel'])
            ->middleware('can:sales_returns.cancel')
            ->name('cancel');
        Route::get('/{salesReturn}/print', [SalesReturnController::class, 'print'])->middleware('can:sales_returns.print')->name('print');
    });
});
