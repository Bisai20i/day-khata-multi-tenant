# Item Ledger

**Status as of 2026-09-29**: all 5 rollout steps done. Deviations from the design below: the actions live in
a new `ItemLedgerController` (`show`/`print`/`export`) instead of growing `ItemController` (already ~600 lines);
the label, reference and signed-quantity helpers moved into the `DescribesStockMovements` controller concern,
shared with `StockMovementRegisterController`; the `Ledger` link/column is one helper in
`resources/js/lib/itemLedger.js`. On `DamageLostStock.vue` only the item-wise table gets the link (its line
rows carry no item id). `from`/`to`/`store_id` are validated (`to >= from`).
This doc is the build plan for this feature - read it before starting work, update its Status line as
phases land, same discipline as `plans/central-panel-build.md`.

## Why this exists

The user fixed a legacy-app gap first (`day_khata`'s Stock Report didn't link an item's name to its Item
Ledger the way the Item List already did - both now open `itemLedger.blade.php`, a simple all-time list of
that item's Purchase/Sale lines with an In/Out totals footer, no running balance). They then asked whether
this rewrite (`day-khata-multi-tenant`) has the same UX. It does not: this app has **no item ledger at
all**, on either the Items page or any Stock report.

## Sources

Direct code reading this session, this repo only: `resources/js/pages/Tenant/Inventory/Items/Index.vue`,
`resources/js/pages/Tenant/Accounting/Accounts/{Index,Ledger}.vue`,
`app/Http/Controllers/Tenant/Accounting/AccountController.php` (`ledger`/`ledgerData`),
`app/Http/Controllers/Tenant/Reports/{StockMovementRegisterController,InventoryReportController,
StockValuationReportController,DamageLostStockReportController,BrandWiseReportController,
CategoryWiseReportController}.php`, `app/Models/{Item,ItemStockMovement}.php`,
`routes/tenant-business.php`, `resources/js/lib/nav-items.js`. Cross-checked against legacy's
`app/Http/Controllers/reportsController.php::itemLedger()` and `resources/views/reports/stock/
itemledger.blade.php` for what "parity" means here.

## Locked decisions (user sign-off via `AskUserQuestion`, 2026-09-29)

1. **Dedicated Item Ledger page**, not a redirect into the existing Stock Movement Register filtered by
   `item_id`. The register is a good audit trail but is built for *browsing many items*, not for *opening
   one item's full history* - no opening/running balance, no per-item header, filter chrome stays visible.
   New page mirrors `Accounts/Ledger.vue`'s shape: opening balance, running balance per row, closing
   balance, print, Excel export.
2. **Entry point**: follow this app's own `Accounts/Index.vue` convention - a `Ledger` text link in the
   row-actions column, not the item name itself turned into a link. (Legacy's fix made the name the link
   because that's the only pattern legacy has; this app already has an established "Ledger" action-link
   convention for Accounts, and staying consistent with it beats copying legacy's specific mechanism.)

## Non-goals (explicitly out of scope for this plan)

- **Stock by Brand / Stock by Category reports.** Both aggregate at the brand/category level
  (`BrandWiseReportController`/`CategoryWiseReportController`) - there is no per-item row to attach a
  `Ledger` link to. Nothing to change there.
- **Item-wise Sales / Item-wise Purchase reports.** These already exist under Sales Report / Purchase
  Report (not Stock Report) and are themselves single-item drill-downs of sales/purchase documents, which
  is a different, narrower view than a stock ledger. Could get a `Ledger` link later as a small follow-on;
  not bundled here since the user's ask was specifically about Stock Report parity.
- **Fiscal-year scoping of the ledger.** Unlike `Account`/`JournalVoucherLine`, `ItemStockMovement` has no
  `fiscal_year_id` and stock is perpetual across years (opening stock carries forward physically, it isn't
  reset by a closing entry the way accounts are). The ledger takes a plain optional date range, no fiscal
  year selector.
- **New migration.** `ItemStockMovement` already carries every field the ledger needs (`item_id`,
  `store_id`, `movement_type`, `quantity`, `unit_cost_rate`, `value`, `date`, `cancelled`, polymorphic
  `reference`). Zero schema changes.

## Already strong - reusable building blocks (do not rebuild)

- **`ItemStockMovement`** (`app/Models/ItemStockMovement.php`) already records every stock-affecting event
  - Purchase, Sale, Purchase/Sale Return, Stock Adjustment In/Out, Transfer In/Out, Production/Refining/
  Repackaging In/Out - each with a polymorphic `reference` back to its source document. This is already a
  strict superset of legacy's item ledger, which only ever shows Purchase and Sale rows.
- **`StockMovementRegisterController`** (`app/Http/Controllers/Tenant/Reports/
  StockMovementRegisterController.php`) already has the exact query shape needed (scoped by `item_id`,
  date range, `cancelled = false`, eager-loaded `reference` via `morphWith`) plus working
  `movementTypeLabel()` and `referenceDescription()` helpers - the new controller reuses this query pattern
  and these two helpers (extract to a shared trait/concern if duplicating the `match` blocks verbatim feels
  wrong, per existing app convention of small per-controller duplication over premature abstraction - see
  `StockMovementRegisterController`'s own docblock on `resolveDateRange()`).
- **`Item::currentStockByItem($itemIds, $storeId, $asOf)`** (`app/Models/Item.php:220`) already computes
  net signed quantity as of a given date from `ItemStockMovement` - exactly what an opening balance needs
  (call it for `[$item->id]` with `asOf = dayBefore($from)`), same technique `StockSummary`'s `stockSummary()`
  already uses for its own opening column.
- **`Accounts/Ledger.vue` + `AccountController::ledger()/ledgerData()`** is the UI/controller shape to
  mirror end-to-end: header card with entity name, optional date window with Apply/Clear, a totals footer,
  Print and Excel buttons, one Inertia page per entity instance.

## Design

### Route

Nested under the existing `items` group in `routes/tenant-business.php` (next to `items/{item}/units`),
matching how `accounts/{account}/ledger` sits alongside the `accounts` resource:

```php
Route::get('items/{item}/ledger', [ItemController::class, 'ledger'])->name('items.ledger');
Route::get('items/{item}/ledger/print', [ItemController::class, 'ledgerPrint'])->name('items.ledger.print');
Route::get('items/{item}/ledger/export', [ItemController::class, 'ledgerExport'])->name('items.ledger.export');
```

### Controller (`app/Http/Controllers/Tenant/Inventory/ItemController.php`)

`ledger(Request $request, Item $item)`:

1. Resolve `from`/`to` from the request, defaulting to the current open fiscal year's window (or
   month-to-date with none open) - same default rule `StockMovementRegisterController::resolveDateRange()`
   already uses; item stock itself isn't fiscal-year-scoped, but defaulting the *view* to the current year
   keeps the page from rendering years of history the first time it opens.
2. Opening balance: `Item::currentStockByItem([$item->id], null, dayBefore($from))[$item->id] ?? Quantity::zero()`.
3. Movement rows: `ItemStockMovement::where('item_id', $item->id)->where('cancelled', false)
   ->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)->with(['store:id,name', 'reference' =>
   morphWith(...)])->orderBy('date')->orderBy('id')->get()`, mapped to `{date, type (movementTypeLabel),
   storeName, signedQuantity, unitCostRate, reference (referenceDescription)}` plus a running balance
   computed by folding the opening balance forward row by row (all in `Quantity`, never floats - see
   `signedQuantity()`'s existing docblock on why).
4. Closing balance = opening + sum of signed quantities in range (= last row's running balance, or opening
   if no rows).
5. Render `Inertia::render('Tenant/Inventory/Items/Ledger', [...])` with `item`, `from`, `to`,
   `openingBalance`, `closingBalance`, `entries`, `stores` (for an optional store filter, since movements
   carry `store_id`).

`ledgerPrint()` / `ledgerExport()`: same `ledgerData()` extraction, reusing `Pdf::loadView()` /
`Excel::download()` the same way `AccountController` does, with a new small Blade PDF view and Export class
(`app/Exports/ItemLedgerExport.php`) mirroring `AccountBookExport`.

### Frontend

- **New page**: `resources/js/pages/Tenant/Inventory/Items/Ledger.vue`, structurally a copy of
  `Accounts/Ledger.vue` (date window controls, Print/Excel buttons, entries table, opening/closing balance
  rows) with an Item-shaped header instead of an Account-shaped one, and columns `Date / Type / Store /
  Quantity (signed) / Running Balance / Reference` instead of the account ledger's debit/credit columns.
- **`Items/Index.vue`** (`resources/js/pages/Tenant/Inventory/Items/Index.vue`): add a `Ledger` link to
  the existing `actions` column (~line 296), same `h(Link, { href: \`/items/${row.original.id}/ledger\` },
  { default: () => 'Ledger' })` placed before the `Units` button, matching `Accounts/Index.vue:232-235`.
- **Stock report pages with per-item rows** - add a trailing `actions` column (none of these currently have
  one) rendering the same `Ledger` link:
  - `resources/js/pages/Tenant/Reports/StockSummary.vue` (row already carries `itemId`, `InventoryReportController::stockSummary()`)
  - `resources/js/pages/Tenant/Reports/StockValuation.vue` (row already carries `itemId`, `StockValuationReportController`)
  - `resources/js/pages/Tenant/Reports/DamageLostStock.vue` (row carries `item_id`, `DamageLostStockReportController::itemWiseTotals()`)
  - `resources/js/pages/Tenant/Reports/StockMovementRegister.vue` (needs a backend change first - see below)
- **`StockMovementRegisterController::index()`** (~line 85-94): the mapped `movements` array currently
  emits `itemName` but not the item's id - add `'itemId' => $movement->item_id` alongside it so the Vue
  page can build the link.

## Testing

- New `tests/Feature/Tenant/Inventory/ItemLedgerTest.php` (Pest): seed a mix of Purchase, Sale, a
  cancelled sale (must be excluded), a Purchase Return, and a Stock Adjustment against one item; assert the
  ledger's opening balance, per-row running balance, and closing balance are all correct for a date window
  that starts mid-history (proves the opening-balance-before-window logic, the exact class of bug
  `report-parity-audit-2026-09-22.md`'s Trial Balance finding was about, just on the stock side). Assert a
  cancelled movement never appears and never affects the running balance.
- Extend `tests/Feature/Tenant/Reports/InventoryReportTest.php` (or add a small assertion elsewhere) to
  confirm `StockMovementRegisterController` now returns `itemId` per row.
- Run `php artisan test --compact --filter=ItemLedger` plus the touched report tests before calling this
  done, per this repo's Test Enforcement rule.

## Rollout order

1. Backend: `ItemController::ledger/ledgerPrint/ledgerExport` + routes + `ItemLedgerExport` + PDF view +
   feature test.
2. Frontend: `Items/Ledger.vue` + `Ledger` link on `Items/Index.vue`. Ship and verify these two together
   first - it's the direct analogue of the legacy fix and independently useful.
3. `StockMovementRegisterController` `itemId` fix + `Ledger` link on `StockMovementRegister.vue`.
4. `Ledger` link on `StockValuation.vue` and `StockSummary.vue` (no backend change needed, already have
   `itemId`).
5. `Ledger` link on `DamageLostStock.vue`.
