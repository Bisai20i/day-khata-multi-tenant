# T10 Reports, VAT and TDS

Phase 3 | Parallel with T11 | Consumes C1, C5-C10 and every Phase 2 column | Audit refs: P0-13 (report
filters), P0-20, P1 VAT book columns, return registers, Debtors/Creditors, ageing with opening balances,
stock valuation reports, Excel exports, BS dates, P1-4 reports rounding.

Phase 2 is committed before you start: read the new columns in the Phase 2 migrations
(`2026_09_12_04*` to `2026_09_12_08*`) and the updated models before writing queries.

## Owned files

`app/Http/Controllers/Tenant/Reports/SalesPurchaseReportController.php`, `VatSummaryReportController.php`,
`TdsReportController.php`, `CategoryWiseReportController.php`, `BrandWiseReportController.php`,
`InventoryReportController.php`, `StockValuationReportController.php`, `StockMovementRegisterController.php`,
`DamageLostStockReportController.php`, `ItemWiseSalesReportController.php`,
`ItemWisePurchaseReportController.php`, `SalesWithNoteReportController.php`, `app/Exports/*`, new report
controllers/exports you create under the same folders, every `routes/tenant-reports-*.php` except
`tenant-reports-accounting.php` and `tenant-reports-print-log.php`, and these pages under
`resources/js/pages/Tenant/Reports/`: `AgedPayables`, `AgedReceivables`, `DamageLostStock`, `ItemWisePurchase`,
`ItemWiseSales`, `PurchaseByCategory`, `PurchaseRegister`, `PurchaseVatBook`, `SalesByCategory`,
`SalesRegister`, `SalesVatBook`, `SalesWithNote`, `StockByBrand`, `StockByCategory`, `StockMovementRegister`,
`StockSummary`, `StockValuation`, `TdsReport`, `VatSummary`, plus new pages you create there. Tests: the
matching files in `tests/Feature/Tenant/Reports/` (all except `AccountingReportTest.php` and
`PrintLogReportTest.php`) and new ones. Migrations with prefix `2026_09_13_10` (probably none needed).

## Tasks

- [x] 1. Money everywhere: aggregate in SQL `SUM` on DECIMAL, read as strings, any further arithmetic in
  `Money`/`Quantity`, send strings to Inertia, format with `formatMoney`/`formatQuantity` on the page. Remove
  every `(float)`/`round()`. Excel exports keep **numeric** cells (use `toFloat()` only at the cell boundary,
  with a 2-decimal number format) so sums work in Excel. Date filters are inclusive on both ends on SQLite
  and MySQL (compare on the date column, never `whereBetween` against datetimes that drop the last day).
- [x] 2. Status semantics: only `posted` returns count (C6); cancelled documents appear in their original
  period as issued **and** as a negative "cancelled" row in the period of their reversal voucher date (C5,
  decision "filed months never change"). Apply the same effective-date rule to TDS (returns reduce TDS in the
  return's period, not the original's).
- [x] 3. VAT Summary and both VAT books include capital sales and capital purchases (separate "Capital"
  column/section) using the Phase 2 fields, so output VAT and input VAT equal the LIA20 and ASA23 ledger
  movements for the period. Add a reconciliation line on the VAT Summary: "Ledger VAT payable/receivable for
  the period" vs "Report total", with the difference (must be 0.00).
- [x] 4. Sales VAT book columns: BS date (and AD), stored `invoice_number`, buyer name and buyer PAN from the
  snapshot, taxable, exempt, VAT, total. Purchase VAT book: BS date, supplier bill number (not our voucher
  number), supplier PAN (from `supplier.tpin` / snapshot), taxable, exempt, VAT, capital. Same columns in the
  Excel exports.
- [x] 5. New Sales Return register and Purchase Return register (VAT books for credit/debit notes), with
  exports; request route `require`s and nav entries from the coordinator.
- [x] 6. Aged Receivables/Payables use `Sale::outstandingAmount()` / `Purchase::outstandingAmount()` (exact,
  no `<= 0.01` filters), plus an "Opening / unallocated" bucket from party ledger balances not explained by
  open invoices. New ledger-based Debtors and Creditors list (every customer/supplier with a non-zero balance
  for the selected fiscal year) with export.
- [x] 7. Stock reports (`StockSummary`, `StockValuation`, category/brand stock, stock movement register):
  values from `StockCosting` (C10), quantities as `Quantity` 4dp, transfers excluded from the combined view,
  item-wise quantity totals never add different units together (group by base unit).
- [x] 8. Payment-mode filter on the Sales and Purchase registers.
- [x] 9. Every report page: BS date column and BS date range labels via `formatBsDate`, `formatMoney` for
  money, no raw `.toFixed`.
- [x] 10. Tests (write, do not run): VAT Summary equals the VAT ledger movement with a capital purchase, a
  cancelled sale (cancelled next month) and a posted return; a rejected return has no VAT effect; the cancelled
  sale shows in the original month as issued and as a negative row in the cancel month; TDS return effect in
  the return's period; Sales VAT book shows buyer PAN and the stored invoice number; Purchase VAT book shows
  the supplier bill number; ageing has no 0.01 residue after exact settlement; stock valuation matches
  `StockCosting`; Excel cells are numeric.
