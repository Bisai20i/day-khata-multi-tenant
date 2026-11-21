# R01 Item-wise Sales and Item-wise Purchase

Read `todo/reports-parity/START.md` first. It holds the rules, lessons and hand-back format.

Legacy: `reportsController::viewSalesReportItemWise`, `SearchItemReportBetweenDateoutstock`,
`salesWithItems*`, `viewPurchaseReportItemWise`, `purchaseWithItems*`; views `reports/sales/itemwisesales`,
`viewsalesbillitems`, `reports/purchase/itemwisepurchase`, `purchasewithItems`.

## Owned files

- `app/Http/Controllers/Tenant/Reports/ItemWiseSalesReportController.php`
- `app/Http/Controllers/Tenant/Reports/ItemWisePurchaseReportController.php`
- `resources/js/pages/Tenant/Reports/ItemWiseSales.vue`, `ItemWisePurchase.vue`
- NEW `app/Exports/ItemWiseSalesExport.php`, `app/Exports/ItemWisePurchaseExport.php`
- NEW components under `resources/js/components/reports/` only if both pages share one (check first whether the
  folder and an equivalent already exist)

Do not edit `SalesPurchaseReportController`, `CategoryWiseReportController` or any `routes/*.php` file.

## Current state (verified)

Both controllers have only `index`. No Excel export, no print. Date handling goes through a private
`resolveDateRange()` (check whether it is strict `Y-m-d` or silently falls back). Store and branch filters exist.
An item can be picked to show its bill lines (`lines`). Quantities are grouped by unit (`quantitiesByUnit`).

## Checkpoint (R01 worker, mid-task)

Audit done. Legacy item-wise pages: date range + one item (required), per-line rows (date, serial, bill no,
customer "Walk-In" fallback, qty in entry unit, rate, VAT, discount, total) and a grand total incl. VAT; no
customer/supplier filter, no returns, no aggregate. Decisions: keep the all-items aggregate; add Net (after line
AND document discount, excl. VAT), VAT, Gross (incl. round off) per item; returns as separate columns (posted
returns dated in range) plus Net after returns; drill-down shows sale/purchase lines and return lines (signed)
built from the same allocated rows as the summary. Sale lines have no stored per-line components, so the
controller allocates each posted sale's stored taxable/nontaxable/VAT/round off across ALL its lines (largest
remainder, same weights as SalesReturn::saleComponents) before item filters apply - ties to the Sales Register
Subtotal / VAT / Total exactly. Purchase lines use stored net_value / vat_amount / round_off. Party filter
skipped (legacy has none; R03 builds party-wise reports). Purchases/returns have no show route, so only sale
rows link (to /sales/{id}).

## Tasks

- [x] 1. Audit against the legacy views and list the gaps (filters, columns, totals, drill-down). Decide each.
- [x] 2. Strict validation: `from`, `to` as `date_format:Y-m-d` with `to` after or equal `from`; `store_id`
  `exists`; `item_id` `exists`. A bad value is a 422, not a fallback.
- [x] 3. Item filters: reuse `App\Support\Reports\StockItemFilters` (category, subcategory, brand, name search)
  for the item list, so a user can narrow to one category or brand, as legacy lets them narrow by group.
  Also a customer filter on sales / supplier filter on purchases if the legacy page has one.
- [x] 4. Cancelled and returned documents: confirm only posted, non-cancelled documents count, and that sales
  returns / purchase returns are either netted per item or shown as a separate column ("Returned qty",
  "Returned value") so the item total ties to the Sales / Purchase Register net. Pick one, document it in the
  page's help text, apply it to the export.
- [x] 5. Totals: per-unit quantity totals (never add different units), exact money total, a grand total row
  that equals the register total for the same filters. Net, VAT and gross shown if the legacy page shows them.
- [x] 6. Drill-down: the item-detail lines link each row to its sale or purchase show page (routes exist);
  the detail total equals the item's row in the summary.
- [x] 7. Excel export for each report, built from the same private method as the screen. Summary sheet or
  section plus the detail lines when an item is selected. Heading rows, numeric cells, Total row, branch label.
- [x] 8. Vue: filter bar, Export button gated by `can('sales_reports.export')` / `can('purchase_reports.export')`,
  URL built from applied props plus `branchFilter.appendTo`. BS date column and range label via `formatBsDate`.
  Every prop declared. Keep each page under about 500 lines.
- [x] 9. Hand-back with **Route requests**: `/item-wise-sales/export` and `/item-wise-purchase/export`, gated
  `['can:sales_reports.view', 'can:sales_reports.export']` and the purchase pair, route names
  `item-wise-sales.export` and `item-wise-purchase.export`, in the two existing item-wise route files.
