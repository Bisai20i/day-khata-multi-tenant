<?php

declare(strict_types=1);

use App\Http\Controllers\Tenant\Hr\HrReportController;
use App\Http\Controllers\Tenant\Hr\PayComponentController;
use App\Http\Controllers\Tenant\Hr\PayrollPaymentController;
use App\Http\Controllers\Tenant\Hr\PayrollRunController;
use App\Http\Controllers\Tenant\Hr\StaffAdvanceController;
use App\Http\Controllers\Tenant\Hr\StaffController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenant: HR (staff payroll)
|--------------------------------------------------------------------------
|
| Required from routes/tenant.php inside its auth:web group. Every key belongs
| to the `hr` module, so none of this is reachable for a tenant that has not
| been entitled to it (plans/11-hr-staff-payroll.md). Everything is prefixed
| /hr so future HR features (attendance, leave, ...) add sibling groups here
| without touching these.
|
*/

Route::name('tenant.hr.')->prefix('hr')->group(function () {
    Route::prefix('staff')->name('staff.')->group(function () {
        Route::get('/', [StaffController::class, 'index'])->middleware('can:hr_staff.view')->name('index');
        Route::get('/create', [StaffController::class, 'create'])->middleware('can:hr_staff.create')->name('create');
        Route::post('/', [StaffController::class, 'store'])->middleware('can:hr_staff.create')->name('store');
        Route::get('/{staff}/edit', [StaffController::class, 'edit'])->middleware('can:hr_staff.view')->whereNumber('staff')->name('edit');
        Route::put('/{staff}', [StaffController::class, 'update'])->middleware('can:hr_staff.edit')->whereNumber('staff')->name('update');
        Route::get('/{staff}/statement', [HrReportController::class, 'statement'])->middleware('can:hr_reports.view')->whereNumber('staff')->name('statement');
    });

    Route::prefix('pay-components')->name('pay-components.')->group(function () {
        Route::get('/', [PayComponentController::class, 'index'])->middleware('can:pay_components.view')->name('index');
        Route::post('/', [PayComponentController::class, 'store'])->middleware('can:pay_components.manage')->name('store');
        Route::put('/{component}', [PayComponentController::class, 'update'])->middleware('can:pay_components.manage')->whereNumber('component')->name('update');
    });

    Route::prefix('payroll')->name('payroll.')->group(function () {
        Route::get('/', [PayrollRunController::class, 'index'])->middleware('can:payroll.view')->name('index');
        Route::get('/create', [PayrollRunController::class, 'create'])->middleware('can:payroll.create')->name('create');
        Route::post('/', [PayrollRunController::class, 'store'])->middleware('can:payroll.create')->name('store');
        Route::get('/{run}', [PayrollRunController::class, 'show'])->middleware('can:payroll.view')->whereNumber('run')->name('show');
        Route::put('/{run}', [PayrollRunController::class, 'update'])->middleware('can:payroll.create')->whereNumber('run')->name('update');
        Route::delete('/{run}', [PayrollRunController::class, 'destroy'])->middleware('can:payroll.create')->whereNumber('run')->name('destroy');
        Route::post('/{run}/post', [PayrollRunController::class, 'post'])->middleware('can:payroll.manage')->whereNumber('run')->name('post');
        Route::post('/{run}/cancel', [PayrollRunController::class, 'cancel'])->middleware('can:payroll.cancel')->whereNumber('run')->name('cancel');
    });

    Route::prefix('salary-payments')->name('salary-payments.')->group(function () {
        Route::get('/', [PayrollPaymentController::class, 'index'])->middleware('can:payroll_payments.view')->name('index');
        Route::post('/', [PayrollPaymentController::class, 'store'])->middleware('can:payroll_payments.create')->name('store');
        Route::post('/{payment}/cancel', [PayrollPaymentController::class, 'cancel'])->middleware('can:payroll_payments.cancel')->whereNumber('payment')->name('cancel');
    });

    Route::prefix('staff-advances')->name('staff-advances.')->group(function () {
        Route::get('/', [StaffAdvanceController::class, 'index'])->middleware('can:staff_advances.view')->name('index');
        Route::post('/', [StaffAdvanceController::class, 'store'])->middleware('can:staff_advances.create')->name('store');
        Route::post('/{advance}/cancel', [StaffAdvanceController::class, 'cancel'])->middleware('can:staff_advances.cancel')->whereNumber('advance')->name('cancel');
    });

    Route::prefix('reports')->name('reports.')->group(function () {
        Route::get('/payroll-register', [HrReportController::class, 'payrollRegister'])->middleware('can:hr_reports.view')->name('payroll-register');
        Route::get('/payroll-register/export', [HrReportController::class, 'payrollRegisterExport'])->middleware('can:hr_reports.export')->name('payroll-register.export');
        Route::get('/staff-balances', [HrReportController::class, 'staffBalances'])->middleware('can:hr_reports.view')->name('staff-balances');
        Route::get('/staff-balances/export', [HrReportController::class, 'staffBalancesExport'])->middleware('can:hr_reports.export')->name('staff-balances.export');
    });
});
