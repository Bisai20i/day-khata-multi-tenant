<?php

declare(strict_types=1);

use App\Http\Controllers\Tenant\Accounting\AccountController;
use App\Http\Controllers\Tenant\Accounting\AccountGroupController;
use App\Http\Controllers\Tenant\Accounting\AccountSubgroupController;
use App\Http\Controllers\Tenant\Inventory\ItemCategoryController;
use App\Http\Controllers\Tenant\Inventory\ItemController;
use App\Http\Controllers\Tenant\Inventory\ItemSubcategoryController;
use App\Http\Controllers\Tenant\Parties\CustomerController;
use App\Http\Controllers\Tenant\Parties\SupplierController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenant: Core Business Schema
|--------------------------------------------------------------------------
|
| Chart of accounts, customers/suppliers, and item categories/items - the
| master-data CRUD every later transactional module (sales, purchase,
| ledger) depends on. Required from routes/tenant.php inside its auth:web
| group. Split into its own file per the parallel-work convention (see
| mem.md gotcha #5) even though only one module owns it today.
|
*/

Route::name('tenant.')->group(function () {
    Route::prefix('account-groups')->name('account-groups.')->group(function () {
        Route::get('/', [AccountGroupController::class, 'index'])->name('index');
        Route::post('/', [AccountGroupController::class, 'store'])->name('store');
        Route::put('/{accountGroup}', [AccountGroupController::class, 'update'])->name('update');
        Route::delete('/{accountGroup}', [AccountGroupController::class, 'destroy'])->name('destroy');
    });

    Route::prefix('account-subgroups')->name('account-subgroups.')->group(function () {
        Route::get('/', [AccountSubgroupController::class, 'index'])->name('index');
        Route::post('/', [AccountSubgroupController::class, 'store'])->name('store');
        Route::put('/{accountSubgroup}', [AccountSubgroupController::class, 'update'])->name('update');
        Route::delete('/{accountSubgroup}', [AccountSubgroupController::class, 'destroy'])->name('destroy');
    });

    Route::prefix('accounts')->name('accounts.')->group(function () {
        Route::get('/', [AccountController::class, 'index'])->name('index');
        Route::post('/', [AccountController::class, 'store'])->name('store');
        Route::put('/{account}', [AccountController::class, 'update'])->name('update');
        Route::delete('/{account}', [AccountController::class, 'destroy'])->name('destroy');
    });

    Route::prefix('customers')->name('customers.')->group(function () {
        Route::get('/', [CustomerController::class, 'index'])->name('index');
        Route::post('/', [CustomerController::class, 'store'])->name('store');
        Route::put('/{customer}', [CustomerController::class, 'update'])->name('update');
        Route::delete('/{customer}', [CustomerController::class, 'destroy'])->name('destroy');
    });

    Route::prefix('suppliers')->name('suppliers.')->group(function () {
        Route::get('/', [SupplierController::class, 'index'])->name('index');
        Route::post('/', [SupplierController::class, 'store'])->name('store');
        Route::put('/{supplier}', [SupplierController::class, 'update'])->name('update');
        Route::delete('/{supplier}', [SupplierController::class, 'destroy'])->name('destroy');
    });

    Route::prefix('item-categories')->name('item-categories.')->group(function () {
        Route::get('/', [ItemCategoryController::class, 'index'])->name('index');
        Route::post('/', [ItemCategoryController::class, 'store'])->name('store');
        Route::put('/{itemCategory}', [ItemCategoryController::class, 'update'])->name('update');
        Route::delete('/{itemCategory}', [ItemCategoryController::class, 'destroy'])->name('destroy');
    });

    Route::prefix('item-subcategories')->name('item-subcategories.')->group(function () {
        Route::get('/', [ItemSubcategoryController::class, 'index'])->name('index');
        Route::post('/', [ItemSubcategoryController::class, 'store'])->name('store');
        Route::put('/{itemSubcategory}', [ItemSubcategoryController::class, 'update'])->name('update');
        Route::delete('/{itemSubcategory}', [ItemSubcategoryController::class, 'destroy'])->name('destroy');
    });

    Route::prefix('items')->name('items.')->group(function () {
        Route::get('/', [ItemController::class, 'index'])->name('index');
        Route::post('/', [ItemController::class, 'store'])->name('store');
        Route::put('/{item}', [ItemController::class, 'update'])->name('update');
        Route::delete('/{item}', [ItemController::class, 'destroy'])->name('destroy');
    });
});
