# Day Khata — Multi-Tenant Rewrite: Memory

Living state doc. Read this before starting work, update it before stopping. See `goal.md` for
direction/roadmap — this file is "what exists and why," not "what's next."

**Last updated:** 2026-09-02. **Git status: initialized**, 15 commits on `main`, plus a substantial
**uncommitted** working tree (see below) as of this update. No remote configured yet — this protects
against a bad `git clean`/`reset`/`checkout`, not disk loss.

**2026-09-02 session: verified, not built, a prior session's uncommitted Fixed Assets / Quotations /
Employee-management pass — found it essentially complete.** This session started from a fresh context
with a large uncommitted working tree already on disk (commit `2a78704` "Fix tenant-guard ambiguity in
root route; prep for Fixed Assets/Quotations/Employee-mgmt" had landed the route/nav scaffolding; the
actual feature build happened after that commit but was never committed or written up here). Rather than
assume it was unfinished, this session did a full read-through of every touched/new file — models,
controllers, migrations, routes, Vue pages, tests — before writing anything. **The user explicitly asked
not to run `php artisan test`/`npm run build` this session** (they run those themselves), so this was a
static-reasoning review only, same caveat as every "not yet re-verified" note elsewhere in this file —
**ask the user to run the suite/build before trusting this is green**, then commit.
- **Fixed Assets** (`app/Models/{FixedAsset,FixedAssetDepreciation}.php`,
  `App\Http\Controllers\Tenant\Assets\FixedAssetController`, migrations `2026_08_30_100100`/`_100101`,
  `Tenant/Assets/FixedAssets/{Index,Create}.vue`, `tests/Feature/Tenant/Assets/FixedAssetTest.php` — 8
  tests): each asset gets its own ledger `Account` under the `"Fixed Assets"` group (no subgroup, matches
  that group having none), posts through the same `JournalVoucher::post()`/`write()` engine as everything
  else. Three new `VoucherType` cases (`FixedAssetPurchase`/`Depreciation`/`AssetDisposal`) and 4 new seeded
  accounts (`AS31` Accumulated Depreciation, `EXE20` Depreciation Expense, `EXE21` Loss on Disposal, `INI30`
  Gain on Disposal — added to `ChartOfAccountsSeeder`, all under pre-existing groups). Supports SLM (flat %
  of depreciable base) and WDV (% of opening WDV) methods against 5 statutory Nepali depreciation pools
  (`App\Enums\DepreciationPool`, Pool A-D have fixed default rates, Pool E has none — amortised over useful
  life instead). `dispose()`'s gain/loss math hand-verified algebraically (debits always equal credits for
  gain/loss/break-even cases: `diff = proceeds + accumulated - cost`, and each branch's line set reduces to
  exactly `cost` on both sides) and the test file covers all three cases numerically too.
  - **`FiscalYear::close()` now posts every active asset's depreciation before the P&L sweep** (`FixedAsset::
    postDepreciationForFiscalYear()` called first inside `close()`'s transaction) — correct ordering, since
    depreciation must reduce the year's profit before that profit is swept to "Profit & Loss". A
    `fixed_asset_depreciations` unique constraint on `(fixed_asset_id, fiscal_year_id)` is the "already posted
    this year" guard, checked explicitly rather than relying on the DB to reject a duplicate mid-transaction.
  - Manual "Post Depreciation" admin action exists too (`role:admin`-gated route, mirrors legacy's
    superadmin-gated equivalent) for posting ahead of year-end if ever needed — same underlying method.
- **Quotations** (`app/Models/{Quotation,QuotationLine}.php`, `App\Http\Controllers\Tenant\Sales\
  QuotationController`, migrations `2026_08_30_100300`/`_100301`, `Tenant/Quotations/{Index,Create}.vue`,
  `tests/Feature/Tenant/Sales/QuotationTest.php` — 5 tests): a quotation never touches the ledger or stock —
  draft/converted/cancelled lifecycle only (`App\Enums\QuotationStatus`). Consolidates legacy's separate,
  behaviorally-identical "Order" module into this one concept (deliberate, not an oversight). `convertToSale()`
  hands off entirely to the existing `Sale::post()` (always as a `credit`/`full`-invoice sale, since a
  quotation never captures a real payment method) rather than reimplementing any sale logic — only a draft
  quotation with ≥1 line can convert, and a converted/cancelled quotation is immutable afterward (verified via
  both model-level and HTTP-level tests, including the HTTP update/delete-on-a-converted-quotation rejection
  path).
- **Employee/user management** (`App\Http\Controllers\Tenant\Admin\UserController`, `Tenant/Admin/Users.vue`
  rewritten from a placeholder page into a real CRUD-minus-delete UI, `tests/Feature/Tenant/Admin/
  UserManagementTest.php` — 6 tests): resolves the phase-plan's previously-open "no owning phase for employee/
  privilege management" item. `users` gained `is_active` (migration `2026_08_30_100300_add_is_active_to_users_
  table`, defaults `true`) — deactivation, not deletion, is the only lifecycle action, since every
  `created_by` FK in this app (`journal_vouchers`, `sales`, `purchases`, ...) is `restrictOnDelete()` so a user
  who ever posted anything can never be hard-deleted anyway (same reasoning already applied to Customer/
  Supplier). `AuthenticatedSessionController::store()` now rejects an inactive employee's login with the exact
  same generic `auth.failed` message a wrong password gets (no distinct message, so a deactivated account's
  status can't be probed from the login form). `UserController::guardLastActiveAdmin()` blocks demoting or
  deactivating the sole remaining active admin — this tenant has no platform-admin impersonation or
  password-reset flow, so that would permanently lock the tenant out of its own admin tooling.
- **A real, already-fixed bug this pass's prep commit (`2a78704`) caught and fixed**: `routes/tenant.php`'s
  root route resolved `$request->user()` via the ambiguous default auth guard, which can be temporarily
  `Auth::shouldUse('platform')`-switched elsewhere in the same worker/process — a tenant root-route hit right
  after that could incorrectly treat a platform admin's session as a tenant user's and redirect to the tenant
  dashboard. Caught by `TenantSuspensionTest` failing after a prior session's redirect-based root-route change.
  Fixed by explicitly resolving `$request->user('web')` everywhere a tenant route checks the current user —
  this same explicit-guard pattern was then also applied to `EnsureUserHasRole` middleware in this pass's own
  uncommitted diff, for the identical reason (defense in depth, not a second instance of the bug actually
  firing).
- **Route/nav file-ownership convention followed as usual** (mem.md gotcha #5): `2a78704` pre-split
  `routes/tenant-{fixed-assets,quotations,employees}.php` and pre-added all 3 nav entries before the actual
  feature build, so whichever session/agent(s) built the three features never risked a route-boot race or a
  shared-file conflict. (Unclear from the diff alone whether the actual build was one session or parallel
  forks — no fork-coordination notes were left in this uncommitted work the way prior multi-fork passes
  documented themselves in this file. Worth asking the user, or just noting for next time: leave a mem.md
  entry immediately after a build pass, even if committing is deferred — this session had to reconstruct the
  full picture from raw `git diff`/file reads instead of a written record.)
- **Not yet done**: not committed (working tree still dirty as of this update — ask before committing), not
  re-verified against the test suite/`npm run build` by this session (user's explicit instruction not to run
  them here), no browser smoke-test of the 3 new pages. `goal.md` does not mention Fixed Assets/Quotations/
  Employee-management anywhere — this was scoped and built outside that roadmap doc, so `goal.md` may be
  worth updating too once this is confirmed working and committed.

**2026-08-29, fourth session: first-ever HTTP-level smoke test of the whole app, found a real
year-end-closing bug tests could never have caught.** No browser automation tool is available in this
session (Laravel Boost MCP failed to connect; no Playwright/browser MCP configured), so per user
choice this was a curl-driven HTTP walkthrough against the real running dev server instead of an
actual browser click-through — a real but strictly weaker substitute (catches server-side/data bugs,
not JS/console/visual ones). Delegated to a single fork (not parallelized — the golden path is
inherently sequential: fiscal year before vouchers, customer/item before a sale, etc.).

- **Environment setup, by the coordinator before forking**: reset both `admin@example.com` (platform)
  and `admin@acme.localhost` (tenant, id `c1ba1318-ce0f-4afa-a9e8-4dd57431c227`) passwords to a known
  value for testing (both had no 2FA enrolled, so no TOTP flow blocked login) and ran
  `php artisan tenants:migrate` — this discovered the acme/test tenants' DBs predated several
  migrations from later sessions and needed catching up before they were usable at all. **If a future
  session needs to manually test against these dev tenants, always run `tenants:migrate` first** — an
  existing dev tenant's schema silently lagging behind `database/migrations/tenant` is exactly the
  scenario that caused the bug below.
- **The golden path walked, all passing** (central: login/tenant-list/tenant-detail/create-tenant-via-
  real-provisioning-flow/delete-tenant, both cleaned up afterward; tenant: login → chart of accounts →
  fiscal year → customer/supplier/items → journal voucher → sale → purchase → stock adjustment →
  partial sales+purchase returns with refund vouchers → cancel a return → 13 sampled reports → close
  fiscal year → closed-year correction voucher → roll-forward → logout). Every one of the 8 vouchers
  posted during the walkthrough was independently confirmed balanced; VAT Summary and TDS Report were
  hand-verified against manual calculation and matched exactly.
- **Real, pre-existing bug found by the walkthrough, not by any Pest test — and structurally
  invisible to Pest**: `FiscalYear::close()` silently does nothing (no `ClosingEntry` posted, no net
  profit swept into "Profit & Loss") on any tenant provisioned before
  `2026_08_25_100009_add_is_profit_and_loss_to_account_heads_table` — that migration added the
  `is_profit_and_loss` column with `default(false)` and **no backfill**, so a pre-existing tenant's
  "Income"/"Expenses" heads are stuck at `false` forever, and `postClosingEntries()` finds nothing
  flagged to sweep. The closed year's Balance Sheet then goes silently unbalanced (caught via a real
  50-unit Assets-vs-Liabilities+Capital discrepancy on the acme dev tenant after closing a year with
  real activity). **`RefreshDatabase`-based tests can never reproduce this**: every test tenant is
  provisioned fresh against current migrations/seeders, so it always gets the correct flag — this bug
  only bites a tenant that already existed before a later migration/seeder fix landed, which is
  exactly what a real running dev/production tenant is and a test factory never is. This is the
  concrete argument for why "tests green" and "smoke-tested against real, aged data" are genuinely
  different bars, not redundant ones.
  - **Fix**: new data-only migration
    `2026_08_29_100200_backfill_is_profit_and_loss_on_account_heads_table.php` — flips
    `is_profit_and_loss=true` for `AccountHead` rows named "Income"/"Expenses" wherever still `false`
    (exactly the two names `ChartOfAccountsSeeder` itself flags, confirmed by reading the seeder, not
    guessed). Deliberately irreversible (`down()` is a no-op with a docblock explaining why: there's no
    way to tell a head this migration flipped from one that was already correctly `true`). Applied via
    `tenants:migrate` to both real dev tenants (acme/test) — confirmed via tinker both now read
    `{"Income":true,"Expenses":true}`.
  - **Test**: `tests/Feature/Tenant/Accounting/FiscalYearClosingTest.php` gained `'a tenant with stale
    is_profit_and_loss flags gets backfilled and closes correctly'` — since `RefreshDatabase` can't
    naturally produce the stale state, the test manually corrupts the flags back to `false` right after
    provisioning, then directly `require`s and invokes the migration's `up()`, then proves both the
    flag fix and that a subsequent `close()` now posts a real `ClosingEntry` with the correct
    net-profit sweep. Full suite after merge: **185/185 tests, 1713 assertions** (up from 184/1709).
  - **Residual, not fixed, dev-only**: the acme dev tenant's FY2026 was already closed in its broken
    state before the fix (from earlier ad-hoc testing) and can't be re-closed through the normal API —
    its Balance Sheet stays unbalanced. Local dev data only, not production; left as-is rather than
    hacked around via tinker.
- **A stale-documentation bug also caught and fixed**: `goal.md`'s "Explicit non-goals" section still
  claimed "Async/queued tenant provisioning — currently synchronous (`shouldBeQueued(false)`)" — this
  directly contradicted `goal.md`'s own item 7 two sections above ("queued (not synchronous) tenant
  provisioning... Built 2026-08-26") and the actual code
  (`TenancyServiceProvider.php`: `shouldBeQueued(true)` on both `TenantCreated`/`TenantDeleted`
  pipelines, confirmed by reading it directly). The walkthrough's create-tenant-via-real-HTTP-flow step
  surfaced this by needing a running queue worker to see provisioning complete. Removed the stale
  non-goal line entirely (queued provisioning is done, not deferred) rather than leaving a
  self-contradicting doc.
- **Aside, unrelated to correctness, not acted on**: `database/` has ~1160 orphaned
  `tenant<uuid>.sqlite` files left behind by past test runs whose tenant DB file was never cleaned up
  (a `RefreshDatabase`/tenant-testing gotcha — the physical file outlives the test). Pure disk clutter,
  not a bug affecting the app; flagged to the user, not touched without explicit sign-off.

**2026-08-29, third session: VAT Summary + Stock Movement Register, via 2 parallel forks.** After the
5-report batch below, the user asked for more legacy reports again — rather than build the whole
remaining ~30-method list speculatively, this session first read every remaining legacy
`reportsController.php` method and dedup'd them: most are either filter-variant duplicates of reports
already built, already covered by the existing generic Account Ledger page, or tied to business
concepts this app deliberately doesn't model (sales agents, capital/service purchase splits, item
"company"/brand groupings) and would need a real feature decision first. Only 2 were genuinely new;
the user picked both. Report count now **20**. This time the coordinator pre-created valid *stub*
route files (not just the `require` lines in `routes/tenant.php`) up front, specifically to avoid the
prior batch's route-boot race where a faster fork's test run could fail because a slower sibling's
route file didn't exist yet — worked as intended, no race this time. Full suite after merge:
**184/184 tests, 1709 assertions** (up from 171/1513).

- **VAT Summary** (`VatSummaryReportController::index`, `routes/tenant-reports-vat-summary.php`,
  `Tenant/Reports/VatSummary.vue`): the net-VAT-payable figure a real VAT filing actually needs, which
  neither the existing Sales VAT Book nor Purchase VAT Book computes (they list gross VAT only, no
  return-netting). Output VAT = posted `Sale.vat_amount` in range minus non-cancelled
  `SalesReturn.vat_amount` (a stored column on the return itself, not recomputed) **whose own date**
  falls in range — deliberately netted by the return's period, not the original sale's period, since
  that's how a real VAT return filing works (a return processed in period B reduces period B's
  liability). Input VAT mirrors this via Purchase/PurchaseReturn. `netVatPayable = outputVat.net -
  inputVat.net`; positive is owed, negative is refundable/carry-forward, and the UI labels/colors it
  accordingly rather than showing a bare signed number.
- **Stock Movement Register** (`StockMovementRegisterController::index`,
  `routes/tenant-reports-stock-movement-register.php`, `Tenant/Reports/StockMovementRegister.vue`):
  the inventory-side sibling of Day Book — one row per non-cancelled `ItemStockMovement` in a date
  range (optionally filtered to one item), chronological, with a signed quantity
  (`quantity * movement_type->direction()`, `quantity` is stored as a positive magnitude) and a
  human-readable reference description resolved from the polymorphic `reference` relation
  (`SaleLine`→"Sale #N · Customer", `PurchaseReturnLine`→"Purchase Return #N (Purchase #M)", etc.) —
  verified against what `Sale::post()`/`Purchase::post()`/`SalesReturn::post()`/
  `PurchaseReturn::post()`/`StockAdjustment::post()` actually pass to `recordStockMovement()`, not
  guessed.
- **A real bug caught in verification, not in either fork's own tests**: `VatSummary.vue`'s
  `defineProps()` used `default: zeroVatBlock` referencing a locally-declared arrow function — Vue's
  `<script setup>` compiler hoists `defineProps()`'s argument out of setup scope, so it can't
  reference local variables (`[@vue/compiler-sfc] defineProps() ... cannot reference locally declared
  variables`). This only surfaces at `npm run build` time, which the fork was deliberately told not to
  run (to avoid concurrent-build races) — a real gap in the "Pest tests pass" signal for Vue-only
  bugs. Fixed by inlining the default as `() => ({ gross: 0, returns: 0, net: 0 })` directly in both
  `outputVat`/`inputVat` prop definitions. **Lesson for future forks that touch `.vue` files under a
  no-build constraint: either allow one `npm run build` right after each Vue-touching fork lands (cheap,
  ~10-20s, no real race risk since builds don't write per-fork state), or explicitly warn forks never to
  reference outer-scope identifiers inside `defineProps()`'s defaults.**

**2026-08-29, second session: 5 more legacy reports (TDS, Stock Valuation, Item-wise Sales/Purchase,
Category-wise rollups), via 5 parallel forks.** Report count now **18**. User picked all three
candidate batches offered from a deduplicated read of legacy's `reportsController.php` (~70 methods,
mostly date-filter variants of the same underlying report — dedup'd down to real distinct types
before asking). Coordinator pre-stubbed both shared files before forking (the `require` lines in
`routes/tenant.php`, the 7 new nav entries in `resources/js/lib/nav-items.js`) so all 5 forks'
controller/route/Vue/test files were fully disjoint — confirmed via `git status` after merge, zero
overlapping edits. Each fork was told to scope its post-work `pint` run to its own exact file list
(not `--dirty`) specifically to avoid the cross-fork pint-collision gotcha from the prior session's
4-fork batch. Full suite after merge: **171/171 tests, 1513 assertions** (up from 151/1004),
`npm run build` succeeds, `vendor/bin/pint --dirty` (run once, after all forks landed) clean.

- **TDS Report** (`TdsReportController::index`, `routes/tenant-reports-tds.php`,
  `Tenant/Reports/TdsReport.vue`): lists every posted Sale/Purchase with a `tds_account_id` in a date
  range, split into "TDS on Sales" (a claimable credit) and "TDS on Purchases" (a liability owed to
  the tax authority), each row showing **net** TDS — `tds_amount` minus the sum of every non-cancelled
  return's proportional `tdsShare = round(tds_amount * (return.total/total), 2)` reversed against it,
  the exact formula `SalesReturn::post()`/`PurchaseReturn::post()` use. Hand-verified directly (not
  just trusted): a cancelled return is excluded from the reversal sum because `cancel()` already
  re-posts a voucher restoring the original TDS in the ledger, so treating it as a no-op here is
  correct, not an oversight.
- **Stock Valuation Report** (`StockValuationReportController::index`,
  `routes/tenant-reports-stock-valuation.php`, `Tenant/Reports/StockValuation.vue`): a single
  `as_of`-date snapshot (not a range) of on-hand quantity × weighted-average cost per stockable item,
  sorted by valuation descending. Reuses `InventoryReportController::stockSummary()`'s exact
  weighted-average-cost algorithm collapsed to one cutoff instead of a from/to range — deliberately
  duplicated rather than shared, matching this app's existing per-controller-file convention.
- **Item-wise Sales / Item-wise Purchase** (`ItemWiseSalesReportController`/
  `ItemWisePurchaseReportController`, routes `tenant-reports-item-wise-{sales,purchase}.php`, pages
  `Tenant/Reports/ItemWise{Sales,Purchase}.vue`): the item-level counterpart to the existing
  invoice-level Sales/Purchase Register — aggregates `SaleLine`/`PurchaseLine.line_total` (not
  `quantity * rate`, since `line_total` already has the line discount baked in) grouped by item over a
  date range, posted-only. Built via portable query-builder joins + `selectRaw` SUM/COUNT aggregates
  (no vendor-specific SQL), verified against both SQLite (tests) and the portability rule.
- **Category-wise rollups** (`CategoryWiseReportController::{salesByCategory,purchaseByCategory,
  stockByCategory}`, `routes/tenant-reports-category-wise.php`, pages `Tenant/Reports/
  {Sales,Purchase,Stock}ByCategory.vue`): Sales/Purchase rolled up by `ItemCategory` with a nested
  `ItemSubcategory` breakdown; Stock is the same `as_of` weighted-average-valuation snapshot as the
  Stock Valuation report above, just summed into category/subcategory buckets instead of listed
  per-item. Every category is always shown (even zero-activity ones), and `item_category_id` is a
  **required, non-nullable FK** on `Item` (confirmed via migration) — so there is no "uncategorized
  item" edge case that could silently drop money from the grand total; an item with no *subcategory*
  rolls into its category's own total and only surfaces its own "Uncategorized" sub-row when nonzero.
- **A real gotcha from running 5 forks instead of 4**: `routes/tenant.php` unconditionally requires
  all 5 new route files up front (pre-stubbed before forking, per the file-ownership convention), so
  until every fork had actually created its own route file, **every test in the whole suite** —
  not just the slow fork's — failed at route-boot time whenever a faster fork ran `php artisan test`
  first. Every fork independently noticed and correctly diagnosed this as a transient sibling-race,
  not their own bug; one fork briefly stubbed the missing file to unblock its own verification and
  deleted the stub immediately after. No real conflict resulted (confirmed via the final disjoint
  `git status`), but if forking ≥5 report/route batches again, consider having the coordinator create
  empty-but-valid stub route files up front (not just the `require` lines) so this race doesn't
  recur.

**2026-08-29, first session: 5 new reports + return fidelity gaps closed, via 4 parallel forks.** Picked by
the user from goal.md's open-items list after re-verifying (not just trusting) the prior session's
"134/134, not yet committed" claim — confirmed green and committed it first as two logical commits
(`36f24b8` the missing-tenant-DB regression fix, `e02fa9d` the closed-year correction UI +
`rollForward()` bug fix), THEN fanned out. Nav entries for all 5 new reports were pre-added by the
coordinator to `resources/js/lib/nav-items.js` before forking (mem.md gotcha #5's convention) so the
two reports forks never touched the same file. Full suite after merge: **151/151 tests, 1004
assertions** (up from 134), `npm run build` succeeds, `vendor/bin/pint --dirty` clean. All 4 forks'
file sets were fully disjoint — confirmed via `git status` after merge, zero overlapping edits.

- **Day Book / Cash Book / Bank Book** (`AccountingReportController::{dayBook,cashBook,bankBook}`,
  routes in `routes/tenant-reports-accounting.php`, pages `Tenant/Reports/{DayBook,CashBook,
  BankBook}.vue`): Day Book is a date-range chronological diary of every voucher (deliberately does
  NOT exclude `ClosingEntry`/`OpeningBalance` — it's a complete audit trail, not a balance
  computation, unlike Trial Balance/Income Statement). Cash Book is hardcoded to the seeded `AS1`
  account (matches this app's existing hardcoded-account-code convention). **Bank Book has no way to
  auto-detect "which account is a bank account"** — there's no `is_bank` flag anywhere on `Account` —
  so it's a plain account picker excluding `AS1`, a real deliberate scope decision, not an oversight;
  revisit if a real bank-account classification is ever added. Both Cash/Bank Book compute an
  **opening balance from all activity strictly before the date range** (not fiscal-year-boxed like
  `Accounts/Ledger.vue`), which is what makes an arbitrary date-window "book" report meaningful.
- **Aged Receivables / Aged Payables** (`SalesPurchaseReportController::{agedReceivables,
  agedPayables}`, routes in `routes/tenant-reports-sales-purchase.php`, pages
  `Tenant/Reports/{AgedReceivables,AgedPayables}.vue`): buckets `credit`-mode, `posted` Sale/Purchase
  invoices by age (Current 0-30 / 31-60 / 61-90 / 90+ days as of a query-param `as_of` date, default
  today) into per-customer/supplier rows. Outstanding per invoice = `total - sum(returns against that
  specific invoice)` — exact, no FIFO needed, since returns already FK-reference one specific
  sale/purchase. **Real, explicitly documented MVP gap**: this app has NO dedicated
  payment-receipt/supplier-payment feature anywhere (confirmed via grep — no `Receipt`/`Payment`
  model exists), so a credit invoice settled later via a generic Journal Voucher will keep aging here
  forever even though it's actually been paid — same honesty-over-silence category as the
  discount/TDS gaps below were before this pass. Revisit if a real payment-receipt feature is ever
  built.
- **Sales/Purchase Return fidelity — all three previously-documented gaps closed**: cancel-a-return,
  proportional header-discount/TDS reversal, and an optional cash/bank refund voucher, mirrored
  independently by two separate forks (Sales Return: `app/Models/SalesReturn.php`
  `App\Http\Controllers\Tenant\Sales\SalesReturnController`; Purchase Return: the `PurchaseReturn`
  equivalents) — verified by reading both models' final `post()`/`cancel()` code directly (not just
  trusting each fork's self-report) and hand-tracing the double-entry math.
  - **Cancel-a-return**: both `SalesReturn`/`PurchaseReturn` gained a `status` column (migrations
    `2026_08_29_100000_...sales_returns...`/`2026_08_29_100130_...purchase_returns...`) and a
    `cancel(User $actor, string $reason)` method mirroring `Sale::cancel()`/`Purchase::cancel()`'s
    exact shape: mirrors the original voucher's lines (debit/credit swapped) into a new voucher —
    reusing `VoucherType::Sale`/`VoucherType::Purchase` for the reversal (the mirror image of why
    `Sale::cancel()`/`Purchase::cancel()` reuse the *Return* type for theirs) rather than adding new
    enum cases — and flags the return's own stock movements cancelled. The `alreadyReturned` quantity
    guard in both `post()` methods now excludes cancelled returns, and `Sale::cancel()`/
    `Purchase::cancel()`'s own "block if any return references this" guard now excludes cancelled
    returns too, so a sale/purchase becomes cancellable again once every return against it is itself
    cancelled.
  - **Header-discount reversal**: Sales — `Sale::post()` applies the header discount only against the
    vatable subtotal before crediting Sales Revenue; `SalesReturn::post()` now reconstructs
    `vatableSubtotalBeforeDiscount = sale.taxable_amount + sale.discount` and backs out each returned
    vatable line's proportional share (`discount * (lineTotal / vatableSubtotalBeforeDiscount)`)
    before crediting Sales Revenue back. Purchases — `Purchase::post()`'s discount mechanism is a
    **uniform ratio of the vatable subtotal applied per item-account** (different shape than Sales'
    single lump account, per the real bug mem.md already documents fixing here); `PurchaseReturn::
    post()` mirrors that exact ratio in reverse, per item account.
  - **TDS reversal**: both compute `tdsShare = tds_amount * (returnTotal / originalTotal)` and split
    it out of the customer/supplier line rather than adding a same-side extra line — Sales credits
    `[customer: total - tdsShare, tds_account: tdsShare]`; Purchases debits
    `[supplier: total - tdsShare, tds_account: tdsShare]` — both pairs still sum to the return's own
    `total`, so the voucher balances for any `tdsShare` value without a separate correction line.
  - **Cash/bank refund voucher**: an optional `refund_account_id` (new nullable FK column, both
    tables, `nullOnDelete()` matching this app's existing optional-account-FK convention) triggers a
    **second** `VoucherType::Journal` voucher immediately after the return's own voucher, settling
    exactly the amount that moved onto the customer's/supplier's account (Sales: `[debit customer,
    credit refund_account]` for `total - tdsShare`; Purchases: `[debit refund_account, credit
    supplier]` for the same) — correctly using the post-TDS-split amount, not the raw `total`, so it
    stays correct even when combined with a TDS reversal.
  - **A genuine, non-obvious design gap both forks independently caught and fixed identically** (same
    convergent-fix pattern mem.md has recorded before, e.g. the missing `HasFactory` trait fix during
    the Sales/Purchase/Stock-Adjustment pass): if a refunded return is later cancelled, reversing only
    the return's own voucher would leave the customer's/supplier's ledger wrong, since the refund cash
    already moved. Both models gained a `refund_journal_voucher_id` column (not in the original task
    spec — a real gap the forks found mid-build) so `cancel()` reverses **both** vouchers' lines
    together into one new voucher when a refund was posted. Hand-traced concretely: for a cash sale +
    full return + refund + cancel, the net effect correctly nets the customer's ledger back to exactly
    its post-sale balance and brings the refunded cash back in — verified by tracing the mirrored line
    set by hand, not just trusting the passing tests.
  - Routes: `POST /sales-returns/{salesReturn}/cancel`, `POST /purchase-returns/{purchaseReturn}/
    cancel`. Frontend: both `Returns/Index.vue` pages gained a status column + cancel action
    (mirroring however `Sales/Index.vue`/`Purchases/Index.vue` already exposed their own
    cancel-a-sale/purchase UX), both `Returns/Create.vue` pages gained an optional "Refund via"
    account picker.
- **A recurring parallel-work gotcha surfaced again this pass**: `vendor/bin/pint --dirty` operates
  repo-wide over every uncommitted file, not just the files the invoking fork itself touched — one
  fork's pint run cosmetically reformatted a sibling fork's in-flight, not-yet-complete file (a
  `phpdoc_align` whitespace tweak, harmless here since the sibling's substantive edits were already
  on disk by then, but a future batch with tighter timing could see one fork's uncommitted edits
  clobbered by another's simultaneous pint run). If running ≥4 parallel forks that all end with a
  pint pass again, consider scoping each fork's pint invocation to only its own known file list
  (e.g. `vendor/bin/pint --format agent -- <files>`) instead of `--dirty`.

**2026-08-27 session, second entry: closed-year correction UI (frontend) + a real roll-forward bug
found by testing it end-to-end.** Backend for posting a correcting journal voucher into a closed
fiscal year already existed and needed zero changes (`JournalVoucher::post()` +
`JournalVoucherController::store()`, see the ledger engine section below) — this was purely a
frontend + test-coverage gap. Added: `Index.vue` now passes the already-fetched `fiscalYears` prop
through to `Create.vue`; `Create.vue` shows an **admin-only** fiscal-year `Select` (closed years
labeled), and when a closed year is picked, a warning callout (reusing the existing
`--color-warning-bg`/`--color-warning-text` tokens, previously unused outside `Badge.vue`) plus a
required `reason` `<textarea>` (no dedicated `Textarea.vue` component exists in this codebase, so a
raw element styled to match `Input.vue`/`Select.vue` was used) — non-admins never see the picker at
all, so they can't get partway through a voucher before learning at submit time that it's rejected.
Added 2 new HTTP-level tests in `LedgerControllerTest.php` (the closed-year path was previously only
tested at the model layer, never through the actual `POST /journal-vouchers` route).

Writing the "admin posts a correction, it rolls forward" HTTP test caught a **real, previously
undetected bug** in `JournalVoucher::rollForward()` (`app/Models/JournalVoucher.php`): it selected
"subsequent fiscal years" via `FiscalYear::where('start_date', '>', $correctedYear->start_date->toDateString())`.
Under SQLite, a `date`-cast column is actually stored as a full `"Y-m-d H:i:s"` string (a known
Laravel+SQLite quirk — the grammar's date format applies to `date` columns too, not just
`datetime`), which is lexicographically *greater than* the truncated `"Y-m-d"` string being compared
against. So the corrected (closed) year matched its own `start_date > ...` filter and got a
**second, spurious roll-forward voucher posted into itself**, on top of the correction voucher
already there — silently corrupting the closed year's own figures with a self-referential
"correction of a correction." The existing model-level test for this
(`JournalVoucherPostingTest.php`) never caught it because it only asserted the *open* year got the
right roll-forward voucher, never that the *closed* year stayed at exactly one voucher. **Fix**:
excluded the corrected year explicitly by id (`FiscalYear::whereKeyNot($correctedYear->id)`) rather
than relying on the date comparison alone — safe because the model already forbids two fiscal years
sharing a `start_date` (see `FiscalYear::saving()`'s overlap check), so once self is excluded by id,
the date-string comparison is reliable for every other row. Strengthened the model-level test with
an explicit "closed year has exactly 1 voucher" assertion so this can't regress silently again.
**Verified**: full suite **134/134 passing, 819 assertions** (up from 132/132 — 2 new tests), `npm
run build` succeeds, `vendor/bin/pint --dirty` clean. **Not yet committed, not yet browser-verified**
— ask before committing.
- **Lesson**: a Laravel `date` cast does not guarantee a bare `Y-m-d` string in the database on
  SQLite — comparing a cast column against a manually truncated string (`->toDateString()`) can
  silently misbehave in self-referential queries. Prefer excluding the current row by primary key
  explicitly rather than trusting a strict date inequality to do it, especially in code executed
  during money-affecting operations. Also: a test that only checks "the right thing showed up"
  without also checking "nothing extra showed up" will miss exactly this class of bug — this is the
  second time in this project a stricter assertion (not a different code path) is what surfaced a
  real defect.

**2026-08-27 session: found and fixed a real regression, not documented anywhere before this.** A
fresh session started by re-verifying mem.md's claims against actual repo state (a good habit —
don't trust a stale "not yet verified" note at face value) and found the full suite at **21/132
passing, 111 errors** — dramatically worse than the "5/132 failed" mem.md had last recorded after
post-hoc fix #6. Root cause (new, distinct from fixes #1–#6 above, though the same underlying vendor
gap): `DatabaseTenancyBootstrapper::bootstrap()` (vendor code) only checks "does the tenant database
exist" and throws a clean `TenantDatabaseDoesNotExistException` when `app()->environment('local')`
— under `testing` (and `production`) it skips that check and goes straight to
`connectToTenant()`, so a real inbound request into a still-`Provisioning` tenant (whose database
genuinely doesn't exist yet) made `tenancy()->initialize()` throw a raw, uncaught SQLite "database
file does not exist" exception, in `InitializeTenancyByDomain::handle()`, before
`AbortIfTenantSuspended` (route middleware, added by the hardening pass) ever got a chance to 403
it. That crash left `Tenancy::$initialized` stuck `true` (same no-try/finally vendor gap documented
in post-hoc fix #5/#6), which corrupted the next test's DB transaction state (SQLite's connection
purge/reconnect under an open RefreshDatabase transaction), cascading "cannot start a transaction
within a transaction" errors into nearly every other tenant-provisioning test in the run.
**Fix**: `AbortIfTenantSuspended` now resolves the tenant itself via
`Stancl\Tenancy\Resolvers\DomainTenantResolver` (a plain central-DB query, catching
`TenantCouldNotBeIdentifiedException` and passing through to let `InitializeTenancyByDomain` handle
an unrecognized domain normally) and checks status **before** tenancy is ever initialized, so a
Provisioning/Suspended tenant is rejected without ever attempting to connect to its (possibly
nonexistent) database. Moved to run before `InitializeTenancyByDomain` in both
`TenancyServiceProvider::makeTenancyMiddlewareHighestPriority()`'s priority list and `routes/tenant.php`'s
declared order (the priority list is what actually controls execution order — the route's own array
order is cosmetic once middleware is in that list, but kept in sync for readability). This still
only ever fires for genuine inbound HTTP requests (not the provisioning pipeline's own internal
`$tenant->run()` calls), same reasoning as post-hoc fix #6 — see that middleware's own docblock.
**Verified**: full suite now **132/132 passing, 811 assertions** (up from the pre-hardening-pass
121/121 — the hardening pass added 11 tests), `npm run build` succeeds, `vendor/bin/pint --dirty`
clean. This is a genuinely new fix, not a re-verification of fix #6 — fix #6 was correct and
necessary but insufficient; it fixed the *internal pipeline* self-abort bug, not this separate
*external request during the missing-DB window* bug. Touched files:
`app/Http/Middleware/AbortIfTenantSuspended.php`, `app/Providers/TenancyServiceProvider.php`,
`routes/tenant.php`. **Not yet committed as of this update** — ask before committing/pushing.

---

## Stack, as actually installed

- Laravel 13.17, PHP 8.3/8.4.
- `stancl/tenancy` v3.10.1, multi-database mode.
- `inertiajs/inertia-laravel` ^3.3, `@inertiajs/vue3`, `vue` 3, `reka-ui` (v2.10.3 — flat named
  exports from `'reka-ui'`, e.g. `import { DialogRoot, SelectRoot } from 'reka-ui'`, not
  namespaced).
- `@tanstack/vue-table` — **v9 is installed**, not v8. v9 is a rewrite: features are declared via
  `tableFeatures({...})` + `createSortedRowModel()`/`createPaginatedRowModel()`, there's no
  `getState()` (state lives in `table.atoms.*`, read directly so Vue's reactivity tracks it), and
  templates render via `<FlexRender :header="header" />` / `<FlexRender :cell="cell" />` rather than
  a bare `flexRender` helper. Any AI-generated or copy-pasted TanStack example is almost certainly
  v8 API and will not compile — verify against `node_modules/@tanstack/vue-table` first.
- `@lucide/vue` for icons — **not** `lucide-vue-next`, which is deprecated (was installed once,
  swapped out same day).
- `clsx` + `tailwind-merge`, combined into `resources/js/lib/utils.js`'s `cn()` helper — the
  standard shadcn-vue pattern, used for merging class props across every `ui/` component.
- Pest ^5.1 + pest-plugin-laravel, `phpunit.xml` uses `DB_CONNECTION=sqlite`,
  `DB_DATABASE=:memory:`, `CACHE_STORE=array`. RefreshDatabase is fine and expected here — this is
  **not** the sibling `day_khata` repo's live-MySQL/no-RefreshDatabase test setup, don't apply that
  constraint here.

## What's built

### Backend: tenancy, auth, tenant management

- **Central DB tables**: `tenants`, `domains` (stancl's own, `App\Models\Tenant` extends stancl's
  base and adds `company_name`/`status`/`contact_email` as real columns — see gotcha below),
  `platform_admins` (+ `App\Models\PlatformAdmin`), `sessions` (central-only — platform admin
  sessions), `cache`/`cache_locks`, `jobs`/`job_batches`/`failed_jobs`.
- **Guards** (`config/auth.php`): `platform` → `platform_admins` provider → `PlatformAdmin`
  (central). `web` → `tenant_users` provider → `App\Models\User` (tenant DB). Never conflate these;
  a tenant route must never check the `platform` guard or vice versa.
- **Central auth**: `App\Http\Controllers\Central\Auth\AuthenticatedSessionController`. Login at
  `/login` (on any `central_domains` entry — currently `127.0.0.1`, `localhost`), rate-limited
  (`throttle:5,1`), generic failure message. Seeder: `database/seeders/PlatformAdminSeeder.php`,
  standalone (not in root `DatabaseSeeder`), creates `admin@example.com` / `password` — **local dev
  only, not a real credential, don't treat as a secret, but don't ship it as a prod default
  either.**
- **Tenant management**: `App\Http\Controllers\Central\Tenants\TenantController`
  (index/create/store/show/suspend/resume/destroy), all behind `auth:platform`, routes in
  `routes/central-tenants.php`. Provisioning flow: create `Tenant` row → stancl's `TenantCreated`
  pipeline fires `CreateDatabase` → `MigrateDatabase` → `SeedDatabase` (synchronous,
  `shouldBeQueued(false)` — see `goal.md` production-hardening item) → create `Domain`
  (`{subdomain}.localhost`) → `$tenant->run(fn () => ...)` creates the first admin `User` against
  the seeded `admin` role. A listener (`app/Listeners/AbortIfTenantSuspended.php`, registered on
  `TenancyInitialized`) 403s any request to a suspended tenant before its DB/cache/filesystem
  connections bootstrap.
- **Tenant DB tables** (`database/migrations/tenant/`): `roles`, `permissions`,
  `permission_role`, `users` (`role_id` FK), `sessions` (tenant-local — see cache/jobs gotcha),
  `cache`/`cache_locks`, `jobs`/`job_batches`/`failed_jobs`.
- **Tenant auth**: `App\Http\Controllers\Tenant\Auth\AuthenticatedSessionController`, `web` guard,
  routes in `routes/tenant.php` inside the existing `InitializeTenancyByDomain` +
  `PreventAccessFromCentralDomains` middleware group. `EnsureUserHasRole` middleware (alias `role`,
  registered in `bootstrap/app.php`), demo gated route at `/admin/users`.
- **`TenantDatabaseSeeder`** (`database/seeders/Tenant/TenantDatabaseSeeder.php`, the exact class
  `config/tenancy.php`'s `seeder_parameters.--class` points at): seeds a handful of permissions and
  exactly two roles — **`admin`** (all permissions) and **`staff`** (none). The `admin` slug is a
  load-bearing contract: `TenantController::store()` looks it up by that exact string to assign the
  provisioned tenant's first user. Don't rename it without updating the other side.

### Frontend: Inertia + Vue 3 + Tailwind v4 + Reka UI

- `resources/css/app.css` — the full `@theme` token block ported verbatim from
  `../day_khata/design-preference.md` / the (already-corrected) sibling `03-design-system-frontend.md`
  §2. `--radius-*` scale is redefined to `0px` across the board except `--radius-full` (9999px) —
  flat corners come from plain `rounded`/`rounded-lg` utilities for free, no need to fight Tailwind.
- `resources/js/components/ui/`: `Button`, `Input`, `Select`, `Combobox`, `Badge`, `Loader`,
  `Modal`, `Toaster` (+ `resources/js/composables/useToast.js`), `Tabs`, `DropdownMenu` +
  `DropdownMenuItem`, `DataTable`, `Card`, `ListRow`. 16 components total.
- `resources/js/layouts/`: `AppLayout.vue` (sidebar via a `navItems` prop, topbar showing
  `auth.platformAdmin`/`auth.user`, mounts `<Toaster />` once), `AuthLayout.vue` (centered card).
- `resources/js/pages/`: `Central/Auth/Login`, `Central/Dashboard`, `Central/Tenants/{Index,Create,Show}`,
  `Tenant/Auth/Login`, `Tenant/Dashboard`, `Tenant/Admin/Users`. All old Blade equivalents deleted;
  only `resources/views/app.blade.php` (Inertia root) and `resources/views/welcome.blade.php`
  (untouched, unused-in-practice `/` route) remain.
- `HandleInertiaRequests` shares `auth.platformAdmin`, `auth.user`, and `flash.status` (for
  `session()->with('status', ...)` messages, e.g. after provisioning/suspend/resume/delete) on
  every page by default.
- **Client-facing demo**: `docs/day-khata-design-system.html` — a standalone, self-contained HTML
  style guide (no build step, sent to the client directly) covering every token and component. Keep
  it in sync manually if the real component library's visuals diverge; it is *not* generated from
  the Vue components, it's a hand-authored parallel reference.

### Backend: core business schema (chart of accounts, customers/suppliers, items)

Tenant-DB master data, `goal.md` roadmap item 2. Backend AND frontend now both built (frontend
landed 2026-08-25 in a second pass, via 3 parallel agents - see "Frontend: business schema pages"
below). Also **no permission-gating** wired to these routes beyond `auth:web` (any authenticated
tenant user) — matches the existing MVP baseline in `TenantDatabaseSeeder`, not a decision to gate
later.

- **Chart of accounts** (`app/Models/{AccountHead,AccountGroup,AccountSubgroup,Account}.php`,
  migrations `2026_08_25_100000..100003`): a proper FK-based 3-to-4-level hierarchy
  (`account_heads` → `account_groups` → `account_subgroups` [optional] → `accounts`), replacing
  the legacy `day_khata` app's `accountheadsetup`/`accountgroup`/`accountsubgroup`/`mainaccount`
  tables, which matched rows by denormalized string columns (`accounthead`/`groups`/`subgroups`
  copied onto every row) instead of foreign keys. This is a deliberate squash/redesign, not a
  literal port — explicitly permitted by `../day_khata/migration_plan/04-data-schema-provisioning.md`
  §2 ("fresh, consolidated migrations... not a literal port").
  - `accounts.account_group_id` and `accounts.account_subgroup_id` are **both nullable, and
    exactly one must be set** — some legacy account groups (e.g. "Sales Accounts") have no
    subgroup level at all, others (e.g. "Current Assets") do. Enforced in `Account::booted()`'s
    `saving` hook (throws `InvalidArgumentException`), **not** a DB CHECK constraint — kept
    app-level to stay SQLite/MySQL portable per the "Stay portable" rule in `goal.md`.
- **`ChartOfAccountsSeeder`** (`database/seeders/Tenant/ChartOfAccountsSeeder.php`, called from
  `TenantDatabaseSeeder::run()`): seeds the 5 fixed account heads (Assets/Liabilities/Income/
  Expenses/Capital), 8 groups, 5 subgroups, and 9 default leaf accounts, ported from the legacy
  app's `database/seeders/DefaultMainAccountSeeder.php`. **`"Sundry Debtors"` and `"Sundry
  Creditors"` subgroup names are a load-bearing contract** — looked up by exact string in
  `App\Models\Concerns\HasLedgerAccount`. Don't rename without updating that trait.
  Deliberately **not** ported from the legacy seeder: Fixed Asset/TDS/asset-disposal default
  accounts (Accumulated Depreciation, TDS Receivable/Payable, Gain/Loss on Asset Disposal) — those
  belong to the Fixed Assets phase (`05-phase-plan.md` Phase 3), add them when that phase starts.
  Also **not** ported: the legacy "Walk-in" customer auto-seed (POS anonymous-sale customer) —
  that's a Sales/POS bootstrapping concern (Phase 2), not core master data.
- **`App\Models\Concerns\HasLedgerAccount`** trait, used by `Customer` and `Supplier`: on
  `creating`, auto-creates a linked `Account` under the model's `ledgerAccountSubgroupName()`
  (`'Sundry Debtors'` / `'Sundry Creditors'`) and sets `account_id`; on `updated`, syncs
  name/phone/address into the linked account if any of those changed. Ports the legacy
  `CustomerController`/`SupplierController`'s `DB::table('mainaccount')->insert(...)`
  side-effect (string-matched) onto a real FK relationship — this is the "creates a
  chart-of-account entry" behavior `05-phase-plan.md` Phase 1 explicitly calls out.
  `customers.account_id`/`suppliers.account_id` are **non-nullable, `restrictOnDelete()`** — an
  `Account` can never be deleted while a customer/supplier still references it; deleting the
  customer/supplier itself leaves the ledger account orphaned-but-intact (correct: never silently
  delete financial records).
- **Items**: `item_categories` → `item_subcategories` (optional) → `items`
  (`app/Models/{ItemCategory,ItemSubcategory,Item}.php`, migrations `2026_08_25_100006..100008`).
  Renamed from the legacy `item_groups`/`item_sub_groups`/`inventorysettings` naming. Dropped
  legacy columns that were ecommerce-only (`thumbnail`, `rating_count`, `sell_count`, `features`,
  `publish_for_ecommerce`, `keep_item_for_sell`, `commonCode`, `beIn`, `purchaseStatus`) per the
  ecommerce non-goal in `00-overview.md` §3, and `company_id`/`store_id` (multi-company/multi-store
  concepts superseded by tenancy itself / not yet in scope — flagged here, not silently dropped).
  `items.account_id` (nullable, optional inventory/COGS ledger link) is a plain manual FK, **not**
  auto-created like customers/suppliers — matches the legacy `inventorysettings.accno` behavior
  (a user-assigned code, not an auto-generated one).
- **Routes**: `routes/tenant-business.php` (new file, `require`d from `routes/tenant.php` inside
  its `auth:web` group) — `account-groups`, `account-subgroups`, `accounts`, `customers`,
  `suppliers`, `item-categories`, `item-subcategories`, `items`, each with
  index/store/update/destroy only (no create/edit/show — simple master-data CRUD, forms are
  expected to be inline modals once the frontend pass builds them, matching the existing
  `central-tenants.php` explicit-route style rather than `Route::resource`).
- **Controllers**: `app/Http/Controllers/Tenant/{Accounting,Parties,Inventory}/*Controller.php` —
  8 controllers, all thin (inline `$request->validate()`, no Form Request classes — matches this
  project's existing convention, there are none anywhere else in the codebase either). All business
  logic (ledger auto-linking, the group-xor-subgroup invariant) lives in the models, not here.
- **Tests**: `tests/Feature/Tenant/{Accounting/ChartOfAccountsTest,Parties/{CustomerTest,
  SupplierTest},Inventory/ItemTest}.php` — 18 focused tests covering seeding, the
  group-xor-subgroup guard, ledger auto-link + sync-on-update, mobile-uniqueness, cross-field
  subcategory-belongs-to-category validation, and full HTTP CRUD round-trips. Run in isolation
  first (per that session's instruction), then verified against the full 44-test suite together —
  all green.

### Frontend: business schema pages

Built 2026-08-25 in a second pass, immediately after the backend above, via **3 parallel
subagents** (one per module: chart of accounts, parties, inventory) each owning a disjoint
directory — no shared nav/config file, every page defines its own `navItems` locally (matching the
existing per-page convention) using an identical hardcoded block so the sidebar is consistent
across all 8 pages without any of the three agents touching the same file.

- **Pages** (all `resources/js/pages/Tenant/.../Index.vue`, matching the exact strings each
  controller's `Inertia::render()` call already used):
  `Accounting/{AccountGroups,AccountSubgroups,Accounts}`, `Parties/{Customers,Suppliers}`,
  `Inventory/{ItemCategories,ItemSubcategories,Items}`. Every page follows the same pattern: header
  row + "New X" button opening a shared create/edit `Modal` (tracked via an `editing` ref, null =
  create), `Card variant="panel"` wrapping a `DataTable`, row actions as icon buttons
  (Pencil/Trash2), delete via native `confirm()` + `router.delete(...)`.
- **`Accounts/Index.vue`** has the one genuinely nontrivial form: a "File under Group/Subgroup"
  toggle (`parentType` ref) that swaps which Select is shown and nulls out the inactive
  `account_group_id`/`account_subgroup_id` field before submit, matching the backend's
  exactly-one-parent invariant.
- **Known gotcha this pass surfaced and fixed (don't rediscover it)**: create/edit/delete on these
  pages redirect back to the *same* route/component that's already mounted. Inertia's Vue adapter
  patches the existing component instance rather than remounting it, so a plain
  `onMounted(() => { if (flash.status) toast(...) })` — the pattern every pre-existing page in this
  app uses — only ever fires on the very first load of the page, never again after an in-place
  modal action. (`Central/Tenants/Show.vue`'s delete looks similar but isn't: it redirects to a
  *different* route/component, `Index`, so a real remount happens there and `onMounted` is fine.)
  Two of the three parallel agents independently caught this and fixed it; the third didn't, and
  its three pages (`ItemCategories`, `ItemSubcategories`, `Items`) were patched afterward to match:
  replace `onMounted` with `watch(() => page.props.flash?.status, ..., { immediate: true })`. If a
  new page ever needs a create/edit/delete-in-modal-on-the-index-page pattern, use `watch`, not
  `onMounted`, from the start.
- **`tests/Feature/Tenant/BusinessPagesRenderTest.php`** (new, not per-agent): one test hitting all
  8 routes as an authenticated user and asserting `assertInertia(fn ($page) => $page->component(...))`
  matches exactly. This is the guard against the controller's `Inertia::render('Tenant/.../Index')`
  string and the actual `.vue` file path silently drifting apart — there's no compile-time link
  between them, a typo either side would 200 with the wrong (or a broken) component and nothing
  else in the suite would catch it. Verified passing (all 8) after the parallel frontend pass.
- `npm run build` succeeds with all 8 new pages bundled. Full suite green: 45/45 (44 backend +
  this new render-guard test).

### Frontend: enterprise UI redesign (app shell, row actions, real dashboard)

Built 2026-08-25, same day as the business-schema pages above but a separate pass: the initial
frontend was flagged as "too basic" for an enterprise billing product. Design direction was
mocked up first in a Claude Design canvas (Xero/QuickBooks-density, 0-radius theme preserved,
single-place shadow/radius tokens) and approved by the user before any real code changed. Applied
via **4 parallel subagents** (AppLayout shell / page-batch-1 / page-batch-2 / dashboard),
following foundational pieces I built myself first so the agents had a fixed shared contract to
build against instead of inventing one each:

- **`resources/css/app.css`**: added `--shadow-xs`, `--shadow-sm`, `--shadow-primary-sm` to the
  `@theme` block, same "single place definition" pattern as the existing `--radius-*` tokens —
  Tailwind v4 auto-generates the matching `shadow-xs`/`shadow-sm`/`shadow-primary-sm` utilities.
- **`resources/js/lib/nav-items.js`** (new): `navGroups(isAdmin)` — the one source of truth for
  the tenant sidebar nav, grouped (`OVERVIEW`/`ACCOUNTING`/`PARTIES`/`INVENTORY`/`ADMIN`). Replaces
  the identical hardcoded flat `navItems` block that used to be copy-pasted into all 8 business
  pages + `Tenant/Dashboard.vue`. **Central (platform-admin) pages still pass their own flat,
  ungrouped `navItems` array locally** — untouched, different nav entirely, not migrated to this
  module on purpose.
- **`resources/js/layouts/AppLayout.vue`**: redesigned shell — sidebar widened to 264px with a
  logo mark, grouped nav (via `nav-items.js` for tenant pages), static user-info footer chip;
  topbar keeps the page `<h1>{{ title }}</h1>` on the left (Central pages depend on it — the
  mockup's title-less topbar was NOT ported as-is for this reason), adds a centered *decorative,
  `disabled`* search input (no backend, deliberately marked non-functional rather than looking
  broken), a `Tooltip`-wrapped notification bell (no fake unread badge — no notification feature
  exists yet), and an avatar+name+chevron `DropdownMenu` trigger holding the one "Log out" entry
  point (the old always-visible topbar Log-out `Button` was removed). **Key contract**: `navItems`
  prop accepts EITHER shape — grouped `[{label, items}]` (tenant) or legacy flat `[{label,href,icon}]`
  (Central) — normalized internally via `Array.isArray(navItems[0]?.items)`. Any new page can pass
  either shape safely.
- **`resources/js/components/ui/Tooltip.vue`** (new): generic reusable tooltip — `label` prop,
  `side` prop (`'top'`|`'bottom'`, default `'top'`), wraps trigger via default slot, dark flat pill
  styled off `bg-toast-bg` (matches `Toaster.vue`'s real color, not a new one). Used by
  `RowActions.vue`, `DataTable.vue`'s pagination prev/next buttons, and `AppLayout.vue`'s bell icon.
- **`resources/js/components/ui/RowActions.vue`** (new): the fix for "edit and delete look the
  same" — two icon buttons (`Pencil`/`Trash2` from `@lucide/vue`), each `Tooltip`-wrapped. Edit
  stays accent-tinted (`bg-primary-tint text-primary`, routine action); Delete is neutral by
  default (`bg-bg-subtle text-text-faint`) and only turns red on hover (`hover:bg-danger-bg
  hover:text-danger`) so a destructive action never looks pre-armed. Emits `edit`/`delete` (Vue
  maps these to `onEdit`/`onDelete` when used via `h(RowActions, {...})` in a TanStack column
  `cell` renderer). All 8 business `Index.vue` pages now use this instead of hand-rolled
  Edit/Delete buttons (some were plain text links, some were two visually-identical `Button
  variant="icon"` instances — same underlying bug either way).
- **`Button.vue`**: removed the primary variant's `shadow-[0_4px_14px_rgba(102,0,255,.35)]` — user
  feedback on the mockup was that it visually bled onto neighboring elements; primary buttons now
  have no shadow at all, not a lighter one.
- **`Card.vue`**: panel variant gained `shadow-xs`. **`DataTable.vue`**: pagination prev/next
  buttons wrapped in `Tooltip`.
- **`app/Http/Middleware/HandleInertiaRequests.php`**: added a shared `tenant` prop —
  `{ company_name } | null`, non-null only when `tenancy()->initialized`. Lets `AppLayout.vue`'s
  sidebar subtext show the real tenant company name without fabricating one on Central pages.
- **Real dashboard** (`app/Http/Controllers/Tenant/DashboardController.php`, new;
  `routes/tenant.php`'s `/dashboard` route now points at it instead of an inline closure): KPI row
  (customers/suppliers/items totals + real "this week" counts via `created_at >= now()->subWeek()`,
  plus a ledger-accounts total), last-5 `recentCustomers` (name/mobile/ledger code/relative time),
  and `accountHeadBreakdown` (per-`AccountHead` leaf-account counts, computed at runtime via
  `whereHas('group', ...)->orWhereHas('subgroup.accountGroup', ...)` — not hardcoded to the 5
  seeded head names). `resources/js/pages/Tenant/Dashboard.vue` rebuilt to render all of it; no
  fabricated/placeholder numbers anywhere in this page.
- **`tests/Feature/Tenant/DashboardTest.php`** (new): same tenant-provisioning pattern as
  `BusinessPagesRenderTest.php`, asserts the KPI/recentCustomers/accountHeadBreakdown prop shapes
  against real seeded records.
- Verified after all 4 agents landed: `php artisan test tests/Feature/Tenant` → 32/32 (up from the
  prior 18 + `BusinessPagesRenderTest`, now also includes `DashboardTest`). `npm run build`
  succeeds. Manually re-read `AppLayout.vue`, `Dashboard.vue`, `DashboardController.php`, and two
  representative `Index.vue` pages end-to-end after the parallel pass — all correctly wired, no
  agent left a stray unused import or a wrong function-name reference in the `RowActions` wiring
  (function names differ per file — `destroy`, `destroyCustomer`, `destroySupplier` — each agent
  matched the real name rather than assuming one).
- **Not yet done**: no live browser check of the redesign (only automated tests + a manual code
  read) — the user still needs to eyeball this in an actual browser before considering it final.

## Gotchas discovered the hard way (don't rediscover these)

1. **Tenant DBs need their own `cache` and `jobs` tables.** `CACHE_STORE=database` and
   `QUEUE_CONNECTION=database` in `.env` resolve against whatever the *default* connection is,
   which is the tenant connection for any request inside tenant context — including the
   database-backed rate limiter on login throttling. Missed this in the original foundational setup
   (only central got these tables at first); surfaced as a 500 on tenant login that the automated
   suite never caught because `phpunit.xml` uses `CACHE_STORE=array`. Fixed via
   `database/migrations/tenant/2026_08_22_120000_create_cache_table.php` and
   `..._120001_create_jobs_table.php`. If a new store/driver gets added to `.env` that defaults to
   "database," ask whether it needs a tenant-side migration too.
2. **`stancl`'s base `Tenant` model sweeps unlisted attributes into a `data` JSON column** (the
   `VirtualColumn`/`HasDataColumn` mechanism). `App\Models\Tenant` overrides `getCustomColumns()` to
   list `id`, `company_name`, `status`, `contact_email` explicitly — without that override, those
   three columns silently stop being real, queryable SQL columns and every `Tenant::where(...)`
   query against them breaks silently (not an error, just always empty).
3. **Tenancy doesn't auto-revert the DB connection at the end of an HTTP test request.** After a
   Pest test hits a tenant route, `config('database.default')` stays on the tenant connection for
   the rest of that test *and can leak into the next one*, since `RefreshDatabase`'s rollback
   re-evaluates which connection to roll back at teardown time. Every tenant-context feature test
   needs `afterEach(fn () => tenancy()->end())`. Forgetting this doesn't fail the test that forgot
   it — it corrupts the *next* test's central-DB isolation, so the failure looks unrelated.
4. **The default guest-redirect only knows one `login` route name.** Laravel's
   `redirectGuestsTo` defaults to the route named `login`, which is the *central* login here. Fixed
   in `bootstrap/app.php` with a closure that checks `tenancy()->initialized` to send tenant
   requests to `tenant.login` instead. If a new unauthenticated-redirect scenario shows up, check
   this closure first before assuming a routing bug.
5. **Route files are split specifically to let agents work in parallel without touching the same
   file**: `routes/web.php` (just a `Route::domain()` loop over `central_domains`) →
   `routes/central.php` (requires the two below) → `routes/central-auth.php` (platform-admin auth
   only) + `routes/central-tenants.php` (tenant CRUD only). `routes/tenant.php` is separate again
   (stancl-generated, tenant-domain-only). Keep this split even if it looks like unnecessary
   indirection for a single session working alone — it's what makes multi-agent fan-out safe here.
6. **`stancl/tenancy`'s per-tenant SQLite files have no file extension** (`database/tenant<uuid>`,
   not `.sqlite`) — `database/.gitignore`'s original `*.sqlite*` pattern missed them entirely. They
   got swept into the initial `git add -A` (36 files, ~4.3MB of dev-only data from manual
   provisioning tests) and had to be untracked in a follow-up commit. Fixed by adding `/tenant*` to
   `database/.gitignore`. If tenancy config ever changes where per-tenant SQLite files land, check
   that pattern still matches.

## How to verify the app is actually working (do this, don't just trust test-green)

```
cd D:\Projects\day-khata\day-khata-multi-tenant
npm run build              # must succeed — last verified 2026-08-29
php artisan test --compact # 151/151 as of 2026-08-29 (1004 assertions)
php artisan serve --port=8123   # then curl through it — see below
```

A known-good local tenant exists from manual smoke testing: `Acme Inc`, domain
`acme.localhost`, admin user `admin@acme.localhost` / `password123` (dev-only, recreate anytime
via `POST /tenants` as the seeded platform admin). Central platform admin:
`admin@example.com` / `password` (from `PlatformAdminSeeder`, not yet run against a fresh DB until
you `php artisan db:seed --class=PlatformAdminSeeder`).

To confirm a page is actually rendering the right Vue component (not just returning HTTP 200):
fetch the response and look for `<script data-page="app" type="application/json">` — it contains
the raw Inertia payload (`component`, `props`) even though curl can't execute the Vue mount itself.
This caught nothing wrong so far, but it's the fast way to tell "wrong component rendered" from
"right component, JS just didn't run under curl."

## Backend + Frontend: ledger / journal voucher posting engine

Built 2026-08-25, right after the enterprise-UI pass. This is `goal.md` roadmap item 3
(ledger/financial-transaction engine) — a **from-scratch, portable schema**, not a port of the
legacy `mainaccountledger`/`mainaccountledgerdetails` tables (whose column layout is inverted and
confusing). Two design questions were resolved with the user before building: (1) fiscal-year
enforcement is Eloquent-portable (global invariant + model events + one posting method) rather
than the legacy MySQL view/trigger/session-variable mechanism, so it runs identically on SQLite
(tests) and MySQL (prod); (2) the user chose full fidelity on scope — both the closed-year
super-admin correction override (with multi-year roll-forward) and automatic P&L year-end closing
are built now, not deferred. Full design record: the plan file this was built from is quoted in
full in the session transcript if the exact reasoning is ever needed again.

- **Schema** (5 new tenant migrations, `2026_08_25_100009` through `_100013`): `account_heads`
  gained `is_profit_and_loss` (boolean) — `Income`/`Expenses` are `true`, everything else `false`
  (set in `ChartOfAccountsSeeder`, which is the ONLY place head classification is decided — never
  string-match head names elsewhere). New tables: `fiscal_years` (name/start_date/end_date/status),
  `voucher_sequences` (per fiscal-year, per voucher-type sequential counters), `journal_vouchers`
  (header: fiscal_year_id/voucher_type/voucher_number/date/narration/reason/created_by),
  `journal_voucher_lines` (account_id/debit/credit/narration). **Posted vouchers are never
  edited or deleted** — every correction, including the closed-year override, is a new voucher.
- **`App\Models\FiscalYear`**: `saving` hook enforces at most one `open` row and no overlapping
  date ranges (same app-level-invariant style as `Account`'s group-xor-subgroup rule — no DB CHECK
  constraints, stays portable). `FiscalYear::current()` resolves the one open row (throws
  `ModelNotFoundException` if none exists — a fresh tenant must create its first fiscal year
  manually, nothing auto-creates one). `FiscalYear::close($next, $actor)` is the year-end engine:
  computes every P&L account's net balance for the closing year, zeroes each one via one
  consolidated `ClosingEntry` voucher with the net profit/loss credited/debited to the seeded
  `"Profit & Loss"` account (code `CA2`, under Capital — reused as the retained-earnings target,
  no new account was seeded for this), then carries every nonzero Balance Sheet account's ending
  balance forward as one consolidated `OpeningBalance` voucher into `$next`. Both consolidated
  vouchers are mathematically guaranteed to balance on their own (proven in the model's docblock
  comments) — worth reading `app/Models/FiscalYear.php` directly rather than re-deriving the sign
  logic from scratch if touching this again.
- **`App\Models\JournalVoucher::post($header, $lines, $actor)`** is the one user-facing entry
  point (thin controllers call this, no business logic duplicated in controllers, matching the
  house style). Validates double-entry shape (≥2 lines, each line exactly one of debit/credit,
  total debit = total credit), resolves the target fiscal year (defaults to `FiscalYear::current()`
  unless `fiscal_year_id` is explicitly passed), and — if that year is `closed` — requires a
  `reason` AND an `admin`-role actor (reusing the existing `role:admin` gate, same one `/admin/users`
  uses) before allowing it. A closed-year posting triggers `rollForward()`: it replays the
  correcting voucher's own lines with each line's account swapped to `"Profit & Loss"` when that
  account resets to zero every year-end (so its only lasting effect is on retained earnings) or
  left as-is for a Balance Sheet account, and posts that replayed (automatically still-balanced)
  line set into every fiscal year after the corrected one up to and including the currently open
  one. There's a low-level `JournalVoucher::write()` used internally by both `post()` and
  `FiscalYear::close()`/`rollForward()` for the actual numbering+creation — `close()`'s two
  consolidated vouchers deliberately bypass `post()`'s fiscal-year-resolution/closed-year gate
  since they're routine system bookkeeping, not a user-initiated correction.
- **Voucher numbering**: `VoucherSequence::firstOrCreate` + `lockForUpdate()` + `increment()`,
  per `(fiscal_year_id, voucher_type)` — this is the first `DB::transaction`/`lockForUpdate` usage
  anywhere in this codebase (confirmed via search before building — no prior pattern to match).
  `lockForUpdate()` is a no-op on SQLite (tests) but does real row locking on MySQL (prod); this is
  expected/portable Laravel behavior, not a bug. A genuine concurrent-race edge case on the very
  first voucher of a brand-new `(fiscal_year_id, voucher_type)` pair could raise a unique-constraint
  `QueryException` that aborts the whole `DB::transaction` — documented as an accepted, not
  bulletproofed, limitation (matches the legacy app's own level of protection here).
- **Controllers/routes**: `FiscalYearController` (index/store/close — `close` is `role:admin`-gated
  and additionally rejects targeting a `$next` year that already has vouchers posted, to stop
  double-seeding opening balances), `JournalVoucherController` (index/store — no update/destroy,
  vouchers are immutable), and a `ledger` method added to the existing `AccountController` (a
  read-only per-account, per-fiscal-year statement with a running balance, computed server-side).
  All wired in a new `routes/tenant-ledger.php`, required from `routes/tenant.php` right after
  `tenant-business.php` (same parallel-work file-splitting convention, mem.md gotcha #5).
- **Frontend**: `Tenant/Accounting/FiscalYears/Index.vue` (list + create modal + a "Close" action
  on the open row that opens a small modal to pick the next fiscal year), `JournalVouchers/Index.vue`
  + `JournalVouchers/Create.vue` (read-only list with a per-voucher line-detail modal, and a
  dynamic multi-line create form with live Dr/Cr balance feedback — **note**: there is no
  `GET /journal-vouchers/create` route; `Create.vue` is a plain component `Index.vue` toggles to
  in-place via a local ref, not a separate page, since adding a new route was out of scope for that
  build pass), and `Accounts/Ledger.vue` (per-account statement, fiscal-year picker) plus a small
  "Ledger" link added to `Accounts/Index.vue`'s row actions. "Fiscal Years" and "Journal Vouchers"
  were added to `resources/js/lib/nav-items.js`'s `ACCOUNTING` group (the shared nav module from
  the enterprise-UI pass) — no per-account ledger nav entry, it's reached only from the Accounts
  list.
- **Tests**: `tests/Feature/Tenant/Accounting/{FiscalYearTest,JournalVoucherPostingTest,
  FiscalYearClosingTest,LedgerControllerTest}.php` — 28 tests covering the invariants, the full
  posting engine including the closed-year override + roll-forward cascade, the P&L sweep +
  opening-balance-carry-forward math (verified against a concrete numeric scenario: 1000 cash
  sale, 400 cash expense → 600 net profit correctly credited to Profit & Loss and carried forward
  alongside Cash's 600 debit balance), and the HTTP-level routes/role-gating. Full
  `tests/Feature/Tenant` suite: 53/53. `npm run build` succeeds. Not yet manually smoke-tested in
  an actual browser (same caveat as the enterprise-UI pass above).
- **Deliberately out of scope for this pass** (real future work, not forgotten): a fresh tenant
  has to create its own first fiscal year manually (nothing auto-creates one during provisioning);
  no UI exists yet for the closed-year super-admin override path (the backend fully supports it —
  see `JournalVoucher::post()`'s `fiscal_year_id`/`reason` params — but `JournalVoucherController`'s
  create form only ever posts into the current open year); sales/purchase modules (which will post
  vouchers through this same `JournalVoucher::post()` engine) are the next roadmap slice now that
  the engine exists.

## Backend + Frontend: Sales, Purchase, Stock Adjustment (2026-08-26)

Built via 2 parallel forks (Sales, Purchase) the same day the ledger engine landed, then a follow-up
pass added Stock Adjustment via a 3rd fork alongside 3 more forks building the Reporting MVP (see
next section) — 4 forks running concurrently, zero file conflicts, via the established
pre-stub-routes-and-nav-myself-then-fork convention (mem.md gotcha #5).

- **Periodic, not perpetual, inventory accounting** — confirmed from an explicit docblock in
  legacy's `StockAdjustmentController`. Sales/Purchase/Stock Adjustment **never** post an
  inventory-asset/COGS ledger line; they only post money-side journal voucher lines
  (debtor/creditor, income/purchase, VAT, optional TDS). Stock quantity lives entirely in a new,
  ledger-decoupled `item_stock_movements` table.
- **`App\Enums\StockMovementType`**: `Purchase|Sale|PurchaseReturn|SaleReturn|Opening|AdjustmentIn|
  AdjustmentOut`, each with `direction(): int` (+1/-1). **`App\Models\ItemStockMovement`**:
  `item_id`, `movement_type`, `quantity` (decimal 4dp), `unit_cost_rate` (decimal 4dp, nullable),
  polymorphic `reference` (points at the SaleLine/PurchaseLine/StockAdjustmentLine that generated
  it), `date`, `cancelled` (bool — flipped true on cancel rather than writing inverse rows),
  `narration`. **`App\Models\Item`** gained `stockMovements()`, `recordStockMovement(type, qty,
  date, reference, ?unitCostRate)`, and `currentStock()` (sums signed quantities of non-cancelled
  movements via `movement_type->direction()`).
- **`App\Models\Sale::post()`/`Purchase::post()`** (static, `DB::transaction`, mirrors
  `JournalVoucher::post()`'s calling convention): computes taxable/nontaxable/VAT/total, builds a
  balanced voucher-line set, posts one `JournalVoucher`, creates the header + line rows, records a
  stock movement per stockable line. **Purchase unifies stock/service/capital purchase lines into
  one shape** by reusing `Item.account_id` (falls back to the seeded `EXE8` Purchases Account if
  null) — no `purchase_type` discriminator column, deliberately. **TDS is fully optional** —  a TDS
  leg only posts if the client supplies `tds_account_id` + `tds_amount > 0`, no hardcoded TDS
  account (none seeded yet). **Two `VoucherType` cases for Sale** (`Sale`/`SaleAbbreviated`) so
  Nepali dual invoice-numbering gets its own `VoucherSequence` counter per type, for free.
- **`Sale::cancel()`/`Purchase::cancel()`**: full-invoice cancellation only in this pass (no
  partial-line returns — see `goal.md` roadmap item 4 for that as a real, explicitly-deferred next
  slice). Posts a brand-new reversing voucher (every original line's debit/credit swapped) — the
  original voucher is **never** edited, matching the app-wide voucher-immutability rule. Flags
  every stock movement the sale/purchase generated as `cancelled=true` rather than writing inverse
  movement rows.
- **A real bug the Purchase build agent caught and fixed itself**: applying the header `discount`
  directly to the supplier-credit line without proportionally reducing the item debit lines left
  the voucher off-balance. Fixed via proportional reduction of vatable-line debit totals, with the
  last account absorbing the rounding remainder.
- **A legacy bug deliberately NOT carried forward**: legacy's `saveServicePurchaseReturn` credited
  VAT Payable (`LIA20`) instead of crediting back VAT Receivable (`ASA23`) on a service purchase
  return. **A legacy gap NOT carried forward**: normal stock-purchase cancellation was never
  actually wired up in legacy (dead table/controller) — this rewrite built it fresh.
- **`App\Models\StockAdjustment`** (`app/Models/{StockAdjustment,StockAdjustmentLine}.php`,
  `app/Enums/StockAdjustmentReason.php`: `Damage|Lost|Correction|Found|Opening|Other`, with
  `isZeroValue(): bool` true for Damage/Lost): `post()` mirrors `Sale::post()`'s shape but **never**
  touches the ledger. Forces `direction='in'` when `reason_type==='opening'` (opening stock is just
  another adjustment reason, not a separate bulk-import flow, unlike legacy's Excel-import screen —
  a deliberate simplification). Forces zero cost/value for Damage/Lost reasons server-side
  regardless of client input (matches legacy). Guards `out` lines against overselling via
  `lockForUpdate()` on the item's own `item_stock_movements` rows (same portable no-op-on-SQLite/
  real-on-MySQL pattern `VoucherSequence` already uses) — checked against `Item::currentStock()`.
  Rejects zero/negative quantity **at the model layer**, not just HTTP validation (a real legacy bug
  class: a negative `out` quantity once slipped past a client-only check and added stock instead of
  removing it). `cancel()` mirrors `Sale::cancel()` — **no edit method at all**, which sidesteps a
  separate real legacy bug (an edit-in-place path once wrote through a fiscal-year-filtered VIEW
  that silently matched zero rows for non-current-year records, leaving a cancelled header with
  stock still live).
- **Routes**: `routes/tenant-sales.php`, `routes/tenant-purchase.php`,
  `routes/tenant-stock-adjustments.php` (index/store/cancel each — no update, matching
  voucher-immutability). Nav: a new "TRANSACTIONS" group (Sales, Purchases) and a "Stock
  Adjustments" entry added to the existing "INVENTORY" group.
- **Tests**: `tests/Feature/Tenant/{Sales/{SalePostingTest,SaleControllerTest},
  Purchases/*,Inventory/StockAdjustmentTest}.php` — 25 tests total across the three modules
  (10 Sales, 15 Purchase, 7 Stock Adjustment — some overlap in the full-suite count below since
  route/controller tests are separate files).
- **A test-authoring gotcha from parallel Eloquent model work**: `AccountHead`/`AccountGroup`/
  `AccountSubgroup` were missing `use HasFactory;` (a pre-existing gap since nothing needed
  `::factory()` for them before) — needed for bank-account test fixtures. **Two independent forks
  fixed this identically with zero conflict** — worth knowing if a future fork hits the same
  missing-trait error on these models, it's a real gap, not a fork mistake.

## Backend + Frontend: Reporting MVP (2026-08-26)

Roadmap item 6. Legacy has a ~52-report `reportsController.php` (3,413 LOC, confirmed via research
pass); rather than port all of it speculatively, the user chose to build a prioritized MVP subset
first, re-evaluating what's next against actual usage. Built via 3 parallel forks (Accounting /
Sales-Purchase / Inventory), running alongside the Stock Adjustment fork above — 4 forks, disjoint
files, verified zero conflicts.

- **8 reports shipped**: Trial Balance, Income Statement (P&L), Balance Sheet (accounting reports —
  the 3 characterization-tested financial statements legacy's own migration plan calls out as exit
  criteria), Sales Register, Purchase Register, Sales VAT Book, Purchase VAT Book (Nepali
  VAT-compliance-mandatory tax-filing formats), Stock Summary (per-item opening/in/out/closing
  quantity + weighted-average valuation over a date range).
- **Zero new DB tables** — every report is a pure aggregation over `JournalVoucher`/
  `JournalVoucherLine`, `Account`→`AccountGroup`→`AccountSubgroup`→`AccountHead`, `Sale`/`SaleLine`,
  `Purchase`/`PurchaseLine`, `ItemStockMovement`. Legacy's equivalent queries were raw joins across
  `mainaccountledger`/`mainaccountledgerdetails` with a typo-repairing `normaliseAccountHead()`
  string-matcher and hacky date-boundary "opening bucket" logic to simulate a per-fiscal-year
  opening balance — none of that ugliness was needed here because the ledger engine already has
  real primitives (`FiscalYear`, an actual carried-forward `OpeningBalance` voucher) for what legacy
  had to fake.
- **Load-bearing correctness point, don't re-derive from scratch if touching these reports again**:
  computing "an account's balance in fiscal year N" means summing `journal_voucher_lines` scoped to
  `journal_voucher.fiscal_year_id === N` only (not across all years — the `OpeningBalance` voucher
  already carries the prior cumulative Balance Sheet position into year N). But if year N has been
  closed, a `ClosingEntry` voucher was posted **into that same year**, zeroing every P&L account
  within that year's own line set — so **Trial Balance and Income Statement must exclude
  `ClosingEntry`-type voucher lines**, or a closed year's P&L wrongly reports zero. **Balance Sheet
  must NOT exclude anything** — `ClosingEntry` only touches P&L accounts (not shown on a Balance
  Sheet) plus the seeded `"Profit & Loss"` retained-earnings account (`CA2`, correctly needs the
  closing entry's net-income effect folded in). This is tested directly: `AccountingReportTest`
  posts P&L activity, closes the year, and asserts the Income Statement for the now-closed year
  still shows the real net profit, not zero.
- **A real, deliberate deviation from the build brief, worth knowing about**: the Balance Sheet
  report adds a virtual `currentYearEarnings` line for a still-**open** fiscal year — computed as
  the same Income-minus-Expenses net used by the Income Statement (excluding `ClosingEntry`).
  Without it, Assets vs. Liabilities+Capital only balances *after* `FiscalYear::close()` sweeps net
  profit into `"Profit & Loss"` — an open year's unswept profit would otherwise just vanish from the
  Balance Sheet rather than merely be unbalanced. The virtual line disappears automatically once the
  year is closed (the real `ClosingEntry`-driven balance takes over) — verified in
  `AccountingReportTest`.
- **A real bug caught in the merge, not shipped**: `AccountingReportTest`'s `assertInertia`
  assertions initially compared float literals like `1000.0` against JSON-decoded values — PHP's
  `json_encode` drops the `.0` from whole-number floats, so the wire value comes back as an int
  `1000`, and Inertia's `where()` assertion does strict (`===`) comparison. Found by a sibling fork
  running the full suite mid-merge, fixed by the owning fork before final report. If a future report
  test asserts an exact numeric value via `assertInertia`, watch for this — cast/round on both sides
  or compare loosely.
- **Routes**: `routes/tenant-reports-{accounting,sales-purchase,inventory}.php`, all under a shared
  `/reports/*` prefix and `tenant.reports.*` name group. Nav: a new "REPORTS" group with all 8
  entries.
- **Frontend pattern**: all 8 pages extend the existing `Accounts/Ledger.vue` template (a filter →
  `router.get(window.location.pathname, {...}, {preserveState:true, preserveScroll:true})` →
  `Card variant="panel"` wrapper) rather than inventing a new report-page shape. The 3 accounting
  reports render a hierarchical head→group→subgroup→account tree manually (not `DataTable` — flat
  tables don't fit a nested statement); the other 5 are flat and use `DataTable` + a totals row.
- **Tests**: `tests/Feature/Tenant/Reports/{AccountingReportTest,SalesPurchaseReportTest,
  InventoryReportTest}.php`.
- **Deliberately out of scope for this pass**: the remaining ~44 legacy report views (Day Book,
  Cash Book, Bank Book, age-wise receivables/payables, item/group-wise breakdowns, etc.) — re-evaluate
  priority against real usage before picking the next batch, don't build speculatively.

## Backend + Frontend: partial-line Sales/Purchase Returns (2026-08-26)

Built via 2 parallel forks (Sales Return, Purchase Return) same day as the above, in a follow-up
pass after the user picked this as the next roadmap slice over more reports / a browser smoke-test
pass / committing. Upgrades cancel-only (full-invoice) voiding with real credit-note/debit-note
documents against specific original line quantities — closer legacy parity.

- **`App\Models\SalesReturn`/`SaleReturnLine`, `App\Models\PurchaseReturn`/`PurchaseReturnLine`**
  (new tables, each mirrors its parent Sale/Purchase's line shape plus a `rate`/`line_total` snapshot
  at return time). `SalesReturn::post()`/`PurchaseReturn::post()` (static, `DB::transaction`, same
  calling convention as `Sale::post()`/`Purchase::post()`): per return-line, guards over-return by
  summing prior return quantities against the same original line (`$remaining = original.quantity -
  sum(existing returns for this line)`), computes the returned amount from the original line's
  **post-discount effective unit price** (`line_total / quantity`), NOT a fresh rate — so multiple
  partial returns against the same line stay consistent with what was actually charged.
- **Money side**: Sales Return debits Sales Revenue (`INI20`) + VAT Payable (`LIA20`, reversing the
  original credit) and credits the Customer's account (a credit note — reduces receivable, or
  creates a customer credit balance if the sale was already cash/bank-settled). Purchase Return
  credits back the SAME per-item accounts the original purchase debited (grouped by
  `item.account_id ?? EXE8`, matching `Purchase::post()`'s own grouping — not a single hardcoded
  account) + credits VAT Receivable (`ASA23`, correctly — not `LIA20`, avoiding the exact legacy bug
  `Purchase::post()`'s own docblock already warns against) and debits the Supplier's account (a
  debit note).
- **Stock side**: writes a brand-new `StockMovementType::SaleReturn` (direction `+1`, goods
  physically return to stock) or `PurchaseReturn` (direction `-1`, goods physically leave stock)
  movement per returned line — this is the first code to actually use those two enum cases (Stock
  Adjustment only used `Opening`/`AdjustmentIn`/`AdjustmentOut`). Deliberately does NOT flag the
  original Sale/Purchase movement `cancelled=true` (unlike full `cancel()`) since only part of its
  quantity came back — the two movements coexist, netting out correctly in `Item::currentStock()`.
- **Two deliberate, documented simplifications (real gaps, not oversights)**: neither return
  proportionally reverses the ORIGINAL invoice's header-level discount (only the line's own
  rate/discount is used); neither reverses any TDS withheld on the original sale/purchase. Also: no
  return auto-generates a cash/bank refund voucher — it only posts the credit/debit note against the
  customer's/supplier's own account; an actual cash refund is a separate, manual follow-up action
  (out of scope for this pass).
- **Guard added to `Sale::cancel()`/`Purchase::cancel()`** (the only change made to those two
  existing files): both now throw `InvalidArgumentException` if any return line already references
  one of the original invoice's lines — prevents a full-invoice cancel from double-reversing money
  a partial return already reversed. Both models gained a `returns(): HasMany` relation.
- **Routes**: `routes/tenant-sales-returns.php`, `routes/tenant-purchase-returns.php`
  (index/store only — returns are themselves immutable in this pass, no cancel-a-return; that's a
  real, deliberately deferred next layer if it's ever needed). Nav: "Sales Returns"/"Purchase
  Returns" added to the existing TRANSACTIONS group.
- **Tests**: `tests/Feature/Tenant/{Sales/SalesReturnTest,Purchases/PurchaseReturnTest}.php` — 10
  tests total (5 each): balanced voucher + correct stock direction on a partial return, over-return
  rejection, return-against-already-cancelled-invoice rejection, cancel-after-return rejection,
  HTTP round-trip. Purchase Return's test additionally asserts VAT credits `ASA23` not `LIA20` and
  that different item accounts are credited back correctly (not one hardcoded account).

## Production hardening pass (2026-08-26)

`goal.md` roadmap item 7, picked as the next slice ahead of the browser smoke-test and the remaining
report batch. Built via 3 parallel forks on completely disjoint files (queued provisioning / 2FA /
CSP+headers), same convention as prior multi-fork passes. **Not yet verified by the test suite or
build** as of this update — the user asked this session to stop running `php artisan test`/`npm run
build` itself and will run them manually; all three forks confirmed `php -l` clean and did targeted
sanity checks (route registration, a real `tinker` round-trip through the TOTP/QR pipeline) instead.

- **Queued tenant provisioning**: `app/Providers/TenancyServiceProvider.php` flips both
  `shouldBeQueued(false)` → `true` (`TenantCreated` and `TenantDeleted` pipelines). A new
  `App\Jobs\CreateTenantFirstAdmin` job is appended to the `TenantCreated` pipeline after
  `SeedDatabase` — it re-fetches the tenant fresh (not the job-pipeline-passed instance, to sidestep
  any doubt about `data`-column decode state surviving queue serialization), creates the first admin
  user from a `pending_admin` payload, then flips the tenant to `Active`. `TenantStatus` gained a
  `Provisioning` case (initial state now, not `Active`). `TenantController::store()` no longer
  creates the admin user synchronously inside the request — it hashes the password immediately and
  stashes `name`/`email`/already-hashed `password` as `$tenant->pending_admin`, which
  `Tenant::getCustomColumns()` not listing it means it's swept into the `data` JSON column
  automatically (`vendor/stancl/virtualcolumn`'s `VirtualColumn` trait) and read back by the job once
  the tenant DB exists. `AbortIfTenantSuspended` (same class, no rename) now also 403s a request into
  a still-`Provisioning` tenant with a distinct message, firing on `TenancyInitialized` before the
  (possibly nonexistent) tenant DB connection is ever attempted. Central `Tenants/Index.vue` and
  `Show.vue` render a third `provisioning` badge state and hide suspend/resume while provisioning.
  Tests: extended `TenantProvisioningTest.php`, including a new test using `Queue::fake()` to
  actually freeze a tenant in `Provisioning` and confirm a request into its domain gets a clean 403
  rather than a missing-database error — not just relying on the `sync` test-queue driver making the
  gap invisible.
- **2FA for platform admins only** (not tenant users — matches `goal.md`'s explicit scope and the
  security doc's "central app is the highest-value target" reasoning). New `pragmarx/google2fa` +
  `bacon/bacon-qr-code` deps (QR rendered as an inline SVG data URI, no external QR image API — keeps
  it compatible with the new strict CSP's `img-src`). `platform_admins` gained
  `two_factor_secret`/`two_factor_recovery_codes` (both `encrypted`/`encrypted:array` casts, both in
  `PlatformAdmin`'s `#[Hidden]`) and `two_factor_confirmed_at` (a secret alone, pre-confirmation,
  doesn't count as enabled — `PlatformAdmin::hasTwoFactorEnabled()` checks the confirmed timestamp).
  Opt-in, not forced enrollment — the seeded dev admin keeps working unchanged until 2FA is
  deliberately turned on. Login flow: `AuthenticatedSessionController::store()` no longer calls
  `Auth::attempt()`; it validates credentials via the guard's provider directly
  (`retrieveByCredentials`/`validateCredentials`) so a 2FA-enabled admin isn't logged in yet — instead
  the pending admin id + `remember` flag + a 5-minute expiry go into session, and the request redirects
  to a new `TwoFactorChallengeController` (also under the existing `guest:platform` route group, since
  the admin genuinely isn't authenticated yet). The challenge accepts either a live TOTP code or a
  one-time recovery code (consumed from the stored array on use, can't be replayed); both the
  `login.store` and `central.two-factor.challenge.store` routes carry the same `throttle:5,1` this app
  already used for plain login. New `TwoFactorAuthenticationController` (behind `auth:platform`) owns
  enroll/confirm/disable — confirm issues 8 recovery codes shown exactly once in that response;
  disable requires re-entering the current password (`current_password:platform` validation rule) so a
  hijacked-but-still-open session can't silently strip the protection. New pages
  `Central/Auth/{TwoFactorChallenge,TwoFactorSetup}.vue`; one new link in the shared `AppLayout.vue`
  avatar dropdown (previously only "Log out"), gated on `auth.platformAdmin` being present. Tests: 8
  cases in `TwoFactorAuthenticationTest.php`, all driven through the real HTTP flow rather than direct
  model writes — enroll/confirm/recovery-codes-shown-once, invalid code rejected, disable requires
  password, a non-2FA login is unaffected, a 2FA login is redirected to the challenge instead of
  establishing a session, the challenge itself accepts/rejects TOTP and recovery codes correctly, and
  a used recovery code can't be replayed.
- **CSP + security headers**: new `app/Http/Middleware/SecurityHeaders.php`, appended in
  `bootstrap/app.php` alongside `HandleInertiaRequests`, applied to every response **except** when
  `app()->environment('local')` — Vite's dev-mode client injects a cross-origin `<script src>` for HMR
  that a strict `script-src 'self'` would block, and there's no browser available in-session to verify
  a dev-mode carve-out empirically, so local is simply exempted rather than guessed at (verify this
  properly the first time it actually matters). Ships `Content-Security-Policy: default-src 'self';
  script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self';
  connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'` (confirmed via grep
  there's no Ziggy, no `v-html`, no external font CDN, and no inline script anywhere — `script-src`
  stays strict; `style-src` needs `'unsafe-inline'` because of one real `:style=` binding in
  `Tenant/Dashboard.vue`), plus `X-Content-Type-Options: nosniff` and
  `Referrer-Policy: strict-origin-when-cross-origin` (`frame-ancestors 'none'` already covers what
  `X-Frame-Options: DENY` would). Test: `SecurityHeadersTest.php` asserts headers present under
  `production`, absent under `local`.
- **MySQL credential-role separation — scoped down to docs only, deliberately not app code**: new
  `deploy/mysql-credentials.md` + `deploy/mysql-grants.sql`. This can't be functionally built or
  tested right now (dev is SQLite, which has no concept of DB users/roles at all), and the security
  doc's own §7 says a single shared runtime DB user is an acceptable MVP default anyway. The docs cover
  only the two roles that actually apply to this rewrite — `tenant_provisioner` (CREATE/DROP DATABASE
  + schema DDL, used only by the provisioning job pipeline) and a least-privilege runtime app user
  (DML+SELECT only) — **not** the security doc's third `tenant_ddl_owner` role, since this rewrite
  deliberately never uses MySQL triggers/views (confirmed via `grep -rn "CREATE TRIGGER\|CREATE
  VIEW\|DB::statement" database/ app/` — zero matches, this is a locked-in `goal.md` architecture
  decision, not an oversight). Actually wiring the `.env`/`config/database.php` split is real future
  work once there's a MySQL deployment target to verify against — not built this pass.
- **Incidental side effect, not a deliberate change**: `composer require`ing the 2FA packages
  triggered Laravel Boost's own post-autoload hook, which auto-regenerated parts of `CLAUDE.md` and
  `boost.json` and added `.claude/skills/inertia-vue-development/` (a lowercase `pages/` path fix and
  a new skill-activation guideline). Harmless, unrelated to the app itself — left as-is.
- **Post-hoc fix #1 (same day, before first test run): `CreateTenantFirstAdmin` crashed on tenants
  created outside `TenantController::store()`.** The user ran the suite manually as instructed and hit
  115/132 failures, virtually all `ErrorException: Trying to access array offset on null` at
  `CreateTenantFirstAdmin.php:50`. Root cause: `pending_admin` is only ever set by
  `TenantController::store()`, but ~22 pre-existing test files across Sales/Purchase/Reports/etc.
  provision their test tenant via `Tenant::create(['company_name' => ...])` directly (bypassing the
  controller) and separately `User::factory()->create()` inside `$tenant->run()`. With
  `shouldBeQueued(true)` now on and `QUEUE_CONNECTION=sync` in `phpunit.xml`, `CreateTenantFirstAdmin`
  runs inline as part of that `Tenant::create()` call and blew up on the null payload, taking down the
  tenant setup for nearly every feature test in the suite — not a provisioning-only bug. Fixed by
  guarding `handle()`: if `pending_admin` is null, skip the `User::create()` step entirely but still
  flip the tenant to `Active` (so directly-created tenants — tests, tinker, future admin tooling —
  aren't left stuck in `Provisioning` forever). Confirmed via `git blame`-equivalent reasoning that the
  DB default for `status` is `'active'`, so these test tenants were never actually `Provisioning` in
  the first place — only the crash inside the pipeline was new. `php -l` clean.
- **Post-hoc fix #2 (same day, second test run): `TenantCouldNotBeIdentifiedById` on ~59 unrelated
  tenant tests.** After fix #1, the user re-ran the suite and hit a second, much wider regression:
  nearly every test that provisions a tenant (Sales/Purchase/Reports/Items/Customers/Suppliers/Ledger/
  Login/RoleMiddleware/Dashboard/BusinessPagesRender, plus the 3 tenant-lifecycle tests in Central)
  failed with `TenantCouldNotBeIdentifiedById` at `vendor/stancl/tenancy/.../QueueTenancyBootstrapper.php:93`.
  Root cause: `QueueTenancyBootstrapper` tags any job payload created while tenancy happens to be
  initialized with the current tenant's id (`getPayload()`), so the job re-initializes that tenant
  automatically when processed. Flipping `shouldBeQueued(true)` (this pass) changed the pipeline's
  dispatch from `dispatch_sync()` (bypasses the queue system's payload-creation hooks entirely) to a
  real `dispatch()` (goes through `Queue::createPayloadUsing()`, exactly what `QueueTenancyBootstrapper`
  hooks into) — so this tagging machinery was never exercised before this pass. If tenancy is left
  initialized at the moment a job payload is built (e.g. mid-pipeline, around `CreateTenantFirstAdmin`'s
  own `$tenant->run()` call, or any of the many pre-existing tests that do `$tenant->run(...)` around
  something that happens to dispatch a job), the job gets tagged with whatever tenant was active; once
  that tenant is gone (rolled back by test isolation) by the time the job is *processed*, re-identifying
  it throws.
  **First attempt was wrong and had zero effect** — added a `queue.connections.*.central` section
  inside `config/tenancy.php`, reasoning by analogy with `tenancy.database.central_connection` etc. But
  `QueueTenancyBootstrapper::getPayload()` reads `$this->config["queue.connections.$connection.central"]`
  off the **root** config `Repository` (it's typehinted `Illuminate\Config\Repository`, not scoped to
  the `tenancy` namespace) — that key resolves to `config/queue.php`, not `config/tenancy.php`. Confirmed
  by reading `vendor/stancl/tenancy/assets/config.php` (the package's own stub): it has no `queue`
  section at all, so this was never meant to live in `config/tenancy.php`. The user's third test run
  (56 failed, 76 passed — down from 59 only because fix #3 landed; the `TenantCouldNotBeIdentifiedById`
  list was byte-for-byte the same as the previous run) is what surfaced that the first attempt did
  nothing. **Actual fix**: added `'central' => true` directly to the `sync` and `database` connection
  arrays in `config/queue.php` (Laravel ignores unknown keys in a connection array for everything except
  this one listener) and removed the dead section from `config/tenancy.php`. This is the documented
  stancl/tenancy opt-out for connections used by central/administrative pipelines. Safe here because
  there are currently no genuinely tenant-scoped queued jobs in this app — both
  `App\Jobs\CreateTenantFirstAdmin` and stancl's own `CreateDatabase`/`MigrateDatabase`/`SeedDatabase`/
  `DeleteDatabase` already receive their `Tenant` directly via the constructor rather than relying on
  this bootstrapper's auto re-initialization. **This will need revisiting if a real tenant-scoped
  background job is ever added on these connections.** Lesson: when a package config key's *name*
  suggests a natural home in a related config file, verify where the code actually reads it (check the
  `$config[...]` scoping) rather than assuming — this cost a whole extra test run.
- **Post-hoc fix #3 (same run): 3 failing tests in `TwoFactorAuthenticationTest`, unrelated to fixes
  #1/#2.** A genuine test-isolation bug, not app code: the `twoFactorTestEnable()` helper calls
  `actingAs($admin, 'platform')` to drive enrollment through the real HTTP flow, but `actingAs()` leaves
  the `platform` guard authenticated for the rest of that test. The 3 failing tests all call the helper
  then immediately do a plain (non-`actingAs`) `POST /login` expecting a genuine guest request — instead
  `guest:platform` middleware saw the still-authenticated admin and redirected away before
  `AuthenticatedSessionController::store()` ever ran, so the 2FA-redirect/challenge assertions failed.
  (Test 7 already worked around this *between its own two internal logins* with an explicit
  `Auth::guard('platform')->logout(); session()->flush();` — just not right after the helper.) Fixed by
  adding that same logout+session-flush to the end of `twoFactorTestEnable()` itself, so every caller
  starts from a clean guest state. `php -l` clean on all files touched by fixes #2/#3.
- **Post-hoc fix #4 (fourth test run, after the corrected fix #2 landed): 5 failures**, all real tenant
  provisioning through the HTTP endpoint (`TenantProvisioningTest`, `TenantSuspensionTest`) —
  `Stancl\Tenancy\Resolvers\Contracts\CachedTenantResolver::__construct(): Argument #1 ($cache) must be
  of type Illuminate\Contracts\Cache\Factory, null given`, plus two knock-on `ModelNotFoundException`s
  in `TenantSuspensionTest` where the provisioning helper's `Tenant::where(...)->firstOrFail()` found
  nothing because the underlying `store()` call had crashed the same way. Root cause, verified by
  reading `Stancl\Tenancy\Database\Concerns\InvalidatesResolverCache`/`InvalidatesTenantsResolverCache`
  (used by stancl's base `Tenant`/`Domain` models, which `App\Models\Tenant` and this app's `domain_model`
  extend): every `saved`/`deleting` event on a Tenant or Domain unconditionally constructs
  `DomainTenantResolver`/`PathTenantResolver`/`RequestDataTenantResolver` (each needs `Factory $cache`
  injected) to invalidate a resolver cache — even though `CachedTenantResolver::$shouldCache` defaults
  to `false` and this app never touches it, so the invalidation itself is always a no-op; the crash is
  purely in constructing the resolver. `CacheTenancyBootstrapper` is the only code in this whole
  codebase that ever touches the `'cache'` container binding (via `Container::extend()` in its
  bootstrap()/revert(), swapping it to a per-tenant `Stancl\Tenancy\CacheManager` and back). Traced the
  exact `Container::extend()`/`resolve()` mechanics at length (confirmed bootstrappers ARE registered as
  real singletons in `TenancyServiceProvider`, confirmed `Factory::class` aliases to `'cache'` via
  `registerCoreContainerAliases()`, confirmed each bootstrap/revert pair looks self-balanced in
  isolation) without being able to pin the *exact* line that corrupts the binding to literal `null` —
  the new piece this pass introduced is that `tenancy()->initialize()/end()` now cycles multiple times
  per request (once each for `Artisan::call('tenants:migrate'/'tenants:seed', ...)` inside
  `MigrateDatabase`/`SeedDatabase`, then again for `CreateTenantFirstAdmin`'s own `$tenant->run()`) where
  before this pass admin creation was a single synchronous call and Domain::create() always ran *before*
  tenancy was ever initialized at all, never exercising this interaction. Also independently confirmed a
  real, separate latent hazard while tracing this: `Stancl\Tenancy\Database\Concerns\TenantRun::run()`
  and `Tenancy::runForMultiple()` have **no try/finally** — if the wrapped callback throws, `tenancy()->
  end()` is simply never called, leaving the DB/filesystem/cache bindings stuck mid-swap for the rest of
  the request. Not currently triggered (no evidence anything throws inside `CreateTenantFirstAdmin`'s
  callback — the seeder unconditionally creates the `admin` role), but worth remembering as a real vendor
  gap if a future job's callback can fail. **Fix applied**: confirmed via a full grep of `app/` that this
  app has zero direct `Cache::`/`cache()` usage anywhere, so tenant-scoped cache tagging has no
  functional value here. Removed `CacheTenancyBootstrapper::class` from the `bootstrappers` array in
  `config/tenancy.php` (and its now-unused `use` import) — this removes the only code path that ever
  swaps the `'cache'` binding, eliminating the crash's mechanism outright rather than chasing the exact
  corruption point. Revisit only if this app ever adds real tenant-scoped `Cache::` usage that needs
  isolation. `php -l` clean.
- **Post-hoc fix #5 (fifth test run, after fix #4): same 5 failures, different crash — "Undefined array
  key 'local'"** at the exact same call sites (`TenantProvisioningTest`/`TenantSuspensionTest` real
  provisioning). Fix #4 was correct and DID work (confirmed — the `CachedTenantResolver`/Factory$cache
  crash is completely gone), but it only removed ONE symptom of a more general defect, which then
  surfaced through the next stateful bootstrapper in line. Root cause (now understood precisely, thanks
  to the crash moving rather than disappearing): `Stancl\Tenancy\Database\Concerns\TenantRun::run()` and
  `Tenancy::runForMultiple()` (the latter used internally by stancl's own `tenants:migrate`/
  `tenants:seed` console commands, which `MigrateDatabase`/`SeedDatabase` jobs invoke via
  `Artisan::call()`) wrap their callback with **no try/finally**. Before this pass, admin creation was
  ONE synchronous `$tenant->run()` call and nothing else ever cycled tenancy state. Now the pipeline
  cycles `tenancy()->initialize()/end()` three separate times per provision (`tenants:migrate`,
  `tenants:seed`, then `CreateTenantFirstAdmin`'s own `$tenant->run()`) — and if anything throws inside
  ANY of those wrapped callbacks, `end()` is simply never called, `Tenancy::$initialized` stays stuck
  `true`, and the *next* initialize()/end() transition anywhere in the request unconditionally reverts
  **every** configured bootstrapper — including ones whose per-cycle "restore to this" state was never
  (re)captured this time. `CacheTenancyBootstrapper` hit this first (fix #4). With Cache removed,
  `FilesystemTenancyBootstrapper` hit the identical class of bug next: its `revert()` reads
  `$originalPaths['disks'][$disk]`, populated per-disk inside `bootstrap()` — when that capture never
  ran, `$disk = 'local'` (first in `config('tenancy.filesystem.disks')`) is the first missing key,
  matching the exact error. **Fix applied**: same grep-verified reasoning as fix #4 — this app also has
  zero `Storage::`/`storage_path()` usage anywhere (no tenant-scoped file storage exists), so
  `FilesystemTenancyBootstrapper` has no functional value either. Removed it from `config/tenancy.php`'s
  `bootstrappers` array (and its `use` import); confirmed the vendor's own `globalUrl` singleton
  registration in `TenancyServiceProvider::boot()` already guards with `$app->bound(FilesystemTenancyBoot
  strapper::class)`, so removing it from the list is safe on that front too. Remaining bootstrappers are
  `DatabaseTenancyBootstrapper` (its `revert()` — `reconnectToCentral()` — doesn't depend on any
  per-cycle captured state, just repoints to a fixed connection name, so it's immune to this defect
  class) and `QueueTenancyBootstrapper` (bootstrap()/revert() are literal no-ops). Neither can crash this
  way, so this should close out the whole defect class rather than just relocating it again — but that
  couldn't be fully confirmed without a fifth test run. The underlying vendor gap (missing try/finally)
  is NOT fixed and can't be from application code; documented in `config/tenancy.php`'s comment as a
  reason to reconsider before ever re-enabling either bootstrapper. `php -l` clean.

- **Post-hoc fix #6 (sixth test run, after fix #5): same "5 failed" count but a completely different
  failure shape** — `ModelNotFoundException`/"No query results for model [App\Models\Tenant]" in
  `TenantDeletionTest`, `TenantProvisioningTest`, and both `TenantSuspensionTest` cases (all of which
  provision a tenant via `central.tenants.store` first), plus "Session is missing expected key [errors]"
  in the subdomain-uniqueness test. Fix #5 genuinely worked (the Filesystem crash is gone) — this is a
  different, **pre-existing self-inflicted bug** in this same hardening pass, just unmasked once the
  bootstrapper crashes stopped hiding it. Root cause: `App\Listeners\AbortIfTenantSuspended` (added earlier in this same pass, for the "block
  requests into a still-provisioning tenant" feature) was registered
  as a listener on the generic `Events\TenancyInitialized` event in `TenancyServiceProvider`. That event
  fires for **every** `tenancy()->initialize()`/`$tenant->run()` call, including the ones the provisioning
  pipeline makes on itself (`tenants:migrate`, `tenants:seed`, `CreateTenantFirstAdmin`'s own
  `$tenant->run()`) — not just real inbound HTTP requests through `InitializeTenancyByDomain`. Since a
  brand-new tenant's `status` is `Provisioning` for the tenant's *entire* provisioning pipeline, the
  listener's own `abort(403, '...still being set up...')` branch fired on the very first internal
  initialize (inside `MigrateDatabase`'s `tenants:migrate` call) — killing the pipeline immediately. That
  `HttpException` bubbles up through the synchronous `dispatch()` (queue = `sync` in tests) and through
  `$tenant->save()` in `TenantController::store()`, into its `catch (Throwable $e)` block, which
  `rollBack()`s the wrapping transaction and re-throws — so the tenant row never really exists, the
  response Laravel renders is a 403 (not a redirect), and every test that provisions-then-looks-up a
  tenant hits `ModelNotFoundException`. The "subdomain must be unique" test failed for the identical
  reason: the *first* tenant in that test never actually persisted either, so the second POST found
  nothing to collide with. **Fix applied**: moved the check out of the `TenancyInitialized` event
  entirely and turned it into real HTTP route middleware — new `App\Http\Middleware\AbortIfTenantSuspended`
  (`handle(Request $request, Closure $next)`, reads `tenant()` directly), registered in
  `routes/tenant.php`'s middleware group right after `InitializeTenancyByDomain::class` (the only tenant
  entry point in this app — confirmed via grep, no `InitializeTenancyBySubdomain` usage anywhere). Removed
  the old `App\Listeners\AbortIfTenantSuspended` file and its `TenancyInitialized` registration (with an
  explanatory comment) in `TenancyServiceProvider`. Because it's now route middleware instead of a global
  tenancy event listener, it only ever sees genuine inbound requests into a tenant's domain — internal
  pipeline-driven `$tenant->run()` calls never go through HTTP routing, so they're no longer affected.
  Also fixed a now-stale comment in `TenantProvisioningTest.php` referencing the old "before any route
  middleware runs" framing. `php -l` clean on all four touched/added files. **Not yet re-verified by the
  user** — this was root-caused and fixed via pure static reasoning about the event vs. middleware timing
  difference, same as every other fix in this pass; no test was executed by the assistant.
  - **Lesson**: a `TenancyInitialized` (or any tenancy lifecycle event) listener that's meant to gate
    *end-user requests* must be scoped to the HTTP middleware layer, not the underlying tenancy event —
    the same event also fires for every internal/programmatic `$tenant->run()` call the app itself makes
    (migrations, seeding, background provisioning, admin tooling), and a status guard meant for requests
    will incorrectly fire there too, especially for a tenant whose current status is exactly the
    in-progress state the guard is designed to catch.

## How to verify the app is actually working, updated (2026-08-27)

Full suite: **132/132 tests, 811 assertions**, `npm run build` succeeding — verified directly by an
assistant session on 2026-08-27 (not just relayed from the user), after finding and fixing the
regression described in the top-of-file 2026-08-27 entry. This closes out the whole post-hoc
fix #1–#6 saga above: every failure mode hit during the hardening pass (queue tenant tagging,
the two vendor-bootstrapper try/finally crashes, the 2FA test-isolation bug, the
provisioning-guard-fires-on-internal-calls bug, and this session's
missing-database-file-crashes-before-the-guard-runs bug) is now fixed and covered by a passing
suite. If a future session sees a "not yet re-verified" note like the ones above again, don't just
trust it — rerun the suite first, the way this session did, since it already caught one place where
the actual state was much worse than what was written down.

**171/171 tests, 1513 assertions as of 2026-08-29 (second session)** — `npm run build` succeeds,
`vendor/bin/pint --dirty` clean, re-verified directly (not relayed) after the 5-fork reports batch
above.

**184/184 tests, 1709 assertions as of 2026-08-29 (third session)** — `npm run build` succeeds (after
fixing the `VatSummary.vue` `defineProps()` bug described above), `vendor/bin/pint --dirty` clean.

**185/185 tests, 1713 assertions as of 2026-08-29 (fourth session)** — after the first-ever real
HTTP-level smoke test found and fixed the `is_profit_and_loss` backfill bug described above.
`vendor/bin/pint --dirty` clean. This was also the first session to verify actual runtime behavior
against real (non-`RefreshDatabase`) tenant data, not just the automated suite — worth repeating
periodically against the two persistent dev tenants (`acme.localhost`/`test.localhost`) rather than
relying on the suite alone, since this bug is proof a green suite can hide a real defect that only
aged, pre-existing data exposes.

## Open items (also see `goal.md` roadmap)

- Chart-of-accounts/customers/suppliers/items, the enterprise UI redesign, the ledger/journal
  voucher posting engine, Sales/Purchase/Stock Adjustment, the Reporting MVP (now **20 reports** — the
  original 8, Day/Cash/Bank Book and Aged Receivables/Payables, TDS, Stock Valuation, Item-wise
  Sales/Purchase, Sales/Purchase/Stock-by-Category, then VAT Summary and Stock Movement Register),
  partial-line Sales/Purchase Returns, and the return-fidelity fixes (cancel-a-return, discount/TDS
  reversal, cash/bank refund) are all backend AND frontend complete, verified via the automated suite
  (185/185) and `npm run build`. **A real HTTP-level (curl-driven) smoke test of the golden path was
  done 2026-08-29 (fourth session) — see above — and it found a real bug (`is_profit_and_loss`
  backfill) the automated suite structurally could not catch.** This was NOT a real browser
  click-through, though — no JS execution, no console-error/visual/CSS check, no proof the Vue side
  actually hydrates and reacts correctly (only that the server returns correct Inertia JSON). **A true
  browser-based pass is still open** and worth doing once a browser automation tool (Playwright MCP or
  similar) is available in a session — every other item below is either a documented, deliberate scope
  limit or genuinely blocked on infrastructure that doesn't exist yet (a real MySQL target).
- Aged Receivables/Payables has a real, documented MVP gap: no payment-receipt feature exists, so a
  credit invoice settled via a generic Journal Voucher keeps aging forever — see the 2026-08-29
  section above.
- The remaining ~28ish legacy report views beyond the now-20-report set (invoice print list, ledger
  summary/sub-ledger, sales/purchase-with-notes, agent charges, capital services, damage-stock,
  outstock/instock raw listings, etc.) are, per a 2026-08-29 dedup pass, mostly either filter-variant
  duplicates of reports already built, already covered by the existing generic Account Ledger page, or
  tied to business concepts this app deliberately doesn't model (sales agents, capital/service purchase
  splits, item "company"/brand groupings) that would need a real feature decision first, not just a
  report — see legacy `app/Http/Controllers/reportsController.php` in the
  sibling `../day_khata` repo for the full method list, most of which are date-filter variants of a
  smaller set of real report types). Re-evaluate priority against actual usage before picking the next
  batch, same as every prior reporting pass.
- No UI yet for the closed-year correction override — **this is now DONE**, see the 2026-08-27
  session entry near the top of this file (was still open as of the last mem.md revision).
- MySQL credential-role separation is docs-only (`deploy/mysql-credentials.md`/`mysql-grants.sql`) —
  actually wiring dual DB connections is real future work, needs a real MySQL target to verify against.
- Queued/async operational pieces still missing: 2FA is opt-in (no forced-enrollment UX), and there's
  no queue-worker supervision/monitoring guidance yet for running `php artisan queue:work` in
  production now that tenant provisioning genuinely depends on a worker being alive. **Confirmed
  concretely, not just in theory, by the 2026-08-29 (fourth session) smoke test**: creating a tenant via
  the real HTTP flow does nothing until a `queue:work` process actually drains the job — this is
  exactly the missing-supervision risk this bullet already flagged, now with a first-hand repro.
- `database/` has ~1160 orphaned `tenant<uuid>.sqlite` files from past test runs whose physical DB file
  was never cleaned up (found during the 2026-08-29 fourth-session smoke test, not acted on). Pure disk
  clutter, not a correctness bug — safe to bulk-delete any `tenant*.sqlite` file whose UUID doesn't
  match a real row in the central `tenants` table, but do that only with explicit user sign-off, not
  unilaterally.
