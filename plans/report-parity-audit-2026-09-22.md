# Report parity audit, 2026-09-22

Requested by the user: verify that every report in this rewrite aligns with the legacy `day_khata` app's
reports, since legacy's reports are battle-tested in real-world use and are the source of truth for *what a
report should show*, not necessarily for *how it's computed* (this rewrite has already fixed several real
legacy defects in report logic - see "Confirmed NOT gaps" below).

## Method

4 parallel read-only research agents, one per report cluster, each comparing the multi-tenant controller
against its counterpart method(s) in legacy's `app/Http/Controllers/reportsController.php` (~70 methods) and
its Blade views, cross-checked against this repo's own `todo/CONTRACTS.md` and `todo/T10-reports-vat-tds.md`
so a deliberate architecture decision is never counted as a gap. No code was changed by the audit itself.

**Headline finding: no wrong-VAT/wrong-tax-total bugs.** One real correctness bug (Trial Balance opening
balance), then a cluster of missing columns/filters/drill-downs, plus several places where this rewrite is
already ahead of legacy and must not be "fixed back" toward it.

## Gaps found, in priority order

### T15-1. Trial Balance mis-buckets Opening Balance into "Period" (HIGH - real correctness bug)

`AccountingReportController::trialBalance()` computes the opening bucket as `date <= dayBefore($from)`. The
Opening Balance voucher for a fiscal year is dated exactly `$from` (the FY start date, see
`FiscalYear::postOpeningBalances()`), and `$from` defaults to the fiscal year's own start date when no
filter is given (`resolveWindow()`). So for the default view of every year after the first, the opening
voucher's lines fall one day outside the opening window and land in "Period" instead - Opening renders
0.00, and Period is inflated by exactly the carried-forward balance. Closing is arithmetically fine; the
Opening/Period split (the entire point of a 3-column trial balance) is wrong.

The Cash/Bank Book's own `accountBook()` (same controller, ~line 1305) already gets this right: it treats
`voucher_type = OpeningBalance` as opening regardless of date, OR `date < from`. Fix `trialBalance()` /
`trialBalanceRows()` (and `trialBalancePdf()` / `trialBalanceExport()`, which likely share the same helper)
to use the same rule.

- Files: `app/Http/Controllers/Tenant/Reports/AccountingReportController.php` (`trialBalance`,
  `trialBalanceRows` or equivalent, ~lines 73-100, 1084-1130)
- Add a test that posts an Opening Balance voucher into year 2, views Trial Balance with no date filter, and
  asserts Opening is nonzero and Period does not include the opening amount - the existing
  `AccountingReportTest` only tests a window *inside* a year, never the default view of a second year.

### T15-2. TDS Report missing `tds_rate` column (MEDIUM)

Legacy's `tdsReport()` and its Blade view show a "TDS Rate" column per row (e.g. "15.00%"), both sales and
purchase sides. `TdsReportController::salesRows()`/`purchaseRows()` only emit `base_total`/`tds_amount`,
never `tds_rate`, even though `Sale`/`Purchase` store it per document. Add `tds_rate` to both row mappers and
a "TDS Rate" column to `TdsReport.vue`, next to TDS Amount, matching legacy's column order.

- Files: `app/Http/Controllers/Tenant/Reports/TdsReportController.php` (~lines 77-186),
  `resources/js/pages/Tenant/Reports/TdsReport.vue`
- Do not touch the return/cancellation netting logic - the signed-row-in-return's-own-period behavior is a
  confirmed, deliberate improvement over legacy (T10 item 2), not something to revert.

### T15-3. VAT Summary doesn't break out input VAT on a fixed-asset line inside an ordinary Purchase (MEDIUM)

Distinct from `CapitalPurchase` (a whole separate capital document, already correctly split out per T10 item
3). This is a fixed asset bought as one line inside an otherwise-ordinary `Purchase` (routed via
`item.account_id`), whose VAT currently lands undifferentiated inside the "gross" bucket. The report total
is still correct; it's a usability/compliance-support gap for VAT filing prep, not a wrong number.

- Files: `app/Http/Controllers/Tenant/Reports/SalesPurchaseReportController.php`
  (`purchaseVatRow()`, ~lines 489-509), `VatSummaryReportController.php` (`side()`, ~lines 142-158)
- Add a per-line split of `vat_amount` for lines whose `item.account_id` is a fixed-asset account, surfaced
  as a fourth bucket (`fixed_asset_vat_amount`) alongside `gross`/`capital`, in both the VAT book and the VAT
  Summary.

### T15-4. Trial Balance / Balance Sheet hide zero-balance and brand-new accounts (MEDIUM)

Legacy shows every ledger account by default (a `LEFT JOIN` starting from the full chart of accounts), only
hiding zero-balance rows behind an explicit "Hide Zero" toggle. `trialBalanceRows()` and `accountRows()`
(used by Balance Sheet) only ever iterate accounts that already have a nonzero balance, `continue`-ing past
anything at zero - so a newly created ledger account with no postings yet, or an account someone wants to
confirm is genuinely at zero, cannot be seen on either report.

- Files: `app/Http/Controllers/Tenant/Reports/AccountingReportController.php` (`trialBalanceRows`
  ~995-1031, `accountRows` ~1084-1130)
- Enumerate from the `accounts` table (scoped to relevant heads/groups) instead of from the balances map;
  default to "show all" matching legacy, optionally add a hide-zero toggle if the product wants a leaner
  default later.

### T15-5. Trial Balance has no single-account filter (MEDIUM)

Legacy's `trailbalance()` accepts `?accno=` to narrow the whole report to one ledger. No equivalent exists
in `AccountingReportController::trialBalance()`.

- File: `app/Http/Controllers/Tenant/Reports/AccountingReportController.php`
- Add an optional `account_id` filter narrowing rows and the report heading, mirroring legacy's behavior.

### T15-6. Item-wise Sales/Purchase reports lost per-transaction drill-down (MEDIUM)

Legacy's `viewSalesReportItemWise()`/`viewPurchaseReportItemWise()` require a specific item and return one
row per billing line (rate, invoice/bill number, party name, vatable flag), paginated - a per-item
transaction ledger. `ItemWiseSalesReportController`/`ItemWisePurchaseReportController` have no `item_id`
filter at all and only ever produce one aggregated row per item across the whole range (quantity, value,
transaction count) - no rate, no party, no per-line detail. A shopkeeper asking "show me every sale of item
X, at what rate, to which customer" has no way to get that from either report today; `StockMovementRegister`
has an item filter but shows cost, not sale/purchase rate.

- Files: `app/Http/Controllers/Tenant/Reports/ItemWiseSalesReportController.php`,
  `ItemWisePurchaseReportController.php`, matching Vue pages
- Add an optional `item_id` filter; when set, also return the raw per-line rows (date, document number,
  party, rate, quantity) alongside the aggregate.

### T15-7. Purchase Register excludes capital purchases from its total (MEDIUM - confirm intent, don't just "fix")

Legacy's `purchaseRegularReport()` has no `purchaseType='capital'` exclusion, so a capital purchase recorded
as a `purchase_records` row is included in legacy's Purchase Register total. Multi-tenant's `purchaseRegister()`
queries only `Purchase::query()`; `CapitalPurchase` never appears there (only in the Purchase VAT Book). This
may be legacy's own oversight rather than intended behavior - **needs a product decision before coding**:
either (a) union `CapitalPurchase` rows into the register with a `capital` flag column, mirroring the VAT
book's pattern, or (b) explicitly document the register as trading-purchases-only and point users to the VAT
book/capital workflow for those.

- File: `app/Http/Controllers/Tenant/Reports/SalesPurchaseReportController.php` (`purchaseRegister()`,
  ~lines 117-163)
- **Action before implementing:** ask the user which behavior they want; this is a scope-boundary decision,
  not an obvious bug.

### T15-8. Debtors/Creditors sign-convention and population difference vs legacy (MEDIUM - likely correct, needs visibility)

Legacy's `debtors()`/`creditors()` use `HAVING SUM(...) > 0` (strictly positive only) and scope by ledger
subgroup tag (`Sundry Debtors`/`Sundry Creditors`), so an overpaid customer (credit balance) is invisible in
legacy, and a hand-tagged ledger account not linked to a real `Customer`/`Supplier` row would show in legacy
but not in multi-tenant. Multi-tenant's `partyBalancePayload()` scopes by `Customer`/`Supplier` FK and
includes negative (credit) balances. This is very likely multi-tenant behaving more correctly (an
overpayment is real money owed back), but the silent difference will look like an unexplained discrepancy to
someone reconciling against what they remember from legacy.

- File: `app/Http/Controllers/Tenant/Reports/SalesPurchaseReportController.php` (`partyBalancePayload()`
  area, ~lines 817-826)
- Keep current behavior; add a "Credit balance" badge/flag on negative rows and a one-line note on the report
  explaining the sign convention, so the difference from legacy is visible and understood rather than
  silent. Do not revert to legacy's silent-drop behavior.

### T15-9. Stock Valuation lost the positive/negative/all stock-status filter (MEDIUM, ties to an already-tracked open item)

Legacy's `stockValuationReport()` supports `stock_status = positive|negative|all` via a `HAVING` clause,
commonly used to hunt down negative-stock data-entry errors. No equivalent filter exists in
`StockValuationReportController`/`StockCosting::valuationRows()`. This connects to the still-open "negative
quantity" item already tracked in the legacy migration flag system (item #5) - the one legacy report built
specifically to surface that class of bug isn't reproduced here.

- Files: `app/Http/Controllers/Tenant/Reports/StockValuationReportController.php`,
  `app/Support/Inventory/StockCosting.php` (`valuationRows()`, ~line 145)
- Add a `stock_status` filter (`positive`/`negative`/`all`), filtering by `Quantity::isPositive()`/
  `isNegative()`.

### T15-10. Print Log: 3 stock document types don't call `PrintLog::record` (LOW-MEDIUM, internal contract gap, not legacy-parity)

Not a legacy-parity issue (legacy never printed or logged these types at all - multi-tenant's `PrintLog` is
already a strict superset of legacy's sales-only `invoiceprintlist()`). It is a real gap against this repo's
own C9 contract: `StockAdjustmentController::print()`, `StockConversionController::print()`, and
`StockTransferController::print()` have print routes/PDF views but never call `PrintLog::record`.

- Files: `app/Http/Controllers/Tenant/Inventory/StockAdjustmentController.php` (~line 152),
  `StockConversionController.php` (~line 101), `StockTransferController.php` (~line 93)
- Add `PrintLog::record($document, $request->user())` to each, matching the pattern in `SaleController::print()`.

### T15-11. Category/Brand reports lost legacy's "click a group to see its lines" drill-down (LOW)

Legacy has single-group detail endpoints (`singleitemgroupwisesales`, `singleCompanywisestock`, etc.)
returning raw line-level rows for one category/subcategory/brand. `CategoryWiseReportController`/
`BrandWiseReportController` only ever return aggregated totals, with no way to pivot into a group's lines.

- Files: `app/Http/Controllers/Tenant/Reports/CategoryWiseReportController.php`,
  `BrandWiseReportController.php`, `ItemWiseSalesReportController.php`, `ItemWisePurchaseReportController.php`,
  `StockMovementRegisterController.php`
- Lower priority: add `category_id`/`subcategory_id`/`brand_id` filters to the item-wise/movement reports so
  a user can pivot from a group total into its lines, or link each category/brand row out to those reports
  pre-filtered.

### T15-12. Stock Valuation dropped the `hs_code` column (LOW)

Legacy's `stockValuationReport()` selects `hs_code` (customs/HS code) alongside item name/qty/rate.
`StockCosting::valuationRows()` only selects `['id', 'name', 'unit', 'purchase_rate']`.

- File: `app/Support/Inventory/StockCosting.php` (`valuationRows()`, ~line 145)
- Add `hs_code` to the column list and thread it through to the controller row mapping and the Vue table.

### T15-13. Sales/Purchase Register shows cancelled rows in the list but excludes them from the footer total (LOW)

Legacy's registers filter `cancel=0` - a cancelled bill never appears at all. Multi-tenant's `salesRegister()`/
`purchaseRegister()` list every row including cancelled ones (with a status badge) at original amount, while
`totalsFor()` sums only the posted subset. Not a wrong number (the footer total is correct), but a real
reconciliation-safety risk if someone exports the visible table and sums it in Excel without noticing the
status column.

- File: `app/Http/Controllers/Tenant/Reports/SalesPurchaseReportController.php`
  (`salesRegister()`/`purchaseRegister()`, ~lines 77-163), matching Vue pages
- Add a visible strike-through/greyed style plus a footnote on cancelled rows in the Vue table, or filter
  them out of the listing entirely to match legacy 1:1 (cancellation already has its own visibility via the
  VAT book's signed rows and the document's own status page) - product call, lean toward the visible-badge
  fix since it's cheaper and keeps more information on screen.

### T15-14. Cancelled Documents report has no date-range or party filter (LOW)

Legacy's two narrower source views (`listcanceledoutstock`, `listinstockcancelrecord`) both had date-range
and party filters with pagination; the new unified `cancelledDocuments()` takes no request parameters and
returns everything, paginated only client-side. Fine at current volume; will degrade as history grows.

- File: `app/Http/Controllers/Tenant/Reports/AccountingReportController.php`
  (`cancelledDocuments()`, ~lines 677-682)
- Add optional `from`/`to` and party filters when cancellation volume grows; not urgent today.

### Not a gap: Sales Agent Commission report (LOW, do not build proactively)

Legacy's `listsalesagentcharges()` has no multi-tenant equivalent, but commission posts as a real ledger
entry against `Agent::account_id`, fully visible and auditable via the existing generic Account Ledger
report - a deliberate dedup decision already recorded in `mem.md`'s 2026-08-29 entry. Only build a
purpose-built summary report if a real user complaint surfaces, per `goal.md`'s own "don't pick up more
reports speculatively" guidance.

## Confirmed NOT gaps (deliberate, already-correct improvements over legacy - do not "fix" these back)

- **Cancellation shows as a signed reversal row in its own period**, not a blanket exclusion from history
  (C5/C6, T10 item 2) - across VAT books, TDS report, and the ledger generally. Legacy's "delete from a
  filed month" behavior is the actual defect this replaced.
- **Capital sales/purchases folded into the VAT books** with their own column (T10 item 3) - legacy's Sales
  VAT book excluded capital sales entirely and its Purchase VAT book only partially included capital
  purchases; this is a documented fix, not a divergence.
- **Aged Receivables/Aged Payables are net-new** - legacy has no aging-bucket report at all;
  `listoutstock()`/`listinstock()` turned out on inspection to just be the plain Sales List/Purchase List
  pages. Nothing to port; treat as a shipped MVP feature, not an audit target.
- **Debtors/Creditors scoped to one fiscal year**, not summed across all time - legacy's all-time sum
  double-counts every party after the first year-end close, since the next year's Opening Balance voucher
  restates the same closing balance. Confirmed correct fix (P0-18).
- **Weighted-average stock costing (C10)** - legacy's "valuation" was actually `qty x a manually-typed
  buyRate field` on the item master, never updated from real purchase transactions; not a real costing method
  to begin with. Multi-tenant's `StockCosting` is strictly more correct, not a different business rule.
- **Brand vs Company terminology** - same underlying concept (`brand_id`/`company_id`), confirmed via schema
  and controller docblocks; no data-loss risk since this is a fresh SaaS, not a legacy-data migration tool.
- **Damage/Lost Stock semantics** - same two reason types (`damage`, `lost`), same "out" semantics as legacy.
- **Day Book / Cash Book / Bank Book are genuinely new reports** - legacy's closest analogues were
  autocomplete endpoints for a payment form and two generic ledger views, not dedicated books. Bank Book's
  inability to auto-detect which account is a bank account (no `is_bank` flag) is an accepted, already-
  documented limitation, not an oversight.
- **Cancelled Documents is a deliberately wider, unified report** across 8 document types, vs. legacy's two
  narrow single-purpose views (cancelled sales only, cancelled purchases only).
- **Payment-mode filter, store filter, quantity-per-base-unit grouping (T10 item 7)** - all confirmed-correct
  additive features with no legacy equivalent to diverge from.

## Recommended execution order

1. T15-1 alone first (real correctness bug, isolated to one report, worth its own fix + test before anything
   else).
2. T15-2, T15-3, T15-9 together (all MEDIUM, all "add a column/filter that already has the underlying data
   stored," low risk, no product decision needed).
3. T15-4, T15-5, T15-6 together (all MEDIUM, Trial Balance/Balance Sheet/Item-wise reports, moderate size).
4. T15-7 and T15-8 need a product decision from the user first (see each item) before any code is written.
5. T15-10 through T15-14 (LOW) as a cleanup batch whenever convenient - none are urgent.

Not included in any phase: nothing in "Confirmed NOT gaps" should be touched.
