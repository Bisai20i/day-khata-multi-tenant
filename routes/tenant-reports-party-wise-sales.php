<?php

declare(strict_types=1);

use App\Http\Controllers\Tenant\Reports\PartyWiseSalesReportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenant: Customer-wise Sales
|--------------------------------------------------------------------------
|
| Required from routes/tenant.php inside its auth:web group. The party
| drill-down is the index route with a customer_id query.
|
*/

Route::name('tenant.reports.')->prefix('reports')->group(function () {
    Route::get('/party-wise-sales', [PartyWiseSalesReportController::class, 'index'])->middleware('can:sales_reports.view')->name('party-wise-sales');
    Route::get('/party-wise-sales/export', [PartyWiseSalesReportController::class, 'export'])->middleware(['can:sales_reports.view', 'can:sales_reports.export'])->name('party-wise-sales.export');
});
