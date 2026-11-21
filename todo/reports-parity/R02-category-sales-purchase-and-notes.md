# R02 Sales by Category, Purchase by Category, Sales With Note

Read `todo/reports-parity/START.md` first. It holds the rules, lessons and hand-back format.

Legacy: `reportsController::singleitemgroupwisesales`, `singleitemgroupwisepurchase`, `Groupwisestock` family;
views `reports/sales/itemgroupwise`, `itemsubgroupwise`, `reports/purchase/itemgroupwise`, `itemsubgroupwise`,
`groupwisePurchaseItemsReport`; sales with note: `saleswithnote*`, view `reports/sales/saleswithnoteledger`.

## Owned files

- `app/Http/Controllers/Tenant/Reports/CategoryWiseReportController.php`, **only** `salesByCategory`,
  `purchaseByCategory`, the new methods you add for them, and the private helpers
  `categoryAggregatesFromSaleLines`, `categoryAggregatesFromPurchaseLines`, `buildCategoryValueRows`,
  `mergeQuantities`, `presentQuantities`, `isEmptyAggregate`, `bucket`, `resolveDateRange`. The stock methods
  (`stockByCategory*`, `categoryAggregatesFromStock`, `resolveStockQuery`, `presentItemRows`) were finished in
  batch 2: read them as the pattern, do not change them.
- `app/Http/Controllers/Tenant/Reports/SalesWithNoteReportController.php`
- `resources/js/pages/Tenant/Reports/SalesByCategory.vue`, `PurchaseByCategory.vue`, `SalesWithNote.vue`
- NEW `app/Exports/SalesByCategoryExport.php`, `PurchaseByCategoryExport.php`, `SalesWithNoteExport.php`
- NEW pages `resources/js/pages/Tenant/Reports/SalesByCategoryItems.vue`, `PurchaseByCategoryItems.vue`
  (drill-down, mirroring `StockByCategoryItems.vue`)

Do not edit `StockByCategory*.vue`, `BrandWiseReportController`, `SalesPurchaseReportController` or any
`routes/*.php` file.

## Current state (verified)

`salesByCategory` and `purchaseByCategory` exist with no export and no drill-down; the date range goes through a
private `resolveDateRange()` (check whether it is strict). Sales With Note has `index` only, no export.

## Tasks

- [x] 1. Audit against the legacy views and list the gaps. Decide each.
- [x] 2. Strict validation on all three reports (`Y-m-d` dates, `to >= from`, `store_id` and ids `exists`).
- [x] 3. Sales and Purchase by Category: add the sub-category level (legacy has group and sub-group pages),
  an "Uncategorized" row with the `'none'` sentinel exactly as `stockByCategoryItems` handles it
  (`^(none|[1-9][0-9]*)$`, 404 for a missing record), and a brand split if the legacy pages have one.
- [x] 4. Returns: decide and document whether category totals are net of returns (preferred, so the grand total
  equals the Sales / Purchase Register net for the same dates) or gross with a returns column. Only `posted`
  returns count. Cancelled documents follow the same effective-date rule the VAT reports use.
- [x] 5. Drill-down pages for both: category (and subcategory) to its items to the bill lines, with links to the
  sale or purchase show page. The drill-down total equals the parent row.
- [x] 6. Sales With Note: add a free-text note search and customer filter, show the note text in full, link each
  row to its sale, add a totals row. Confirm what "note" means in this app (`sales.note` or equivalent) before
  filtering on it.
- [x] 7. Excel exports for all three, built from the same private method as the screen. Heading rows, numeric
  cells, Total row, per-unit quantity totals (never add different units).
- [x] 8. Vue: Export buttons gated by `sales_reports.export` / `purchase_reports.export`, URLs from applied
  props plus `branchFilter.appendTo`, BS dates via `formatBsDate`, every prop declared.
- [x] 9. Hand-back with **Route requests**: `/sales-by-category/items`, `/purchase-by-category/items`,
  `/sales-by-category/export`, `/purchase-by-category/export`, `/sales-with-note/export`, gates and names
  following the stock-by-category pattern, in `routes/tenant-reports-category-wise.php` and
  `routes/tenant-reports-sales-with-note.php`.

## Decisions (review fixes, 2026-11-20)

- Brand split: not built; Stock by Brand covers brand, and sales/purchase by brand was not in legacy (legacy
  `reportsController` uses `Company` (brand) only in the stock reports and `Companywisestock`).
- Basis: every Sales / Purchase by Category amount is after line AND document discounts, before VAT, the
  Item-wise basis. Sales lines and unlinked credit notes are split with `AllocatesDocumentDiscount::splitDocument()`
  exactly as `ItemWiseSalesReportController` does; purchase and debit note lines use their stored `net_value`.
  The return item is the return line's own, falling back to the original line's, as Item-wise does.
- Returns: shown as a separate column plus a Net column (value minus returns). Grand total Net equals Item-wise's
  net total for the same dates, store and branch; the items drill-down uses the same rows, so category row,
  items page and the Item-wise "View" link all tie.
- Quantities include bonus units: `(quantity + bonus_quantity) x factor`, as Item-wise.
- Excel: Net added as the last column (F) of both exports; no existing column moved.
- Gap decisions from the legacy audit (item 1) live in the controller docblocks.
