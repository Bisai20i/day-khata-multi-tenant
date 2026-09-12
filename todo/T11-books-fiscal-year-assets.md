# T11 Books, fiscal year, fixed assets

Phase 3 | Parallel with T10 | Consumes C1, C4, C10 | Audit refs: P0-17 (closing stock in the books), P0-18
(Cash/Bank Book and dashboard double count), P0-19 (reopened year Balance Sheet), P1 archiver failure,
depreciation atomicity and proration, year-close safeguards, FY close race, reopen after relock, BS dates.

This is the accounting heart of the statements. Keep inventory **periodic** (C10): stock enters the books
through opening/closing stock entries, not per movement.

## Owned files

`app/Http/Controllers/Tenant/Reports/AccountingReportController.php`, `routes/tenant-reports-accounting.php`,
`app/Http/Controllers/Tenant/DashboardController.php`, `resources/js/pages/Tenant/Dashboard.vue`,
`app/Models/FiscalYear.php`, `app/Models/FiscalYearArchive.php`,
`app/Http/Controllers/Tenant/Accounting/FiscalYearController.php`,
`app/Http/Controllers/Tenant/Accounting/FiscalYearArchiveController.php`,
`app/Http/Controllers/Tenant/Accounting/ArchivedFiscalYearController.php`,
`app/Support/FiscalYear/FiscalYearArchiver.php`, `app/Console/Commands/AutoStartFiscalYear.php`,
`app/Models/FixedAsset.php`, `app/Models/FixedAssetDepreciation.php`,
`app/Http/Controllers/Tenant/Assets/FixedAssetController.php`, `routes/tenant-fixed-assets.php`,
`routes/tenant-fiscal-year-archive.php`, `database/seeders/Tenant/ChartOfAccountsSeeder.php`, pages
`resources/js/pages/Tenant/Reports/{TrialBalance,IncomeStatement,BalanceSheet,CashBook,BankBook,DayBook}.vue`,
`resources/js/pages/Tenant/Accounting/FiscalYears/Index.vue`, `FiscalYearArchive/*.vue`,
`resources/js/pages/Tenant/Assets/FixedAssets/*.vue`, tests `tests/Feature/Tenant/Reports/AccountingReportTest.php`,
`tests/Feature/Tenant/Accounting/FiscalYear*.php` (except the one assertion T03 already changed in
`FiscalYearReopenTest.php`: keep it), `ArchivedFiscalYearBrowsingTest.php`, `tests/Feature/Tenant/Assets/*`,
`tests/Feature/Tenant/DashboardTest.php`, `tests/Feature/Console/AutoStartFiscalYearTest.php`, new tests.
Migrations with prefix `2026_09_13_11`.

## Tasks

- [x] 1. Stock accounts: in `ChartOfAccountsSeeder` rename `AS11` to "Stock in Hand" (asset, balance sheet)
  and add two P&L accounts following the seeder's code conventions (pick unused codes, document them):
  "Opening Stock" (expense side, trading) and "Closing Stock" (income side, trading). Add an idempotent tenant
  data migration that creates them for existing tenants and renames AS11.
- [x] 2. `FiscalYear::close()`: before the P&L sweep, (a) move the year's opening stock (AS11 balance at the
  start of the year) to "Opening Stock" (Dr Opening Stock / Cr AS11), (b) post closing stock
  `StockCosting::totalClosingValue(end_date)` (Dr AS11 / Cr Closing Stock). Both as closing-type vouchers
  inside the existing close transaction, so the sweep then produces the right profit and AS11 carries the
  closing value forward via the opening balances. Lock the fiscal year row and re-check status inside the
  transaction (no double close). All amounts `Money`, exact.
- [x] 3. Statements for a year that is not closed yet: Income Statement shows Opening Stock (AS11 balance at
  year start), Purchases, less Closing Stock computed as of the report date, giving gross profit; the Balance
  Sheet shows Stock in Hand at the computed closing value and includes the same stock effect in current
  earnings, so it balances. Closed years show the posted entries. Assert Assets = Liabilities + Capital in
  code (throw in tests, show a visible warning row in production if ever not equal).
- [x] 4. Reopened-year corrections (P0-19): after any correction posted into a reopened year, the Balance
  Sheet of that year must still balance. Compute unswept P&L for every year (not only when status is Open),
  and on relock post a supplementary closing entry for the corrections' P&L effect. `reopen()` clears
  `relocked_at`. `close()` refuses a "next" year that starts before this one ends. Roll-forward never writes
  into an archived year: refuse the correction with a clear message naming the archived year (or re-archive it
  afterwards if the archiver supports that cleanly; document the choice).
- [x] 5. Cash Book, Bank Book, Day Book and dashboard balances (P0-18): opening balance = the selected
  year's Opening Balance voucher lines + that year's lines before `from`; never sum across years. Date
  ranges are limited to one fiscal year (the page picks the year, then an optional date window inside it).
  Running balances in `Money`, no `-0.00`.
- [x] 6. Trial Balance, Income Statement and Balance Sheet accept an optional date window inside the chosen
  year; Trial Balance shows opening, period debit, period credit and closing columns plus a balance check.
  Financial statements, Day/Cash/Bank Book routes get `role:admin`. Date filters inclusive on both ends on
  SQLite and MySQL.
- [x] 7. Year-close safeguards: refuse closing before `end_date` unless an admin gives a reason; create a
  backup first using the existing backup mechanism (read `BackupController`/`Backup` model; call it, do not
  edit it; send a cross-file request if it lacks a callable entry point). Close routes admin-only.
  `AutoStartFiscalYear` runs the same close (with its own reason text) and must stay idempotent.
- [x] 8. `FiscalYearArchiver`: archive tables accept accounts without `account_code` (party and asset
  accounts); archive SUMs read as strings.
- [x] 9. Fixed assets: depreciation run and disposal inside one transaction with a lock on the asset row
  (no orphan voucher on double click); depreciation prorated by days held in the year for assets bought
  mid-year (document the Nepal pool rule you apply, WDV by pool rate); never below salvage; disposal-year
  depreciation up to the disposal date; all money exact; admin-only routes.
- [x] 10. Dashboard: all KPIs via `Money` strings and fiscal-year-scoped balances; add a low-stock widget
  (items at or below `min_stock` using `Item::currentStockByItem()`); `formatMoney` on the page.
- [x] 11. Every owned page: BS dates via `formatBsDate`, `formatMoney`, no raw `.toFixed`.
- [x] 12. Tests (write, do not run): close a year with stock on hand, then the next year's Balance Sheet
  shows Stock in Hand = closing value and balances; gross profit includes the stock change; Cash Book in year
  two opens at the year-one closing cash (not double); correction into a reopened year keeps its Balance
  Sheet balanced after relock; double close rejected; archive a year with supplier postings succeeds;
  depreciation proration and double-run protection; low-stock widget.

## Deviations and notes (T11, 2026-09-12)

1. **Task 7, blocking cross-file request.** The pre-close backup is taken in
   `FiscalYearController::close()` and `AutoStartFiscalYear`, not in
   `FiscalYear::close()`, so model-level tests stay pure. Both call
   `BackupController::performBackup()`, which is currently `private`. Until it
   is made `public`, the HTTP close route and the cron refuse to close with a
   clear message, and `AutoStartFiscalYearTest` fails.
2. **Task 2 detail.** The two trading vouchers post as `VoucherType::ClosingEntry`
   as specified. Trial Balance and Income Statement therefore no longer exclude
   ClosingEntry by type; they exclude the P&L sweep, identified as "a
   ClosingEntry voucher with no Stock in Hand line". By construction the sweep
   only touches P&L accounts plus CA2, and each stock voucher always pairs a
   stock account with AS11.
3. **Task 9 rule.** Depreciation is prorated by days held inside the fiscal
   year (the finer-grained form of Nepal's statutory trimester absorption
   rule), never below salvage, and charged up to the disposal date on disposal.
