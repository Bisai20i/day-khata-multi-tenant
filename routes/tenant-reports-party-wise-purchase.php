<?php

declare(strict_types=1);

use App\Http\Controllers\Tenant\Reports\PartyWisePurchaseReportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenant: Supplier-wise Purchase
|--------------------------------------------------------------------------
|
| Required from routes/tenant.php inside its auth:web group. The party
| drill-down is the index route with a supplier_id query.
|
*/

Route::name('tenant.reports.')->prefix('reports')->group(function () {
    Route::get('/party-wise-purchase', [PartyWisePurchaseReportController::class, 'index'])->middleware('can:purchase_reports.view')->name('party-wise-purchase');
    Route::get('/party-wise-purchase/export', [PartyWisePurchaseReportController::class, 'export'])->middleware(['can:purchase_reports.view', 'can:purchase_reports.export'])->name('party-wise-purchase.export');
});
