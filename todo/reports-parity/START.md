# Reports parity, batch 3: sales, purchase and manufacturing reports

Goal: bring the remaining reports of the multi-tenant app up to (and past) the legacy app at
`D:\Projects\day-khata\day_khata`, production ready. Done in earlier batches: Income Statement, Balance Sheet,
Trial Balance, TDS, VAT Summary, Unified VAT Register, all stock reports and the Item Ledger.

Four tracks run in parallel. Each owns a disjoint set of files, so any four workers (this session plus three
others, or four sub-agents) can work at the same time without colliding.

| Track | Task file | Reports |
|---|---|---|
| R01 | `R01-item-wise-sales-purchase.md` | Item-wise Sales, Item-wise Purchase |
| R02 | `R02-category-sales-purchase-and-notes.md` | Sales by Category, Purchase by Category, Sales With Note |
| R03 | `R03-party-wise-sales-purchase.md` | NEW Customer-wise Sales, NEW Supplier-wise Purchase |
| R04 | `R04-potential-profit-and-manufacturing.md` | NEW Stock with Potential Profit, Manufacturing reports pass |
| R05 | `R05-blocked-registers-and-books.md` | **Not startable yet.** Sales/Purchase Register, Day/Cash/Bank Book, Sales/Purchase VAT books |

## How to run a track

1. Read `AGENTS.md`, then `.ai/rules/index.md` and every rule file whose glob covers a file you will edit.
2. Read this file, then your task file. Skim `todo/CONTRACTS.md` (C1 to C3 money, C10 costing).
3. Read the pattern pages named below before writing anything. Copy their structure, do not invent a new one.
4. Audit first: read the legacy controller method and view named in your task, list what legacy shows that the
   new page does not, decide per gap, then build. Keep the new app's exact-decimal basis where it differs from
   legacy (for example weighted-average stock cost); say so in the hand-back.
5. Tick your checkboxes as you finish. Finish with the hand-back below.

## Rules (from AGENTS.md and todo/START.md, restated because they are the ones that bite)

- **Never run** tests, builds, migrations, tinker or any git command that changes state. The user runs tests and
  commits. **Do not write new tests** (standing instruction); review the code by reading it.
- Allowed: `php -l <file>`, `vendor/bin/pint <your files>`, `git status`, `git diff -- <your files>`.
- **Money and quantity** are exact decimal strings via `App\Support\Money\Money` and `Quantity`. No float math,
  `(float)`, `round()`, `number_format`, `toFixed`, `parseFloat`. `toFloat()` only at the Excel cell boundary.
  Vue uses `resources/js/lib/money.js` (`formatMoney`, `formatQuantity`, `formatRate`, `compareMoney`).
- **Shared tree.** Other workers edit other files right now. Edit only your owned files and new files in your
  listed paths. Never reformat or "fix" a file you do not own. Need a change elsewhere? Put it under
  "Cross-file requests" in your hand-back.
- **Coordinator-only files:** `routes/tenant.php`, `routes/*.php` (all of them), `resources/js/lib/nav-items.js`,
  `resources/js/lib/report-catalog.js`, `config/*`, `todo/START.md`. Do not edit them. Instead list the exact
  route lines / catalog entries under "Route requests" in your hand-back. Every tenant route carries
  `->middleware('can:<key>')`; gate exports with the existing `*.export` key (config already has
  `sales_reports.*`, `purchase_reports.*`, `stock_reports.*`, `manufacturing_reports.*`). Do not invent keys.
- **No em dashes** in code comments, UI text or docs. Use commas, colons, full stops or " - ".
- Vue pages stay near 500 lines; split a component out when a page grows past that.

## Lessons from batches 1 and 2 (each one was a real defect)

1. **Export and Print URLs are built from the applied props**, never from unapplied local refs, plus
   `branchFilter.appendTo(params)`. Button is `as="a"` and gated by `can('<key>.export')`. Pattern:
   `UnifiedVatRegister.vue`.
2. **Declare every controller prop in `defineProps`.** An undeclared Inertia prop renders as an HTML attribute
   on the root element. A local `ref` named like a prop shadows `props.x` in the template, so name local state
   `somethingInput`.
3. **Screen and export share one private method** in the controller (`report()`), so they cannot disagree.
4. **Strict validation.** `from` / `to` / `as_of` are `date_format:Y-m-d`, `to` is `after_or_equal:from`, ids use
   `exists:`. A malformed date must give a 422 validation error, never a silent fallback and never a 500.
5. **Filter state that is a ref must follow the applied props** (watch them) and "Clear filters" visibility must
   be reactive, not a one-time `const`.
6. **Drill-down totals equal the parent row.** If a row links to a detail page, the detail total must tie to it
   by construction (same query, narrower predicate), and the page should say so.
7. **Exports are Excel only** for these reports, matching the other books. Numeric cells, 2-decimal money
   number format, a Total row, heading rows (title, period, branch label) above the column headings.
8. **Permission pairing.** An export route needs the report's `*.view` key as well as `*.export`
   (`['can:x.view', 'can:x.export']`) when the report has its own view key; otherwise a user who cannot see the
   page could still download it.
9. **Excel columns:** do not move existing columns without saying so in the hand-back; no test pins cell
   positions, but users' macros might.
10. **Document links:** link a row to its document only where a show route exists (sales, purchases, returns,
    receipts, payments). Do not invent routes.

## Pattern pages to copy

- `app/Http/Controllers/Tenant/Reports/UnifiedVatRegisterController.php`, `app/Exports/UnifiedVatRegisterExport.php`,
  `resources/js/pages/Tenant/Reports/UnifiedVatRegister.vue` (filters, export URL, branch filter, totals).
- `app/Support/Reports/StockItemFilters.php` (shared category / subcategory / brand / search filters). Reuse it
  if your report filters items; do not copy it.
- `app/Http/Controllers/Tenant/Reports/CategoryWiseReportController.php` stock methods and
  `StockByCategoryItems.vue` (drill-down that ties to its parent).
- `app/Http/Controllers/Tenant/Reports/StockMovementRegisterController.php` (screen row cap with a `truncated`
  flag while the export covers every row).
- Branch filter: `App\Support\Branch\BranchFilter`, `BranchStock::storeOptions`, `useBranchReportFilter`,
  `BranchReportFilter.vue`, `BranchReportNotes.vue`. Every report keeps the branch filter it already has.

## Hand-back format (your last message, under 600 words)

1. Per checkbox: what changed.
2. Files created and files modified (explicit list, so the user can commit by file).
3. **Route requests**: exact `Route::get(...)` lines with middleware and names, and which route file they go in.
4. **Catalog / nav requests**: exact entries.
5. Cross-file requests, behaviour changes (stricter validation, moved Excel columns), and anything skipped
   with the reason.
6. Manual checks for the user.

## Status board (coordinator updates)

| Track | Status | Notes |
|---|---|---|
| R01 | built and reviewed | awaiting user tests and commit |
| R02 | built and reviewed | awaiting user tests and commit |
| R03 | built and reviewed | awaiting user tests and commit |
| R04 | built and reviewed | awaiting user tests and commit |
| R05 | blocked | waits for the Ledger and Debtors/Creditors sessions to be committed |
