<?php

declare(strict_types=1);

use App\Http\Controllers\Tenant\Accounting\AccountController;
use App\Http\Controllers\Tenant\Accounting\AccountGroupController;
use App\Http\Controllers\Tenant\Accounting\AccountSubgroupController;
use App\Http\Controllers\Tenant\Inventory\BarcodeLabelController;
use App\Http\Controllers\Tenant\Inventory\BrandController;
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
    // Every write path into the chart of accounts is admin-only: the shape of
    // the chart decides which side of the books an account lands on, and the
    // opening-balance import writes straight into the ledger (audit P1, "No
    // role:admin on ... chart of accounts, opening balances"). The read paths
    // stay open so staff can look an account or its ledger up.
    Route::prefix('account-groups')->name('account-groups.')->group(function () {
        Route::get('/', [AccountGroupController::class, 'index'])->name('index');

        Route::middleware('role:admin')->group(function () {
            Route::post('/', [AccountGroupController::class, 'store'])->name('store');
            Route::put('/{accountGroup}', [AccountGroupController::class, 'update'])->name('update');
            Route::delete('/{accountGroup}', [AccountGroupController::class, 'destroy'])->name('destroy');
        });
    });

    Route::prefix('account-subgroups')->name('account-subgroups.')->group(function () {
        Route::get('/', [AccountSubgroupController::class, 'index'])->name('index');

        Route::middleware('role:admin')->group(function () {
            Route::post('/', [AccountSubgroupController::class, 'store'])->name('store');
            Route::put('/{accountSubgroup}', [AccountSubgroupController::class, 'update'])->name('update');
            Route::delete('/{accountSubgroup}', [AccountSubgroupController::class, 'destroy'])->name('destroy');
        });
    });

    Route::prefix('accounts')->name('accounts.')->group(function () {
        Route::get('/', [AccountController::class, 'index'])->name('index');
        Route::get('/opening-balances/template', [AccountController::class, 'openingBalanceTemplate'])->name('opening-balances.template');

        Route::middleware('role:admin')->group(function () {
            Route::post('/', [AccountController::class, 'store'])->name('store');
            Route::put('/{account}', [AccountController::class, 'update'])->name('update');
            Route::delete('/{account}', [AccountController::class, 'destroy'])->name('destroy');
            Route::post('/opening-balances/import', [AccountController::class, 'importOpeningBalances'])->name('opening-balances.import');
            Route::post('/opening-balances/{journalVoucher}/reverse', [AccountController::class, 'reverseOpeningBalanceImport'])->name('opening-balances.reverse');
        });
    });

    Route::prefix('customers')->name('customers.')->group(function () {
        Route::get('/', [CustomerController::class, 'index'])->name('index');
        Route::post('/', [CustomerController::class, 'store'])->name('store');
        Route::put('/{customer}', [CustomerController::class, 'update'])->name('update');
        Route::delete('/{customer}', [CustomerController::class, 'destroy'])->name('destroy');
        Route::get('/import/template', [CustomerController::class, 'importTemplate'])->name('import.template');
        Route::post('/import', [CustomerController::class, 'import'])->name('import');
    });

    Route::prefix('suppliers')->name('suppliers.')->group(function () {
        Route::get('/', [SupplierController::class, 'index'])->name('index');
        Route::post('/', [SupplierController::class, 'store'])->name('store');
        Route::put('/{supplier}', [SupplierController::class, 'update'])->name('update');
        Route::delete('/{supplier}', [SupplierController::class, 'destroy'])->name('destroy');
        Route::get('/import/template', [SupplierController::class, 'importTemplate'])->name('import.template');
        Route::post('/import', [SupplierController::class, 'import'])->name('import');
    });

    Route::prefix('brands')->name('brands.')->group(function () {
        Route::get('/', [BrandController::class, 'index'])->name('index');
        Route::post('/', [BrandController::class, 'store'])->name('store');
        Route::put('/{brand}', [BrandController::class, 'update'])->name('update');
        Route::delete('/{brand}', [BrandController::class, 'destroy'])->name('destroy');
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
        Route::get('/import/template', [ItemController::class, 'importTemplate'])->name('import.template');
        Route::post('/import', [ItemController::class, 'import'])->name('import');
        // See BarcodeLabelController's docblock - not nested under a single
        // {item} since one print request can cover several items at once.
        Route::get('/barcode-labels/print', [BarcodeLabelController::class, 'print'])->name('barcode-labels.print');
        // Bulk "mark vatable" (item 6 of T13) - not nested under {item} for
        // the same reason as barcode-labels.print above.
        Route::post('/mark-vatable', [ItemController::class, 'markVatable'])->name('mark-vatable');
        // Per-unit barcode scan (item 6 of T13): Sales/Create, Pos.vue and
        // Purchases/Create call this to resolve a scanned code to an item +
        // (optionally) a specific alternate unit.
        Route::get('/lookup-barcode', [ItemController::class, 'lookupBarcode'])->name('lookup-barcode');
    });

    // Nested under an item rather than its own top-level resource - see
    // ItemController::storeUnit()'s docblock.
    Route::prefix('items/{item}/units')->name('items.units.')->group(function () {
        Route::post('/', [ItemController::class, 'storeUnit'])->name('store');
        Route::put('/{itemUnit}', [ItemController::class, 'updateUnit'])->name('update');
        Route::delete('/{itemUnit}', [ItemController::class, 'destroyUnit'])->name('destroy');
    });
});
