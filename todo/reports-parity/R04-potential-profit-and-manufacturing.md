# R04 Stock with Potential Profit (new) and Manufacturing reports pass

Read `todo/reports-parity/START.md` first. It holds the rules, lessons and hand-back format.

## Part A: Stock Report with Potential Profit (new)

Legacy: `reportsController::newStockReportSearch` / `stockValuationReport`, views `reports/stock/new_viewreports`,
`viewreports`, `stock_valuation`. The legacy report lists each item with quantity, cost rate, sell rate, stock
value at cost, stock value at sell rate, and the potential profit (sell value minus cost value).

### Owned files (NEW)

- `app/Http/Controllers/Tenant/Reports/StockPotentialProfitReportController.php`
- `app/Exports/StockPotentialProfitExport.php`
- `resources/js/pages/Tenant/Reports/StockPotentialProfit.vue`

Reuse `App\Support\Reports\StockItemFilters` and `StockCosting::valuationRows()` (read how
`StockValuationReportController::report()` builds rows and copy the approach). Do not edit the stock valuation
files or any `routes/*.php` file.

### Decisions to keep

- Cost is the new app's weighted-average basis from `StockCosting`, which reconciles to the Balance Sheet.
  Legacy used the latest buy rate; do not switch. State this in the page's help text.
- Sell rate is the item's current selling rate. Items with no sell rate show a dash and are excluded from the
  sell-value total, with a count of excluded items shown above the table. Do not treat a missing rate as zero.
- Sell value and profit are exact `Money` arithmetic (quantity times rate), then rounded once with
  `Money::round()`.

### Tasks A

- [x] A1. Controller with `index` and `export`, one private `report()` shared by both. Strict validation
  (`as_of` `Y-m-d`, `store_id` `exists`, item filters via `StockItemFilters::rules()`), branch filter, an
  "include zero-stock items" toggle like `StockValuation`.
- [x] A2. Columns: item, unit, quantity, cost rate, sell rate, stock value at cost, stock value at sell, potential
  profit, margin percent (exact string, 2 decimals, dash when sell value is zero). Totals row. Sort by profit
  descending by default.
- [x] A3. Vue page and Excel export, gated by `stock_reports.view` / `stock_reports.export`. Cost total must
  equal the Stock Valuation total for the same filters; say so in the help text.
- [x] A4. Route and catalog requests in the hand-back (`/stock-potential-profit`, `/stock-potential-profit/export`,
  new route file `routes/tenant-reports-stock-potential-profit.php`, catalog entry in the Stock group).

## Part B: Manufacturing reports pass

Files: `app/Http/Controllers/Tenant/Reports/ManufacturingReportController.php`,
`app/Support/Manufacturing/ManufacturingReports.php` (the class the controller injects),
`app/Exports/Manufacturing/ManufacturingReportExport.php`, `resources/js/pages/Tenant/Reports/Manufacturing/*`.
There is no legacy equivalent beyond the production and refining views; this part is a quality pass, not a port.
Spec: `todo/manufacturing/M07-manufacturing-reports.md`.

### Tasks B

- [x] B1. Audit against M07 and list what is missing or inconsistent. If nothing material is missing, say so and
  stop Part B; do not invent work.
- [x] B2. Strict validation: `from` / `to` are `date_format:Y-m-d` (currently the plain `date` rule with a silent
  fiscal-year default; keep the default when the param is absent, reject a malformed one).
- [x] B3. Parity with the other books: Excel heading rows (title, period, branch label), Total rows, numeric
  cells. The reconciliation report should show a clear "reconciles" or "difference" state.
- [x] B4. Links from rows to the production entry or material issue note where a show route exists.
- [x] B5. Hand-back lists any route or catalog requests.
