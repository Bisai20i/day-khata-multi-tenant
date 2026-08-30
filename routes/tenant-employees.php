<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Tenant Employee/User Management Routes
|--------------------------------------------------------------------------
|
| Placeholder pending real CRUD (see mem.md/goal.md open item: employee
| creation + role assignment). Kept as a working named route in its own
| file so parallel feature work on other route files never breaks this one.
*/

Route::get('/admin/users', function (Request $request) {
    $request->user()->loadMissing('role');

    return Inertia::render('Tenant/Admin/Users');
})->middleware('role:admin')->name('tenant.admin.users');
