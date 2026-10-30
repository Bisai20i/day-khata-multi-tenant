<?php

declare(strict_types=1);

use App\Http\Controllers\Tenant\Manufacturing\MaterialIssueNoteController;
use App\Http\Controllers\Tenant\Manufacturing\ProductionEntryController;
use App\Http\Controllers\Tenant\Reports\ManufacturingReportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenant: Manufacturing
|--------------------------------------------------------------------------
|
| Required from routes/tenant.php inside its auth:web group. Material issue
| notes move raw material into WIP, production entries accumulate WIP cost
| and complete it into finished goods, and the manufacturing reports read
| the three perpetual ledgers (todo/manufacturing/CONTRACTS.md MC5-MC10).
| Every key belongs to the `manufacturing` module, so none of this is
| reachable for a tenant that has not been entitled to it.
|
*/

Route::name('tenant.')->group(function () {
    Route::prefix('material-issues')->name('material-issues.')->group(function () {
        Route::get('/', [MaterialIssueNoteController::class, 'index'])->middleware('can:material_issues.view')->name('index');
        Route::get('/create', [MaterialIssueNoteController::class, 'create'])->middleware('can:material_issues.create')->name('create');
        Route::post('/', [MaterialIssueNoteController::class, 'store'])->middleware('can:material_issues.create')->name('store');
        Route::get('/{material_issue}', [MaterialIssueNoteController::class, 'show'])->middleware('can:material_issues.view')->name('show');
        Route::post('/{material_issue}/cancel', [MaterialIssueNoteController::class, 'cancel'])->middleware('can:material_issues.cancel')->name('cancel');
        Route::get('/{material_issue}/print', [MaterialIssueNoteController::class, 'print'])->middleware('can:material_issues.print')->name('print');
    });

    Route::prefix('production-entries')->name('production-entries.')->group(function () {
        Route::get('/', [ProductionEntryController::class, 'index'])->middleware('can:production_entries.view')->name('index');
        Route::get('/create', [ProductionEntryController::class, 'create'])->middleware('can:production_entries.create')->name('create');
        Route::post('/', [ProductionEntryController::class, 'store'])->middleware('can:production_entries.create')->name('store');
        Route::get('/{production_entry}', [ProductionEntryController::class, 'show'])->middleware('can:production_entries.view')->name('show');
        Route::post('/{production_entry}/attach-notes', [ProductionEntryController::class, 'attachNotes'])->middleware('can:production_entries.edit')->name('attach-notes');
        Route::post('/{production_entry}/direct-costs', [ProductionEntryController::class, 'addDirectCost'])->middleware('can:production_entries.edit')->name('direct-costs.store');
        Route::post('/{production_entry}/direct-costs/{direct_cost}/cancel', [ProductionEntryController::class, 'cancelDirectCost'])->middleware('can:production_entries.edit')->name('direct-costs.cancel');
        Route::post('/{production_entry}/return-material', [ProductionEntryController::class, 'returnMaterial'])->middleware('can:production_entries.edit')->name('return-material');
        Route::post('/{production_entry}/complete', [ProductionEntryController::class, 'complete'])->middleware('can:production_entries.edit')->name('complete');
        Route::post('/{production_entry}/completions/{completion}/cancel', [ProductionEntryController::class, 'cancelCompletion'])->middleware('can:production_entries.cancel')->name('completions.cancel');
        Route::post('/{production_entry}/returns/{return_voucher}/cancel', [ProductionEntryController::class, 'cancelReturn'])->middleware('can:production_entries.edit')->name('returns.cancel');
        Route::post('/{production_entry}/abandon', [ProductionEntryController::class, 'abandon'])->middleware('can:production_entries.cancel')->name('abandon');
        Route::post('/{production_entry}/cancel', [ProductionEntryController::class, 'cancel'])->middleware('can:production_entries.cancel')->name('cancel');
        Route::get('/{production_entry}/print', [ProductionEntryController::class, 'print'])->middleware('can:production_entries.print')->name('print');
    });

    Route::prefix('reports/manufacturing')->name('reports.manufacturing.')->group(function () {
        Route::get('/stock-ledger', [ManufacturingReportController::class, 'stockLedger'])->middleware('can:manufacturing_reports.view')->name('stock-ledger');
        Route::get('/inventory-schedule', [ManufacturingReportController::class, 'inventorySchedule'])->middleware('can:manufacturing_reports.view')->name('inventory-schedule');
        Route::get('/cogs-schedule', [ManufacturingReportController::class, 'cogsSchedule'])->middleware('can:manufacturing_reports.view')->name('cogs-schedule');
        Route::get('/reconciliation', [ManufacturingReportController::class, 'reconciliation'])->middleware('can:manufacturing_reports.view')->name('reconciliation');
        Route::get('/{report}/print', [ManufacturingReportController::class, 'print'])
            ->middleware('can:manufacturing_reports.export')
            ->where('report', 'stock-ledger|inventory-schedule|cogs-schedule|reconciliation')
            ->name('print');
        Route::get('/{report}/export', [ManufacturingReportController::class, 'export'])
            ->middleware('can:manufacturing_reports.export')
            ->where('report', 'stock-ledger|inventory-schedule|cogs-schedule|reconciliation')
            ->name('export');
    });
});
