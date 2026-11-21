<?php

declare(strict_types=1);

use App\Http\Controllers\Tenant\Reports\BrandWiseReportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenant: Brand-wise Reports
|--------------------------------------------------------------------------
|
| Requires an explicit `require base_path('routes/tenant-reports-brand-wise.php');`
| line added to routes/tenant.php inside its auth:web group (not added here -
| see this build pass's own instructions). Deliberately its own file rather
| than appended to routes/tenant-reports-category-wise.php, whose docblock
| reserves that file entirely for the Category-wise Reports build pass.
|
*/

Route::name('tenant.reports.')->prefix('reports')->group(function () {
    Route::get('/stock-by-brand', [BrandWiseReportController::class, 'stockByBrand'])->middleware('can:stock_reports.view')->name('stock-by-brand');
    Route::get('/stock-by-brand/items', [BrandWiseReportController::class, 'stockByBrandItems'])->middleware('can:stock_reports.view')->name('stock-by-brand.items');
    Route::get('/stock-by-brand/export', [BrandWiseReportController::class, 'stockByBrandExport'])->middleware(['can:stock_reports.view', 'can:stock_reports.export'])->name('stock-by-brand.export');
});
