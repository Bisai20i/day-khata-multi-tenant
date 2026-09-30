<?php

declare(strict_types=1);

use App\Http\Controllers\Tenant\Accounting\AccountController;
use App\Http\Controllers\Tenant\Accounting\AccountGroupController;
use App\Http\Controllers\Tenant\Accounting\AccountSubgroupController;
use App\Http\Controllers\Tenant\Inventory\BarcodeLabelController;
use App\Http\Controllers\Tenant\Inventory\BrandController;
use App\Http\Controllers\Tenant\Inventory\ItemCategoryController;
use App\Http\Controllers\Tenant\Inventory\ItemController;
use App\Http\Controllers\Tenant\Inventory\ItemLedgerController;
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
    // Every write path into the chart of accounts has its own key
    // (accounts.create/.edit/.delete, opening_balances.import/.cancel): the
    // shape of the chart decides which side of the books an account lands
    // on, and the opening-balance import writes straight into the ledger
    // (audit P1, no admin gate on the chart of accounts or opening
    // balances). The read paths, including the opening-balance template,
    // sit under accounts.view so a role can look an account up without
    // being able to change the chart.
    Route::prefix('account-groups')->name('account-groups.')->group(function () {
        Route::get('/', [AccountGroupController::class, 'index'])->middleware('can:accounts.view')->name('index');

        Route::post('/', [AccountGroupController::class, 'store'])->middleware('can:accounts.create')->name('store');
        Route::put('/{accountGroup}', [AccountGroupController::class, 'update'])->middleware('can:accounts.edit')->name('update');
        Route::delete('/{accountGroup}', [AccountGroupController::class, 'destroy'])->middleware('can:accounts.delete')->name('destroy');
    });

    Route::prefix('account-subgroups')->name('account-subgroups.')->group(function () {
        Route::get('/', [AccountSubgroupController::class, 'index'])->middleware('can:accounts.view')->name('index');

        Route::post('/', [AccountSubgroupController::class, 'store'])->middleware('can:accounts.create')->name('store');
        Route::put('/{accountSubgroup}', [AccountSubgroupController::class, 'update'])->middleware('can:accounts.edit')->name('update');
        Route::delete('/{accountSubgroup}', [AccountSubgroupController::class, 'destroy'])->middleware('can:accounts.delete')->name('destroy');
    });

    Route::prefix('accounts')->name('accounts.')->group(function () {
        Route::get('/', [AccountController::class, 'index'])->middleware('can:accounts.view')->name('index');
        Route::get('/opening-balances/template', [AccountController::class, 'openingBalanceTemplate'])->middleware('can:accounts.view')->name('opening-balances.template');

        Route::post('/', [AccountController::class, 'store'])->middleware('can:accounts.create')->name('store');
        Route::put('/{account}', [AccountController::class, 'update'])->middleware('can:accounts.edit')->name('update');
        Route::delete('/{account}', [AccountController::class, 'destroy'])->middleware('can:accounts.delete')->name('destroy');
        Route::post('/opening-balances/import', [AccountController::class, 'importOpeningBalances'])->middleware('can:opening_balances.import')->name('opening-balances.import');
        Route::post('/opening-balances/{journalVoucher}/reverse', [AccountController::class, 'reverseOpeningBalanceImport'])->middleware('can:opening_balances.cancel')->name('opening-balances.reverse');
    });

    Route::prefix('customers')->name('customers.')->group(function () {
        Route::get('/', [CustomerController::class, 'index'])->middleware('can:customers.view')->name('index');
        Route::post('/', [CustomerController::class, 'store'])->middleware('can:customers.create')->name('store');
        Route::put('/{customer}', [CustomerController::class, 'update'])->middleware('can:customers.edit')->name('update');
        Route::delete('/{customer}', [CustomerController::class, 'destroy'])->middleware('can:customers.delete')->name('destroy');
        Route::get('/import/template', [CustomerController::class, 'importTemplate'])->middleware('can:customers.import')->name('import.template');
        Route::post('/import', [CustomerController::class, 'import'])->middleware('can:customers.import')->name('import');
    });

    Route::prefix('suppliers')->name('suppliers.')->group(function () {
        Route::get('/', [SupplierController::class, 'index'])->middleware('can:suppliers.view')->name('index');
        Route::post('/', [SupplierController::class, 'store'])->middleware('can:suppliers.create')->name('store');
        Route::put('/{supplier}', [SupplierController::class, 'update'])->middleware('can:suppliers.edit')->name('update');
        Route::delete('/{supplier}', [SupplierController::class, 'destroy'])->middleware('can:suppliers.delete')->name('destroy');
        Route::get('/import/template', [SupplierController::class, 'importTemplate'])->middleware('can:suppliers.import')->name('import.template');
        Route::post('/import', [SupplierController::class, 'import'])->middleware('can:suppliers.import')->name('import');
    });

    Route::prefix('brands')->name('brands.')->group(function () {
        Route::get('/', [BrandController::class, 'index'])->middleware('can:brands.view')->name('index');
        Route::post('/', [BrandController::class, 'store'])->middleware('can:brands.manage')->name('store');
        Route::put('/{brand}', [BrandController::class, 'update'])->middleware('can:brands.manage')->name('update');
        Route::delete('/{brand}', [BrandController::class, 'destroy'])->middleware('can:brands.manage')->name('destroy');
    });

    Route::prefix('item-categories')->name('item-categories.')->group(function () {
        Route::get('/', [ItemCategoryController::class, 'index'])->middleware('can:item_categories.view')->name('index');
        Route::post('/', [ItemCategoryController::class, 'store'])->middleware('can:item_categories.manage')->name('store');
        Route::put('/{itemCategory}', [ItemCategoryController::class, 'update'])->middleware('can:item_categories.manage')->name('update');
        Route::delete('/{itemCategory}', [ItemCategoryController::class, 'destroy'])->middleware('can:item_categories.manage')->name('destroy');
    });

    Route::prefix('item-subcategories')->name('item-subcategories.')->group(function () {
        Route::get('/', [ItemSubcategoryController::class, 'index'])->middleware('can:item_categories.view')->name('index');
        Route::post('/', [ItemSubcategoryController::class, 'store'])->middleware('can:item_categories.manage')->name('store');
        Route::put('/{itemSubcategory}', [ItemSubcategoryController::class, 'update'])->middleware('can:item_categories.manage')->name('update');
        Route::delete('/{itemSubcategory}', [ItemSubcategoryController::class, 'destroy'])->middleware('can:item_categories.manage')->name('destroy');
    });

    Route::prefix('items')->name('items.')->group(function () {
        Route::get('/', [ItemController::class, 'index'])->middleware('can:items.view')->name('index');
        Route::post('/', [ItemController::class, 'store'])->middleware('can:items.create')->name('store');
        Route::put('/{item}', [ItemController::class, 'update'])->middleware('can:items.edit')->name('update');
        Route::delete('/{item}', [ItemController::class, 'destroy'])->middleware('can:items.delete')->name('destroy');
        Route::get('/import/template', [ItemController::class, 'importTemplate'])->middleware('can:items.import')->name('import.template');
        Route::post('/import', [ItemController::class, 'import'])->middleware('can:items.import')->name('import');
        // See BarcodeLabelController's docblock - not nested under a single
        // {item} since one print request can cover several items at once.
        Route::get('/barcode-labels/print', [BarcodeLabelController::class, 'print'])->middleware('can:items.print')->name('barcode-labels.print');
        // Bulk "mark vatable" (item 6 of T13) - not nested under {item} for
        // the same reason as barcode-labels.print above.
        Route::post('/mark-vatable', [ItemController::class, 'markVatable'])->middleware('can:items.edit')->name('mark-vatable');
        // Per-unit barcode scan (item 6 of T13): Sales/Create, Pos.vue and
        // Purchases/Create call this to resolve a scanned code to an item +
        // (optionally) a specific alternate unit. Gated items.view, not
        // allowlisted: it returns the full Item model including cost fields
        // (ROUTE-MAP shared lookup item 2).
        Route::get('/lookup-barcode', [ItemController::class, 'lookupBarcode'])->middleware('can:items.view')->name('lookup-barcode');
    });

    // Nested under an item rather than its own top-level resource - see
    // ItemController::storeUnit()'s docblock.
    Route::prefix('items/{item}/units')->name('items.units.')->group(function () {
        Route::post('/', [ItemController::class, 'storeUnit'])->middleware('can:items.edit')->name('store');
        Route::put('/{itemUnit}', [ItemController::class, 'updateUnit'])->middleware('can:items.edit')->name('update');
        Route::delete('/{itemUnit}', [ItemController::class, 'destroyUnit'])->middleware('can:items.edit')->name('destroy');
    });

    // One item's stock history with running balance, beside the item like
    // accounts/{account}/ledger sits beside the accounts. Read-only and gated
    // by the same stock_reports.* keys as the Stock Movement Register it
    // drills into (ROUTE-MAP shared lookup item 8).
    Route::get('/items/{item}/ledger', [ItemLedgerController::class, 'show'])->middleware('can:stock_reports.view')->name('items.ledger');
    Route::get('/items/{item}/ledger/print', [ItemLedgerController::class, 'print'])->middleware('can:stock_reports.print')->name('items.ledger.print');
    Route::get('/items/{item}/ledger/export', [ItemLedgerController::class, 'export'])->middleware('can:stock_reports.export')->name('items.ledger.export');
});
