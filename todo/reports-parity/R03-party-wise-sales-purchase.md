# R03 Customer-wise Sales and Supplier-wise Purchase (new reports)

Read `todo/reports-parity/START.md` first. It holds the rules, lessons and hand-back format.

Legacy: `reportsController::salesRegularCustomerWiseReportDateBetween`, `customerWiseLedgerReportDateBetween`,
`purchaseRegularSupplierWiseReportDateBetween`, `supplierWiseLedgerReportDateBetween`; views
`reports/sales/customerwisesales`, `customerledger`, `reports/purchase/supplierwisereport`, `supplierledger`.
The new app has no party-wise sales or purchase report at all (the Debtors / Creditors lists are balances, not
turnover). This track builds both.

## Owned files (all NEW)

- `app/Http/Controllers/Tenant/Reports/PartyWiseSalesReportController.php`
- `app/Http/Controllers/Tenant/Reports/PartyWisePurchaseReportController.php`
- `app/Exports/PartyWiseSalesExport.php`, `app/Exports/PartyWisePurchaseExport.php`
- `resources/js/pages/Tenant/Reports/PartyWiseSales.vue`, `PartyWisePurchase.vue`
- NEW route files `routes/tenant-reports-party-wise-sales.php` and `routes/tenant-reports-party-wise-purchase.php`
  may be **drafted as text in your hand-back only**; routes are coordinator-only, so do not create the files.

Do not edit `SalesPurchaseReportController` (the Debtors / Creditors work lives there), `AccountingReportController`
or any existing report page. Read `SalesPurchaseReportController`'s register methods for the query shape and
copy the approach, never call into private methods of it.

## Report definition

For each customer (sales) or supplier (purchase) with activity in the period: document count, taxable amount,
VAT, total, returns (credit or debit notes, posted only), net, amount received or paid in the period, and the
outstanding balance at the end date. Click a party to see its documents (one row per sale or purchase with BS
date, number, taxable, VAT, total, payment status) with links to the document.

## Tasks

- [x] 1. Audit the four legacy methods and views, list what they show, decide what carries over.
  Legacy: customer-wise sales = ONE customer's non-cancelled bills in a range with item lines (item, qty, rate,
  VAT, discount, total) and VAT / discount / total footers; supplier-wise purchase is a stub (query commented
  out); customer / supplier ledger = account ledger with opening balance. Carried over: per-party document list
  (drill-down) with totals. Not carried: item lines (Item-wise reports cover them; the drill-down links to the
  sale), ledger with opening balance (the Account Ledger / Debtors / Creditors already do that).
  Done by the R03 worker; see the hand-back for route and catalog text.
- [x] 2. Backend: strict validation (`Y-m-d` dates, `to >= from`, `customer_id` / `supplier_id` `exists`,
  `store_id` `exists`), branch filter via `BranchFilter`, payment-mode filter, search by party name or PAN.
- [x] 3. Totals tie out: sum of party rows equals the Sales / Purchase Register total for the same filters;
  returns use the stored `credit_note_number` / `debit_note_number` and `tds_amount`, posted only. Cash and
  walk-in sales with no customer appear as a single "Cash / walk-in" row, not dropped.
- [x] 4. Outstanding at the end date uses `Sale::outstandingAmount()` / `Purchase::outstandingAmount()` (exact,
  no tolerance). If that is too slow per party at scale, aggregate in SQL and cross-check the method in review.
- [x] 5. Drill-down: the per-party documents view (same controller, a `party` query param or a second route),
  total equals the parent row.
- [x] 6. Excel export for each, same private method as the screen. Heading rows, numeric cells, Total row.
- [x] 7. Vue pages: filter bar, party table with totals row, drill-down view, Export button gated by
  `sales_reports.export` / `purchase_reports.export`, BS dates, every prop declared, under about 500 lines each.
- [x] 8. Hand-back with **Route requests** (two route files, index, party detail, export, gates
  `sales_reports.view` / `sales_reports.export` and the purchase pair, names `party-wise-sales`,
  `party-wise-sales.export`, ...) and **Catalog requests**: entries for `report-catalog.js` in the Sales and
  Purchase groups with the matching permissions.
