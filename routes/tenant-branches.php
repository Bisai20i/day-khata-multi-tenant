<?php

declare(strict_types=1);

use App\Http\Controllers\Tenant\Admin\BranchController;
use App\Http\Controllers\Tenant\Reports\BranchComparisonReportController;
use App\Http\Controllers\Tenant\Reports\InterBranchBalancesReportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenant Branches Admin Routes
|--------------------------------------------------------------------------
|
| Required from routes/tenant.php inside its auth:web group. Reading needs
| branches.view; every write needs branches.manage, and BranchController
| additionally refuses a user who is limited to some branches (administration
| is company-wide). Branches are never deleted, only deactivated.
*/

Route::name('tenant.')->group(function () {
    Route::middleware('can:branches.view')->group(function () {
        Route::get('/branches', [BranchController::class, 'index'])->name('branches.index');
    });

    // Branch comparison (plan 12, BR16): branches.report, the same key the
    // inter-branch balances report will use.
    Route::middleware('can:branches.report')->group(function () {
        Route::get('/reports/branch-comparison', [BranchComparisonReportController::class, 'index'])->name('reports.branch-comparison');
        Route::get('/reports/branch-comparison/export', [BranchComparisonReportController::class, 'export'])->name('reports.branch-comparison.export');
        Route::get('/reports/inter-branch-balances', [InterBranchBalancesReportController::class, 'index'])->name('reports.inter-branch-balances');
    });

    Route::middleware('can:branches.manage')->group(function () {
        Route::post('/branches', [BranchController::class, 'store'])->name('branches.store');
        Route::put('/branches/{branch}', [BranchController::class, 'update'])->name('branches.update');
        Route::post('/branches/{branch}/deactivate', [BranchController::class, 'deactivate'])->name('branches.deactivate');
        Route::post('/branches/{branch}/reactivate', [BranchController::class, 'reactivate'])->name('branches.reactivate');
        Route::put('/branch-label', [BranchController::class, 'updateLabel'])->name('branches.label');
    });
});
