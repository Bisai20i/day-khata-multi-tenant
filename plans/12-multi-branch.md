# Multi-branch support (plan, drafted 2026-11-06)

Status: DRAFT for review. Nothing here is built. Task chunks are sized for the ~150k-token subagent cap
(`.ai/rules/general.md`): 2-3 checkboxes each, chunks touching the same files run serially. Agents never run
tests, builds or migrations; the user does. Style: no em dashes in docs, code comments or UI text.

## 1. Goal and non-goals

**Goal.** A tenant (one company, one database) can run several branches and record transactions, see reports and
manage inventory per branch, with an owner view across all branches. "Branch" is a configurable label (branch,
site, outlet, project), so construction companies can call it a site. It stays an accounting feature, not a
project-management one.

**Non-goals (see section 12).** Separate legal entities or group consolidation across tenants, per-branch chart of
accounts or fiscal years, budgets, branch price lists, re-tagging of historical data, partial receipt and
discrepancy handling for transfers.

## 2. Locked decisions (agreed 2026-11-06, do not re-ask)

| Topic | Decision |
|---|---|
| Ledger | **One company ledger.** Every posted line carries a `branch_id`. No per-branch books. |
| Branch vs store | A branch is a finance and access unit. A **store stays a stock location and belongs to exactly one branch**. A central warehouse belongs to Head Office and feeds branches by transfer. |
| Never empty | `branch_id` is `NOT NULL` everywhere it exists. Every tenant gets a **Main** branch. Single-branch tenants see no change. |
| Where the tag lives | Document header **and** journal voucher lines. Lines are the source of truth for reports; headers drive lists, numbering and access. |
| Per-branch balance | **Every voucher balances within each branch.** Cross-branch postings get system "Inter-Branch Clearing" lines (hub and spoke, section 5.3). |
| Shared masters | Items, categories, brands, units, customers, suppliers, agents, areas, chart of accounts, roles, settings, fiscal years. Party balances are shown per allowed branch. |
| Inter-branch stock | Two-step (dispatch, receive) with an in-transit state. Ownership and accounting move at **dispatch**. Valued at company weighted average cost (perpetual classes: the item's moving average via `PerpetualCosting::issueCost`). Receive is all-or-nothing in v1. |
| Numbering | Default **shared** series. Per-branch series is a per-fiscal-year scope, changeable only before that year's first numbered voucher. Format to be confirmed with a Nepali CA (section 13). |
| Access | User sees all branches, or an assigned subset with a default branch. Enforced server-side by a query scope, not only in forms. Owner always sees all. |
| Entitlement | New central module `branches`. No separate tenant on/off flag: the branch selector appears when the tenant has two or more active branches. |
| Cut-over | **By transfer, not by re-tagging.** Existing history stays in Main. Populated stores cannot change branch. |
| Reports | Branch filter on every report, plus "by branch" columns on the headline reports. Branch balance sheet ships, labelled a management report. |

## 3. Research that shaped this (summary)

Zoho Books (Locations: business vs warehouse, per-user location access, per-branch number series), QuickBooks
(location and class are tags only, single-location inventory), Xero (2 tracking categories cap), ERPNext (branch as
accounting dimension, mandatory flags, untagged entries vanish from filtered reports), Tally (cost centres plus
godowns, separate company only per tax registration, branch current accounts), Odoo (transit location for
inter-branch moves), SAP Business One (branch P&L fine, balance sheet vague, cost-centre visibility not restricted
per user), NetSuite (keep finance location and warehouse apart). Consequences adopted here: dedicated indexed
`branch_id`, never empty, branch separate from store, inter-branch clearing accounts, in-transit transfers, access
enforced in data not UI. Full source list is in the 2026-11-06 discussion; branch-specific Nepal VAT rules could
not be verified (section 13).

## 4. Facts about the current code that constrain the design

Verified by reading the code on 2026-11-06.

1. **One posting choke point.** Every module posts through `JournalVoucher::post()`, which calls `write()`.
   `validateLines()` normalises lines; `reverse()`, `rollForward()` and `FiscalYear::close()` build lines
   themselves via `write()`. Lines are immutable (`JournalVoucherLine` throws on update and delete), so history
   can never be re-tagged through the model.
2. **Periodic inventory for trading items.** `StockTransfer` and `StockAdjustment` never touch the ledger.
   Gross profit comes from Opening Stock, Closing Stock and Stock in Hand accounts. `FiscalYear::close()` posts
   `postStockTradingEntries()` using one company-wide `StockCosting::totalPeriodicClosingValue()`, then
   `postClosingEntries()` (sweeps P&L accounts into "Profit & Loss") and `postOpeningBalances()`.
   A branch P&L therefore needs a **per-branch closing stock**, and a transfer between branches **must** post a
   voucher or the sending branch's gross profit is understated and the receiving branch's overstated.
3. **Cost is company-wide, quantity is per store.** `StockCosting` documents "no per-store cost". A branch's
   closing stock is therefore the sum of its stores' quantity times the company average cost, which is additive
   across branches (total equals the sum of branches).
4. **Perpetual classes** (raw material, WIP, finished goods) are real-time in AS12/AS14 and guarded by
   `InventoryReconciliation` (invariant P1: subledger equals GL). That invariant must hold per branch.
5. **`store_id` already exists** on sales, purchases, returns, stock adjustments, transfers, conversions, capital
   documents, manufacturing documents and `item_stock_movements`. `users.store_id` (nullable, null means all) and
   `company_settings.default_store_id` exist. `stores.view` / `stores.manage` permissions and pages exist.
6. **Numbering.** `VoucherSequence` is one gapless counter per `(fiscal_year_id, voucher_type)`;
   `journal_vouchers` has a unique `(fiscal_year_id, voucher_type, voucher_number)`. Invoice numbers are
   `{prefix}-{voucher_number}` (`Sale::invoiceNumberFor`) with a unique `(fiscal_year_id, invoice_number)`.
7. **Receipts and payments** post two lines (settlement account, party account) and then write allocation rows
   against invoices. Cash is account code `AS1`. Parties have a ledger `account_id`.
8. **Reports read lines directly.** `AccountingReportController` (ledger, trial balance, income statement, balance
   sheet, day, cash and bank books) aggregates `JournalVoucherLine` through `whereHas('journalVoucher')`, with
   sweep-voucher detection and a live `stockPosition()`.
9. **The working tree is dirty.** `git status` showed ~60 modified files (models, controllers, exports) at session
   start. Phase 0 must start from a committed, green baseline (section 7).

## 5. Architecture

### 5.1 Data model (tenant DB)

New tables:

- `branches`: `id`, `name` (unique), `code` (unique, max 10), `series_code` (nullable, unique, max 6, used only
  in per-branch numbering), `address`, `phone`, `email`, `pan_number` (nullable, print override),
  `default_store_id` (nullable FK stores), `is_main` (exactly one true), `is_active`, `sort_order`, timestamps.
  Branches are never deleted; Main can never be deactivated.
- `branch_user`: `(branch_id, user_id)` composite primary key.

New columns:

- `users.all_branches` (bool, default true), `users.default_branch_id` (nullable FK). `users.store_id` stays one
  release as deprecated, then is dropped.
- `stores.branch_id` NOT NULL FK. **Immutable once the store has any movement or document** (invariant I5).
- `branch_id` NOT NULL FK on the tables in 5.4, `journal_voucher_lines.counterparty_branch_id` (nullable, set only
  on clearing lines), `journal_vouchers.series_branch_id` and `voucher_sequences.series_branch_id` (unsigned big
  integer NOT NULL default 0, no FK, 0 means the shared series), `fiscal_years.numbering_scope`
  (`shared` | `per_branch`, default `shared`), `stock_transfers.from_branch_id` / `to_branch_id`,
  `dispatched_at`, `received_at`, `received_by`, `journal_voucher_id`, and status values `in_transit`, `received`.
- `company_settings.branch_label` (`branch` | `site` | `outlet` | `project`, default `branch`).

New chart accounts (codes chosen against `ChartOfAccountsSeeder` in BR17, added to existing tenants by a data
migration): **Inter-Branch Clearing** (balance sheet, net asset or liability), **Stock Transferred to Branches**
(trading credit), **Stock Received from Branches** (trading debit). New `VoucherType::BranchStockTransfer`
(own gapless sequence).

### 5.2 Invariants (each has a test and a read-only audit)

- **I1** Every voucher balances per branch: for each `branch_id` among its lines, debits equal credits.
- **I2** Completeness: no null branches, and for every account the sum over branches equals the old company-wide
  balance.
- **I3** Inter-Branch Clearing nets to zero across all branches; Stock Transferred equals Stock Received.
- **I4** Per-branch closing stock values sum to the company closing stock value.
- **I5** `stores.branch_id` never changes after first use; a store-bearing document has `branch_id` equal to its
  store's branch.
- **I6** A restricted user can neither read nor post outside their branches.
- **I7** Each `(fiscal_year, voucher_type, series_branch_id)` series is gapless.
- **I8** Perpetual P1 holds per branch (value of the branch's stores equals the branch's AS12/AS14 balance).

### 5.3 Posting engine (BR04, the heart of the change)

`JournalVoucher::post()` / `write()` gain:

1. Header `branch_id` (origin branch). Missing means `BranchContext::postingBranchId()` (actor's active or default
   branch). System callers with no user (year close, roll forward) pass it explicitly. This keeps all ~30 existing
   call sites working before any module is touched.
2. Each line may carry `branch_id` and `counterparty_branch_id`; default is the header branch.
3. `validateLines()` enforces **I1**. A caller opts into `autoBalance: true`, in which case `BranchBalancer` appends
   Inter-Branch Clearing lines **hub and spoke around the origin branch**: every other branch gets one clearing
   line against the origin (`counterparty_branch_id` = origin) and the origin gets the mirror lines. Hub and spoke
   keeps results deterministic and gives a pairwise "who owes whom" report. Without `autoBalance` an imbalance
   throws.
4. `reverse()` mirrors `branch_id` and `counterparty_branch_id`. `rollForward()` carries `branch_id` per line.
5. Opt-in callers: manual journal, receipts and payments (allocation to other branches' documents), salary
   payments and staff advances, cross-branch transfers. Document postings (sale, purchase, returns) are
   single-branch and need no balancing.

Rule for party lines: the party (receivable or payable) line is tagged with the **branch of the document it
settles**; the cash or bank line is tagged with the branch where money moved. Example: branch A receives 100 for
B's invoice. A: Dr Cash 100, Cr Clearing 100. B: Cr Customer 100, Dr Clearing 100. B's receivable falls, A's cash
rises, and the clearing balances A owes B.

### 5.4 Per-table rules

| Table(s) | Branch rule |
|---|---|
| `sales`, `purchases`, `sales_returns`, `purchase_returns`, `quotations`, `capital_purchases`, `capital_sales`, `stock_adjustments`, `stock_conversions`, `material_issue_notes`, `production_entries` | `branch_id` NOT NULL; must equal `store.branch_id` where a store exists. Linked returns inherit the original's branch. |
| `receipts`, `payments` | `branch_id` = where money moved. Allocations may target other branches' documents (5.3). |
| `stock_transfers` | `from_branch_id` and `to_branch_id`; equal means single-step, different means two-step with a voucher. |
| `journal_vouchers`, `journal_voucher_lines`, `voucher_sequences` | As 5.1 and 5.3. |
| `staff_members`, `payroll_lines`, `payroll_payments`, `staff_advances` | Staff has a home branch; payroll expense and payable lines follow the staff member's branch; the paying cash or bank line follows the payment's branch (clearing handled by 5.3). |
| `fixed_assets` | `branch_id`; depreciation lines follow the asset. Asset transfer between branches is deferred. |
| `item_stock_movements`, stock reports | **No new column.** Branch is derived through `store_id`. |
| Items, parties, accounts, roles, settings, fiscal years | Shared. |

Cash and bank accounts stay shared. A branch's cash or bank balance is "what was posted under that branch" (Zoho
model); the real bank balance is the total across branches.

### 5.5 Inventory

- Each branch has `default_store_id`. A document picks a branch, the store defaults to the branch's default store,
  and the store dropdown only offers stores of that branch (and the user's allowed stores).
- **Branch closing stock** = sum over the branch's stores of quantity times company average cost. `StockCosting`
  gains `storeIds` (array) alongside the current `storeId`, so the existing single-store path is unchanged.
- **Same-branch store transfer:** unchanged (no voucher).
- **Cross-branch transfer (periodic items), at company average cost on the transfer date:**
  sender branch Dr Inter-Branch Clearing, Cr Stock Transferred to Branches; receiver branch Dr Stock Received from
  Branches, Cr Inter-Branch Clearing. Stock movement out at dispatch, in at receipt. Consolidated, both trading
  accounts and the clearing account net to zero. Where the item has no average, fall back to `purchase_rate` as
  `StockCosting` already does. Perpetual-class lines (raw material, finished good) are instead valued by
  `PerpetualCosting::issueCost`: the item-wide moving average with its own fallbacks, refusing the transfer when
  there is no cost basis (and when stock would go below zero).
- **In transit:** status `in_transit` after dispatch. Receive posts the in-movement. Cancel before receipt reverses
  the voucher and movement. **Fiscal year close is refused while any transfer is in transit** (same style as the
  `InventoryReconciliation` gate), so valuation never needs an in-transit adjustment. Stock reports show an
  "In transit" column.
- **Shortage or damage on receipt:** receiver records an ordinary Stock Adjustment afterwards.
- **Perpetual classes:** cross-branch transfer was blocked by validation in BR18 and is delivered in BR25.
  Within one branch they work as today.

### 5.6 Year-end close and open (BR04)

`FiscalYear::close()` becomes per branch, all inside the same transaction and the same vouchers:

- `postStockTradingEntries()`: opening stock from the Stock in Hand balance **per branch**, closing stock from
  `totalPeriodicClosingValue` over each branch's stores. Lines tagged per branch.
- `postClosingEntries()`: net balance per `(account, branch)`; each branch's P&L lines sweep into "Profit &
  Loss" tagged to that branch. Each branch stays balanced.
- `postOpeningBalances()`: opening lines per `(account, branch)`; the Inter-Branch Clearing balance carries per
  branch. By I1 each branch's balance-sheet accounts sum to zero.
- `netBalance()` gets a branch dimension. Sweep-voucher detection in `AccountingReportController` is unchanged.
- Refuse to close if I1 or I3 fail or a transfer is in transit.

### 5.7 Access control

`App\Support\Branches\BranchContext` (request scoped): `allowedBranchIds(): ?array` (null means unrestricted),
`activeBranchId()` (from session, validated against allowed), `postingBranchId()`.

- Trait `BelongsToBranch` registers `BranchAccessScope` (`whereIn branch_id`) on: all document models, `Store`,
  `JournalVoucher`, `JournalVoucherLine`, `StockTransfer` (visible if either end is allowed),
  `ItemStockMovement` (through allowed stores). The scope is active **only for restricted users**; unrestricted
  users (owner, all-branch users) run unchanged queries, so the common path has zero overhead and zero behavior
  change.
- System code that needs the whole truth (year close, audits, imports, reversal lookups, numbering) runs inside
  `BranchContext::unrestricted(fn)`.
- Active branch (UI filter) and allowed set (security) are separate: the scope uses the allowed set; lists and
  reports narrow further by the active or selected branch.
- A restricted user sees party balances limited to their branches. A cross-branch voucher shows a notice that
  lines of branches they cannot access are hidden.
- Posting: a document's branch, and any allocation target's branch, must be in the actor's allowed set.
- Leak audit test: every model that uses the trait has the scope, and a table-driven test hits each list, show
  and report route as a restricted user and asserts no foreign row appears.
- **Documented exception, HR (decided in BR10):** `StaffMember`, `PayrollRun`, `PayrollLine`, `PayrollPayment` and
  `StaffAdvance` are NOT branch-scoped on purpose (balances, payroll locks and the HR sub-ledger need every staff
  member). HR is a company-wide function: a restricted user who holds `hr.*` permissions sees and acts on all staff,
  and the users page says so. The BR22 leak audit must list the HR list/show/discard/edit routes as an explicit,
  asserted exception (the posting engine still refuses lines in branches the actor cannot use, so HR posting is
  covered by I6). Revisit with a controller access check only if a customer needs branch-limited HR clerks.

### 5.8 Numbering (BR20)

`fiscal_years.numbering_scope` decides how that year's series is keyed. `shared`: `series_branch_id` = 0
(today's behavior, byte-identical numbers). `per_branch`: `series_branch_id` = branch id, and the printed number
becomes `{prefix}-{branch.series_code}-{n}` (exact format subject to section 13). The scope can change only while
the fiscal year has no vouchers of numbered types, owner only. The unique indexes on `journal_vouchers` and
`voucher_sequences` are rebuilt to include `series_branch_id` **in create-new-then-drop-old order** so the
`fiscal_year_id` foreign key is never left without an index (`.ai/rules/migrations.md` trap 3).

### 5.9 Reports

New `App\Support\Branches\BranchFilter` (modelled on `AreaFilter`): request `branch` (empty means all allowed, id,
or `branches[]`), garbage degrades to "all allowed", always intersected with the allowed set. `by_branch=1` adds
branch columns. Every PDF and Excel export prints "Branch: X" or "All branches" in its header.

| Group | Reports | Filter | By-branch columns |
|---|---|---|---|
| Accounting | ledger, trial balance, income statement, balance sheet, day book, cash book, bank book | yes | trial balance, income statement, balance sheet |
| Sales and purchase | registers, VAT books, TDS, item-wise, summary reports | yes | sales and purchase summary |
| Parties | debtors, creditors, ageing, party ledger | yes (party balance per branch) | debtors, creditors |
| Stock | valuation, ledger, movement register, ageing, category, brand, damage and lost | yes (via stores) | valuation, movement |
| Manufacturing | production and WIP reports | yes (via stores) | no |
| New | Inter-branch balances (pairwise, from `counterparty_branch_id`), branch comparison (sales, purchases, gross profit, expenses, net profit per branch) | n/a | n/a |

Income statement and balance sheet for one branch use that branch's stores for `stockPosition()` and its own
`unsweptProfitAndLoss`. Branch statements are management reports: shared costs sit where posted until a manual
allocation journal moves them.

### 5.10 UI

- Top-bar branch switcher (hidden with fewer than two active branches), values: allowed branches plus "All
  branches" (read-only contexts; forms fall back to the user's default). Shared Inertia props `branch.current`,
  `branch.allowed`, `branch.isMulti`; composables `useBranch` and `useBranchLabel`.
- Document create pages: branch selector (defaults from the active branch), store follows branch. POS fixes the
  branch from the active selection.
- List pages: branch column and filter, remembered per user.
- Admin: Branches page (CRUD, assign stores, default store, print details), Users page gets branch access and
  default branch, Stores page gets a branch field (read-only once used).
- New pages: stock transfer dispatch and receive, inter-branch balances, branch comparison.
- Files stay near 500 lines (`.ai/rules/js.md`): selector, switcher and filter are components or composables.

### 5.11 Entitlement and permissions

`config/permissions.php` (coordinator only): module `branches` (requires `accounting`; not `inventory`, because a service business with several offices and no stock is a valid branch user). New keys:
`branches.view`, `branches.manage`, `branches.report` (comparison and inter-branch reports),
`stock_transfers.receive`. Existing keys keep their meaning. A new module is off for existing tenants (rule in
`.ai/rules/config.md`); no data migration grants it. Every new route gets a `can:` gate and a row in
`todo/permissions/ROUTE-MAP.md` (`.ai/rules/routes.md`).

## 6. Migration and cut-over strategy

**Migrations (tenant, expand only).** Follow the `add_store_id_*` pattern: create `branches`, insert Main inside the
migration, add each `branch_id` with `default($mainId)` and FK, backfill. Backfill is derivable and idempotent:
documents and stores get Main; lines and vouchers get Main. All data migrations freeze constants and read no config
(`.ai/rules/migrations.md`). Existing users: `all_branches = true`; users with `store_id` keep access to all
branches (they were not branch scoped before) and `default_branch_id` = Main. Chart accounts are added by a
separate idempotent data migration.

**Cut-over by transfer.** Branches are created freely. A store may change branch only while it has no movement and no
document. To move a business into a branch: create a new store under the branch, then run an ordinary cross-branch
transfer for the stock, and a manual cross-branch journal (auto-balanced) for any cash, bank or party balance that
should move. All of it is audited and immutable like normal accounting. History is never re-tagged. Recommendation
shown in the Branches page help text: switch on branches at the start of a fiscal year.

## 7. Rollout and rollback

Preconditions: commit or park the ~60 uncommitted files so Phase 0 starts from a clean, green baseline.

Order: deploy code, central migrate, `php artisan tenants:migrate`. Then phases ship dark (the module is off, the
selector hidden, everything resolves to Main).

Gates (the user runs the commands; the coordinator lists the exact files per commit):

1. After Phase 0 and Phase 1: **the existing test suite passes unchanged** and a single-branch parity test shows
   every report, PDF and export is identical to before for a Main-only tenant.
2. After Phase 3: invariants test and `branches:audit` on a copy of a real tenant.
3. Pilot tenant switched on from the central panel before general availability.

Rollback: all migrations have `down()`. Once a tenant has more than one active branch with data, reverting to
single-branch is data-lossy, so the central panel blocks switching the module off for such a tenant (allowed only
while all data is in Main). Phase 0 and 1 are fully reversible.

## 8. Performance and MySQL

- `journal_voucher_lines`: add `branch_id` and index `(branch_id, account_id)` (explicit short name). Adding a NOT
  NULL column with a default plus an FK on a large table can lock: rehearse on a copy of the biggest tenant, record
  the timing in `mem.md`, schedule a window if needed.
- Document tables: index `(branch_id, date)` where lists sort by date.
- Unrestricted users add no predicate. Restricted users add one `IN` list on an indexed column.
- Reports: branch predicate goes on the lines table directly, not through `whereHas`.
- Budget: single-branch report time within 10 percent of today's on the same copy; one new query at most per
  request for branch context (cached in the request).
- MySQL checks that SQLite hides: FK type matches parent (`branches.id` bigint), index names under 64 characters,
  create-new-then-drop-old unique indexes, no MySQL-only DDL. Full migrate plus full rollback on MySQL before
  shipping.

## 9. Task chunks

Route files, `nav-items.js`, `config/*` and dependency manifests are coordinator-edited
(`.ai/rules/js-lib.md`). Workers list "cross-file requests". Reserved migration timestamps start at
`2026_11_10_100000`. Owned-file lists are indicative; the coordinator finalizes them per chunk. Every worker prompt
repeats the three rules from `.ai/rules/general.md` (read minimally, no re-reads, stop near 150k tokens).

### Phase 0: foundation (zero behavior change)

- [x] **BR01 Branches core.** (written and review-verified; tests not yet executed) `branches`, `branch_user`, `Branch` model, factory, seeder hook; Main created per
  tenant (`TenantDatabaseSeeder` and migration); `users.all_branches`, `users.default_branch_id`;
  `stores.branch_id` with immutability guard; module `branches` and permission keys in `config/permissions.php`
  (coordinator). Tests: exactly one Main, Main cannot be deactivated or deleted, store branch immutability,
  catalog integrity.
- [x] **BR02 Schema and backfill A (documents).** (written and review-verified; tests not yet executed) `branch_id` on the sales, purchase, return, quotation, capital
  and stock-document tables, default to Main, FK and indexes. Tests: backfill on a legacy-shaped DB, idempotent
  rerun, rollback.
- [x] **BR03 Schema and backfill B (ledger, HR, assets).** (written and review-verified; tests not yet executed; MySQL rehearsal still owed) `branch_id` on `journal_vouchers` and
  `journal_voucher_lines` (plus `counterparty_branch_id`), receipts, payments, staff and payroll tables, fixed
  assets, `stock_transfers` columns. Same test shape. Includes MySQL rehearsal notes.
- [x] **BR04 Branch context and posting engine.** `BranchContext`, `BelongsToBranch`, `BranchAccessScope`,
  `BranchBalancer`; changes to `JournalVoucher::post/write/validateLines/reverse/rollForward`, `FiscalYear::close`
  per-branch trading, closing and opening entries, `netBalance` branch dimension. Tests: I1 (balanced, unbalanced
  throws, `autoBalance` hub and spoke with 3 branches), reversal mirrors branches, roll-forward keeps branches,
  year close per branch keeps each branch balanced, single-branch output identical to before. Split into two
  agent runs: (a) engine and balancer, (b) fiscal-year close and open.
  - [x] (a) engine and balancer (written; tests not yet executed). `BranchContext`, `BelongsToBranch`,
    `BranchAccessScope`, `BranchBalancer`, `JournalVoucher` post/write/validateLines/reverse/rollForward,
    trait on `JournalVoucher` and `JournalVoucherLine`. Clearing account resolved by name
    "Inter-Branch Clearing" until BR17 gives it a protected code.
  - [x] (b) `FiscalYear::close` per-branch trading, closing and opening entries, `netBalance` branch dimension.
    (written; tests not yet executed). Same vouchers as before with lines grouped per branch (Main first, then by
    id), header branch Main; Main takes the closing-stock rounding remainder (I4 exact); I3 refusal and the BR18
    in-transit slot live in `FiscalYear::assertBranchBooksCanClose()`. Opening clearing lines carry no
    `counterparty_branch_id` (open point for BR19).

### Phase 1: stamp every posting path

- [x] **BR05 Sales side.** Sale, POS, sales returns, quotations, sale notes: branch selection, store validation,
  branch on lines. Linked returns inherit. (written; tests not yet executed) Shared resolver
  `App\Support\Branches\DocumentBranch` (forDocument / forStoreDocument / inherited / assertStoreInBranch), all
  failures are InvalidArgumentException. POS posts through `Sale::post()`; sale note templates are a shared master
  with no branch.
- [x] **BR06 Purchase side.** Purchase, purchase returns, capital purchases and sales. (written; tests not yet
  executed) All four use `DocumentBranch` (`forStoreDocument`; linked returns `inherited`, refund voucher in the
  return's branch). Capital documents carry a store, so they are store documents (default store now follows
  `DocumentBranch`, i.e. the company default store when it is in the branch). A capital asset gets its bill's
  branch. Cancel blockers, bill-number duplicate checks and the voucher lookups before `reverse()` in these four
  models run inside `BranchContext::unrestricted()` (strike them from the BR10 list).
  **Carry-forwards to BR07:** `Payment.php` ~280 `Purchase::whereIn(...)` allocation lookup and
  `CapitalPurchaseSettlement` (lock of the bill at :100 and :178, settlement voucher posted with no branch) need
  the unrestricted wrapper and the 5.3 party-line rule; `capital_purchase_settlements` has no `branch_id` column.
- [x] **BR07 Receipts and payments.** Branch of receipt and payment, allocations across branches using
  `autoBalance` and the party-line rule of 5.3, customer and supplier settlements for capital documents. This is
  the highest-risk chunk of the phase, give it its own review.
  (written; tests not yet executed; `tests/Feature/Tenant/Branches/BranchReceiptPaymentTest.php`) Decisions:
  `Receipt`, `Payment`, `CapitalPurchaseSettlement` use `BelongsToBranch`; `branch_id` = where money moved
  (`DocumentBranch::forDocument`, request `branch_id` optional). **Party-line rule for unallocated money:** the
  on-account remainder is tagged with the receipt's or payment's own branch (it settles no document yet); each
  allocation's share goes to its document's branch; party lines are one per branch, ascending branch id; receipt
  order is cash/bank first then customer lines, payment order is supplier lines then cash/bank (as before);
  clearing lines are appended by the balancer (hub = receipt/payment branch). All-in-one-branch output is the
  same two lines as before. Allocation targets are locked inside `unrestricted()` and then checked with
  `BranchContext::canAccessBranch` (I6), so a restricted user allocating to a document of a branch they cannot use
  (or an inactive branch) gets a form error. Capital settlement: supplier line in the bill's branch, cash/bank in
  the settlement's branch. `BranchRuleViolation` (missing clearing account) is converted to
  `InvalidArgumentException` in the three models and caught in `JournalVoucherController::store`.
  Outstanding reads (`Sale::outstandingAmount`, `Purchase::outstandingAmounts`, `CapitalPurchase::settledAmount`)
  and the sale/capital-purchase cancel blockers, `SalesReturn::alreadyCredited` (cap + C6 remainder) and the SAL-07
  commission remainder reads run inside `unrestricted()`. Sale.php has no invoice-number uniqueness read (the
  number comes from the gapless voucher series and a unique index), so nothing to wrap there. Manual journal:
  optional header and per-line `branch_id`, `autoBalance` always on, `distinct` on account replaced by one line per
  (account, branch). There is no customer-side capital settlement model (capital sales on credit are not
  receipt-allocatable today). Review fixes: Receipt and Payment take their document row locks (sales ascending;
  supplier then purchases ascending) before the first plain SELECT of the transaction (MySQL snapshot, same rule
  as `SalaryPaymentPoster`); a hidden, missing, foreign or cancelled allocation target gives one message checked
  before anything else (no oracle); the receipt PDF prints "Invoice in another branch" for a hidden invoice; the
  manual-journal duplicate rule keys on (integer account id, effective branch); `PurchaseReturn::prepareLine`
  wraps only the earlier-returns reads. Settlement keeps the actor-posting-branch default; `SalaryPaymentPoster`
  deliberately defaults to the payslips' branch. Still open: `JournalVoucher::sourceRecordLabel` wrapper (file
  not owned by BR07).
  **Carry-forwards from BR05:** once `Receipt`/`Payment` use `BelongsToBranch`, (a) `Sale::cancel`'s
  `receiptAllocations()->whereHas('receipt', ...)` blocker check must run inside `BranchContext::unrestricted()`,
  otherwise a restricted canceller cannot see a cross-branch receipt and could cancel a paid sale; likewise the
  purchase-side cancel check; (b) `Receipt.php` ~245 `Sale::whereIn(...)->lockForUpdate()` drops sales outside a
  restricted user's branches, so allocation lookups need the unrestricted wrapper.
  **Sales-side wrappers owed (found in BR06 review):** BR06 wrapped these reads in `BranchContext::unrestricted()` on the
  purchase side; the sales side has none yet. Add them in `Sale::cancel` (partial-returns blocker, `receiptAllocations`
  blocker), the `SalesReturn` quantity-cap and last-return remainder reads, and the sale invoice-number uniqueness
  check. Also wrap `JournalVoucher::sourceRecordLabel()` (~1058) in `unrestricted()`: once cross-branch vouchers exist a
  restricted user would get null for a voucher whose source record is in a branch they cannot see.
- [x] **BR08 Stock and manufacturing documents.** Adjustments, conversions, material issue, production entries,
  same-branch transfers; per-branch perpetual reconciliation (I8) in `InventoryReconciliation`.
  (written; tests not yet executed) Placement via `App\Models\Concerns\PlacesStockDocument` (DocumentBranch,
  keeping the legacy lowest-id-active-store default while it is in the branch). Every perpetual voucher and its
  reversal is filed in the document's branch; opening-stock re-import replaces only its own branch's batch.
  Transfers stamp `from_branch_id = to_branch_id`; two branches are refused until BR18; transfers get
  `BranchAccessScope` with an either-end predicate. `InventoryReconciliation::checkBranch/checkByBranch/
  assertBranchesReconciled` added; `check()`/`assertReconciled()` unchanged and the year-end gate still uses
  only the company-wide check (wire the per-branch gate in after BR06).
- [x] **BR09 HR and assets.** Staff home branch, payroll posting split by branch, salary payments and advances with
  clearing, fixed asset branch and depreciation tagging. Phase 0 already made `branch_id` NOT NULL (default Main) on
  `hr_staff`, `hr_payroll_runs`, `hr_payroll_lines`, `hr_payroll_payments`, `hr_staff_advances`, and HR code treats a
  missing header branch as Main; this chunk adds the staff-form branch picker and the real multi-branch split. Also: `FixedAsset::postDepreciationForAsset`
  (FixedAsset.php ~465) calls `JournalVoucher::write()` with no branch, so depreciation lands in the closing user's
  posting branch (Main for console auto-close); pass the asset's `branch_id` as the `$branchId` argument.
  (written; tests not yet executed) Branches resolved through `DocumentBranch` (all failures InvalidArgumentException;
  `BranchRuleViolation` from a missing clearing account is converted). A run's `branch_id` is its origin; payslips may
  span branches (`all_branches` on generate) and each posts expense, Salary Payable and recovery in its staff
  member's branch, so the payroll voucher balances per branch with no clearing (posted without autoBalance on
  purpose). Payments and advances: cash or bank line in the paying branch (default: the payslips' or staff
  member's branch), payable or receivable line in the staff side's branch, `autoBalance`. Staff cannot change home
  branch while an advance is outstanding. `FixedAsset` uses `BelongsToBranch`; purchase, registration,
  depreciation and disposal post in the asset's branch. UI picker is BR12; `CapitalPurchase` asset creation is BR06.
- [ ] **BR09b Drop the Main defaults.** After BR05 to BR09, every posting path sets `branch_id` explicitly. Expand-only
  migration that drops `DEFAULT <Main id>` from every `branch_id` column added in BR01 to BR03, so a path that forgets
  the branch fails loudly instead of silently landing in Main. Gate: the single-branch parity test and a grep audit of
  every create path. Do this only after the pilot confirms no path relies on the default.

### Phase 2: management UI and access

- [x] **BR10 Branches admin.** Controller, requests, Branches page, stores assign, user access and default branch,
  `branch_label` setting. Routes and nav by the coordinator.
  (written; tests not yet written or executed; routes and nav pending the coordinator) `BranchController` (index,
  store, update, deactivate, reactivate, updateLabel; writes need access to every branch on top of
  `branches.manage`), `Store/UpdateBranchRequest`, `UpdateBranchLabelRequest`, `company_settings.branch_label`
  (migration `2026_11_10_200000`, edited on the Branches page, not Company Setup). Main may be renamed but never
  deactivated or un-Mained (model guard). Deactivation guard: `App\Support\Branches\BranchDeactivationGuard::blockers()`
  (read-only, unrestricted, reusable by BR23): unpaid credit sales, unpaid purchases, capital bills with a balance,
  unpaid payslips, staff with an outstanding advance, active fixed assets, draft quotations, pending sales return
  requests, production in progress, draft payroll runs, transfers `in_transit`, and items with non-zero stock in the
  branch's stores (the last one added beyond the carry-forwards: stranded stock is the same dead end), plus active staff based in the branch. Store branch
  field on the Stores page (read-only once used); `Store::save()` wraps a branch change in a transaction and
  `Store::updating` locks the row before the usage check. User branch access (`UserBranchAccess`): all/subset/default,
  escalation guard like roles, owner always all, `BranchContext::flush()` after sync. HR (b): documented exception in
  5.7, no code. Unrestricted wrappers added per the prerequisite list (see the BR10 report for the exact list).
  **Review fixes:** a branch-restricted admin gets 403 on any edit of a user whose current access reaches further
  (`UserBranchAccess::actorReachesUser`, also hides Edit); stores: explicit checks (no `BelongsToBranch` on `Store`),
  create resolves the branch via `postingBranchId()` then checks it, update/destroy need reach over the store's
  current branch, index lists only reachable stores; Branches index narrowed for restricted viewers (no assignment
  counts); new `active_staff` blocker; `StaffController::homeBranchChange` short-circuits an unchanged branch;
  unchanged default store not re-validated, deactivating a store clears it as a branch default; credit sales and
  capital bills shortlisted in SQL (integer paisa) before `outstandingAmount()`; blocker examples shown only with the
  matching view permission; branch form requests authorize on full branch reach.
  **Still open:** (1) ~~posting paths read a store's branch without a lock~~ Done: `DocumentBranch::storeBranchId`
  now reads the store's branch with `sharedLock()` inside the posting transaction, so a store move waits for (or is
  seen by) the posting. (2) Deactivation is not serialised against postings (they share-lock the branch row only in a
  per-branch numbering year, via `SeriesNumber::seriesBranchFor`); a document filed in the same instant can land in a just-deactivated branch; BR23 should list open
  items in inactive branches. (3) `InventoryReconciliation::check()` deliberately left scoped (BR15 F1).
  **BR05 carry-forwards:** `Store::updating` checks store usage without a lock, so a store's branch could change while
  a document posts against it; lock the store row and re-read before the usage check. BR12 forms must send `branch_id`
  and map branch/store errors to the `branch_id`/`store_id` fields (sales currently report them under `lines`).
  A pending return request whose branch is later deactivated can only be rejected, not approved (I6, intended).
  **BR09 carry-forwards:**
  (a) Deactivating a branch must be refused while it holds unpaid payslips, outstanding staff advances, active fixed assets
  or open documents (the engine refuses inactive branches on every posted line, so these would dead-end: payslips cannot be
  paid, advances cannot be recovered, assets cannot be disposed); tell the user which items to clear. No payroll-only
  bypass; if a settle-only exception is wanted later, add one engine option used by BR07 and BR09 together.
  (b) HR rows (`StaffMember`, `PayrollRun`, `PayrollLine`, `PayrollPayment`, `StaffAdvance`) are deliberately NOT
  branch-scoped (balances and payroll need all staff). Before any restricted user exists, either add an access check at the
  HR controllers (list/open/discard/edit) or add them to the 5.7 leak audit explicitly.
  (c) More lookups for the unrestricted list: `JournalVoucher::sourceRecordLabel` (~1049-1058) incl. FixedAsset,
  `AccountController::documentFor` (~491), `StaffBalances` (:172, :195), `HrReportController` (:117,:136,:148,:192
  `reversalJournalVoucher` loads), and `firstOrFail()` voucher fetches in the three HR posters' cancel (404 for a restricted
  user otherwise).
  (d) `FixedAssetController` manual depreciation for a restricted user only covers their branches; adjust the "all assets
  already posted" message. Annotate `plans/11-hr-staff-payroll.md:45` (single-branch run) as superseded by BR09.
  **Prerequisite, before any restricted user can exist:** the JournalVoucher/JournalVoucherLine access scope filters
  every query for a restricted user, so these system-purpose queries must run inside `BranchContext::unrestricted()`
  (or use one helper that bypasses the scope), found in the BR04a review: `VoucherSequence.php:73`;
  `CompanySettingController.php:193`; `Account.php:91,:138`; `AccountGroupController.php:86`;
  `FiscalYear.php:407,:687` and `FiscalYearController.php:90` and `FiscalYearArchiver.php:216` (done in BR04b);
  `InventoryReconciliation.php:191`; `SalesReturn.php:879,:884,:1802,:1848`; `ProductionEntry.php:405,:713,:825`;
  the `->journalVoucher()->firstOrFail()` lookups before `reverse()` in Sale, Purchase, PurchaseReturn, Receipt,
  Payment, CapitalSale, CapitalPurchase, CapitalPurchaseSettlement, MaterialIssueNote, StockAdjustment,
  `PayrollPoster`, `SalaryPaymentPoster`, `StaffAdvancePoster`; `StaffBalances.php:172,:195`;
  `AccountController.php:748`; "closing entry posted" detection at `AccountingReportController.php:986,:1073,:1188`
  and `ManufacturingReports.php:641`. Report/list queries are scoped on purpose and are handled per report in BR13+.
  **BR07 carry-forwards:**
  (e) Deactivating a branch must also be refused while it holds invoices, bills, capital purchases or settlement balances
  with an outstanding amount (the I6 allocation checks refuse inactive branches, so they could never be settled, even by
  the owner).
  (f) Wrap `AccountController::documentFor` (~462-502) in `unrestricted()` so a restricted user still gets the source label
  of a receipt/payment filed in a hidden branch.
  (g) A receipt filed in branch B2 that settles a B1 invoice is invisible to B1 users (the `Receipt` scope is plain). Product
  call: widen the scope like `JournalVoucher`'s, or leave. Recommendation: leave until restricted users exist.
  (h) The salary payment default branch (the payslips' branch) is a deliberate exception to "actor's posting branch" used by
  receipts, payments and settlements; do not harmonise.
  After syncing a user's branches call `BranchContext::flush()` (`branches()->sync` fires no model event). Consider a
  `(journal_voucher_id, branch_id)` index on `journal_voucher_lines` for the scope's EXISTS probe if volume is large.
- [x] **BR11 Context plumbing.** (written and review-fixed; tests not yet written; gated on the `branches` module, Main-only tenants get the minimal shared shape with one cached branches read) `HandleInertiaRequests` shared props, switcher component, `useBranch`,
  `useBranchLabel`, middleware that sets `BranchContext`, active-branch endpoint.
- [x] **BR12 Forms and lists.** (written in 12a/12b/12c and reviewed by reading; nothing run or built. Decisions: per-store stock
  on sale/POS forms stays company-wide (Sale::post checks per store); the printed branch line shows only while the company is
  multi-branch; a transfer's to-store is limited to the from-store's branch until BR18/BR19 widen it; the opening-stock import
  has no branch picker yet. Cleanup candidate for BR22: three store-option helpers overlap (`OffersDocumentBranch`,
  `BranchStoreOptions`, purchases' private `storeOptions()`); fold them into one.) (BR11 review carry-forwards: (1) the user's active branch selection steers every
  `BelongsToBranch` create that has no explicit `branch_id` (trait stamps `postingBranchId()`), so audit that every child
  row/system-created record follows its parent's branch explicitly; (2) list filters must read the selection through
  `ActiveBranchSelection::viewingAll()`/current, never from inside route-model binding; (3) the selector default on forms
  comes from the shared `branch.current` prop and refreshes on switch.) Branch selector on document create pages, branch column and filter on list pages,
  store follows branch, POS branch handling. Shared small components to keep files under ~500 lines.
  **BR07 carry-forwards:** receipt, payment, capital-settlement and manual-journal forms need branch pickers; the settlement
  form preselects the bill's branch when the user may use it (no clearing lines in the normal case); branch errors currently
  arrive under `amount` or `lines`. `CapitalPurchaseController` (~:211) settlements list is scoped while `outstanding` is
  company-wide, so a restricted user's list does not add up: add an "other branches" aggregate line. Receipt print/show for a
  restricted user shows hidden invoices as "Invoice in another branch" with the applied amount only.
  - [x] (a) shared foundation and accounting forms (written; tests not written or executed).
    `BranchFormOptions` (choices/defaultId/props/errorField), `BranchListFilter` (apply/columnVisible),
    `BranchSelect.vue`, `BranchBadge.vue`, `useBranchField.js`, `useBranchListColumn.js`; receipts and payments
    (picker, list filter, branch column, branch chip on open invoices/bills, restricted receipt list strips hidden
    invoice ids), capital settlements (modal extracted to `CapitalPurchaseSettleModal.vue`, bill branch preselected,
    "paid from other branches" line on page and PDF for restricted viewers), manual journal (header plus per-line
    branch; per-line errors on `lines.N.branch_id`). M3 audit: every row these four flows create (receipt, payment,
    settlement, voucher header, voucher lines, clearing lines, reversal) gets an explicit branch_id; allocations
    carry none. Not done here: cash/bank voucher picker, journal and capital purchase list filters (12b/12c).

### Phase 3: reports

- [x] **BR13 `BranchFilter` and accounting reports.** Ledger, trial balance, income statement, balance sheet, day,
  cash and bank books, per-branch `stockPosition` and `unsweptProfitAndLoss`, by-branch columns.
  (written and reviewed by reading, not run: `BranchFilter`, `BranchReportTotals`, `AccountingReportController`, the
  `branchReport` prop and `BranchReportFilter` / notes / cells components on the seven pages. `AccountController::ledger`
  (account/party ledger) not filtered yet, left for BR14 with its page. The COGM/COGS and inventory schedule links from
  the income statement and balance sheet do not carry the branch yet: BR15.)
- [x] **BR14 Sales, purchase, VAT, TDS and party reports.** Registers, VAT and TDS books, item-wise, debtors,
  creditors, ageing, party ledger.
  (written and reviewed by reading, not run. Filter on the document's `branch_id` for document reports and on
  `journal_voucher_lines.branch_id` for party balances and the account ledger; `by_branch` columns on debtors and
  creditors; `PartyBranchBalances`, `BranchFilter::applyToDocuments/rowBranch`. The "Branch: X" header on the ledger and
  party Excel/PDF is BR16.)
- [x] **BR15 Stock reports.** Valuation, ledger, movement, ageing, category, brand, damage and lost;
  `StockCosting::storeIds`.
  (written and reviewed by reading, not run: `BranchFilter::storeIds`, `BranchStock`, `StockCosting::branchValuationRows`,
  F1 reconciliation via `checkBranch()` sums. Still company-wide, to fold into BR16/BR22: Items list stock, POS, sale-form
  stock checks, Dashboard.)
  **BR08 carry-forward (F1):** `InventoryReconciliation::check()` reads GL lines through the access scope while the stock side is
  company-wide, so for a restricted user `ManufacturingReports::reconciliation()` (:269) shows false differences. Build that
  report from `checkBranch()` over the user's allowed branches and sum the rows; do not wrap `check()` in `unrestricted()`.
- [x] **BR16 Comparison, dashboard, exports.** Branch comparison report, dashboard branch filter and comparison
  widget, branch header on every PDF and Excel export.
  (written and reviewed by reading, not run: `BranchComparisonReportController` + `BranchComparisonExport` +
  `BranchComparison.vue`, shown in the Reports index only while multi-branch; `DashboardController` follows the filter
  (documents on `branch_id`, cash/debtors/creditors on line `branch_id`, stock via store sets) with
  `BranchDashboardComparison`; branch label on 7 report PDFs and 9 report Excel exports. Still company-wide: Items list
  stock, POS and sale-form stock checks (BR22/23); cancelled-documents report has no branch filter.)

### Phase 4: inter-branch

- [x] **BR17 Accounts and voucher type.** (written 2026-11-10; tests not written or run per the no-new-tests rule.
  Codes ASA25 / INI23 / EXE11 on `App\Support\Branches\BranchAccounts`; migration
  `2026_11_10_300000_add_inter_branch_accounts`. Existing branch tests that factory-create the clearing account by
  name need updating to the seeded ASA25 account.) Three chart accounts, seeder, idempotent data migration for existing
  tenants, `VoucherType::BranchStockTransfer`. Also: give Inter-Branch Clearing a protected code in
  `Account::SYSTEM_CODES` and switch `BranchBalancer::clearingAccountId()` and `FiscalYear::assertBranchBooksCanClose()`
  from the name lookup to the code; extend the year-end I3 check with Stock Transferred = Stock Received.
  Also (BR07 review): add Inter-Branch Clearing to the manual-journal control-account guard (like
  `ManufacturingAccounts::assertNotControlAccounts` / `HrAccounts`), so a one-sided manual line cannot break I3.
- [x] **BR18 Two-step transfer.** (written 2026-11-10 and reviewed by reading; no tests written or run, routes pending the
  coordinator. Two vouchers, each balanced inside one branch: dispatch in the sender (Dr ASA25 cp receiver / Cr INI23),
  receipt in the receiver for the stored amount (Dr EXE11 / Cr ASA25 cp sender). Lines valued at average cost on the
  transfer date (purchase-rate fallback), rate rounded to 4dp then value = qty x rate, stored in the existing
  `unit_cost_rate`/`line_value`/`total_value`; migration `2026_11_10_400000` adds `receipt_journal_voucher_id`,
  `reversal_journal_voucher_id`, `received_on`. Close gate refuses in-transit first. Open for BR19: all-stores stock
  reports leave transfers out of Qty In/Out but closing qty drops while a transfer is in transit (in-transit column);
  `AccountController::documentFor` / `JournalVoucher::sourceRecordLabel` do not map the transfer vouchers yet.)
  Dispatch, receive, cancel, vouchers, stock movements, close-year gate, blocks
  perpetual items. Tests: consolidated nets (I3), sender and receiver gross profit, cancel in transit.
  Carry-forwards from the Phase 0 review: `stock_transfers` has no `reversal_journal_voucher_id`, add it (cancel
  before receipt reverses the dispatch voucher); `StockTransfer.status` is a plain string, so audit every
  `status === 'posted'` check when adding `in_transit` and `received`; add `stock_transfers.receive` and
  `branches.view` to the `MANAGER` role template (not `branches.manage` or `branches.report`).
- [x] **BR19 Transfer UI and reconciliation.** (written 2026-11-10 and reviewed by reading; no tests written or run,
  route pending the coordinator. Dispatch and receive UI were already delivered in BR18 (Create form, ReceiveModal).
  (A) `FiscalYear::postOpeningBalances` carries ASA25 per (branch, counterparty) from the year's lines grouped by
  `counterparty_branch_id` (opening lines included, so it accumulates); null-counterparty lines stay one unattributed
  line per branch; split re-checked against the branch net; skipped with Main alone (parity). Helper
  `App\Support\Branches\InterBranchBalances`. (B) `App\Support\Branches\StockInTransit` (as-at semantics follow the
  movements; `asBooked` also counts transfers cancelled after the date): Stock Summary gets `transit` per row and
  `inTransit` buckets within / outgoing ("Dispatched, awaiting receipt") / incoming, only when multi-branch and
  non-empty; the transfer list gets an in-transit banner and a `status` filter. Not touched: valuation, ledger,
  movement register, ageing, category/brand, Items list, Dashboard (they show no all-stores In/Out identity that
  transit breaks, or are still company-wide per BR15/BR16). (C) `InterBranchBalancesReportController` +
  `Reports/InterBranchBalances.vue` + `components/branch/InterBranchPairsTable.vue`: pairs with each side's books,
  transit-explained vs unexplained differences, unattributed bucket, per-branch net, company check (unrestricted
  only), transfers in transit / received, INI23 / EXE11 ledger vs transfers. No export. Transfer vouchers mapped in
  `JournalVoucher::sourceRecordLabel` and `AccountController::documentFor`.)
  Dispatch and receive pages, in-transit stock column, Inter-branch
  balances report. Design point: year-end opening entries carry Inter-Branch Clearing lines WITHOUT
  `counterparty_branch_id`, so a pairwise "who owes whom" view cannot be rebuilt from opening vouchers; decide
  whether to carry the counterparty on those lines (split the clearing balance per counterparty at close) before
  building this report.

### Phase 5: numbering and print

- [x] **BR20 Per-branch series.** (written 2026-11-10 and reviewed by reading; no tests written or run, route pending the
  coordinator. Migration `2026_11_10_500000_add_per_branch_numbering`: `fiscal_years.numbering_scope`, `series_branch_id`
  (NOT NULL default 0) on journal_vouchers, voucher_sequences, material_issue_notes (F3) and hr_payroll_runs (PAY run
  number has the same collision), uniques rebuilt new-then-old; down() refuses while branch-series rows exist.
  `VoucherSequence::claimNext` is the one counter (JournalVoucher::write and ProductionEntry; production entries stay on
  the shared series in every year). Series key = header branch; reversal, roll-forward and year-end vouchers take their
  own header branch's series (Main for year end). `JournalVoucher::seriesNumber/printedNumber/displayNumber` +
  `App\Support\Branches\SeriesNumber::format` print `{prefix}-{series_code}-{n}` in per_branch years only; every
  document number built from a voucher number goes through them. A branch without a series code cannot post in a
  per_branch year; a used series code is frozen (Branch::booted). `App\Support\Branches\NumberingScope::change`: owner,
  module on, year open or never opened (deviation: a next year gets its opening voucher the moment it opens), no voucher
  in the year, every active branch coded, no shared starting number; activity-logged. UI card on the Fiscal Years page
  (multi-branch only). Starting numbers refused in per_branch years. Gaps: archived-year browser, JV search by number,
  MI/Production list pages show the bare number.)
  `numbering_scope`, `series_branch_id`, index rebuild, `Sale::invoiceNumberFor`
  and the other numbered documents, owner-only scope change with guard.
  **BR08 carry-forward (F3):** `material_issue_notes` has `UNIQUE(fiscal_year_id, number)` and `number` is the voucher number, so
  per-branch numbering would collide; add the branch to that index or decouple the note number.
- [x] **BR21 Print.** (written and reviewed by reading; no tests written or run, nothing built) Branch address, phone, email and PAN on invoice, receipt and voucher headers. `App\Support\Branches\BranchPrintDetails::for(?Branch, CompanySetting)` returns per field the branch value if non-empty else the company value; `forView()` finds the document in the view data (sale, salesReturn, purchase, purchaseReturn, quotation, receipt, voucher, capitalSale/Purchase, stockAdjustment/Conversion, stockTransfer by `from_branch_id`, material issue note, production entry), applies the override only when the `branches` module is on and the company has more than one active branch, and reads the document's own Branch row by id (Branch has no access scope). One view composer in `AppServiceProvider` (on `pdf.layout`, `pdf.sale`, `pdf.sale-receipt`) supplies `$branchPrintDetails`; `layout.blade.php`, `sale-receipt.blade.php`, `ird/_company.blade.php` and `ird/abbreviated.blade.php` (boxed PAN) read it instead of `$company->address/phone/email/pan_vat_number`. Company name, logo and footer are unchanged; the PAN keeps its existing label. Main-only, module off, no overrides or no branch: same values, same markup. The IRD invoice formats print no phone today, so none is added. The Branches admin form gained one explanatory line.

### Phase 6: hardening

- [x] **BR22 Invariants and parity.** Property-style test posting a random mix across branches then asserting I1 to
  I4, restricted-user leak audit (I6), single-branch parity, `record-rule` entries for the durable decisions
  (once the Boost MCP server is reachable).
  (Done 2026-11-10 by READING, because new tests are barred: four read-only audits (restricted-user leaks, posting and
  stock invariants, single-branch parity, leftovers) and three fix batches, each re-reviewed by split reviewers.
  Fixed: company-wide stock on Items/POS/sale form now follows the actor's stores with a scope label; report and
  setting store dropdowns narrowed; activity log, print log and archived-year browser filtered for restricted
  actors (`BranchMorphVisibility`, archive branch columns); restricted actors cannot close/reopen/relock a year;
  generic store/branch refusal for hidden branches; Day Book/JV type filters, Stores/Users/chart-of-accounts parity;
  cancelled-documents branch filter; dispatch-date guard; `FixedAsset::dispose` lock order; the inter-branch
  accounts migration moves a colliding tenant account aside instead of throwing. Accepted/deferred: opening-balance
  re-import is company-wide, `receive()` does not re-check the destination store is active, a hard-deleted document
  hides its own-branch log history from restricted users, `FiscalYear` correction log text may name another
  branch's voucher, "All reports" nav for a pure `branches.report` holder in a Main-only company, stale `store_id`
  on sales/purchase/VAT report URLs, archive branch names, per-store stock on POS/sale form beyond the switcher,
  Excel comparison column, `documentFor` N+1, Users-page branch list not narrowed for restricted managers.
  `record-rule` entries are still pending (Boost MCP unreachable). The HR routes in section 5.7 are the asserted
  company-wide exception.)
- [x] **BR23 `branches:audit` command.** Read-only per-tenant check of I1 to I3 and I5, plus store and document
  branch mismatches; output suitable for the pilot dry run.
  (written 2026-11-10 and reviewed by reading; no tests written or run. `php artisan branches:audit [--tenant=ID ...]
  [--details|-v] [--json]`: default every tenant with the `branches` module, `--tenant` audits named tenants even with
  the module off (copy of a real tenant); exit 1 on any FAIL, unknown tenant or unreadable database, WARN exits 0.
  Logic in `App\Support\Branches\BranchAudit::run(?asOf)` returning `Audit\BranchAuditResult` objects (key,
  invariant, status, message, count, first 20 items), all reads inside `BranchContext::unrestricted()`, query builder
  aggregates with scaled-integer sums, a throwing check becomes a FAIL. Checks: Main sound; I1 per (voucher, line
  branch); I2 header/line/counterparty/document branch tags; I3 clearing per fiscal year company-wide and per pair
  against `StockInTransit::valueByBranchPair(year end)`, INI23 less EXE11 against the change in transit; I4
  `StockCosting::branchValuationRows` split vs row and vs `totalClosingValue`, movements in stores without a branch;
  I8 `InventoryReconciliation::checkByBranch` for the open year; I5 stores and default stores, store-bearing
  documents and transfer ends vs store branch, every stock movement vs its document's branch; I6 document voucher
  headers (incl. reversals, refunds, transfer legs, depreciation, production costs) vs document branch, cross-branch
  receipt/payment/settlement allocations without a clearing line in the settled branch; I7 gapless series with gap
  positions, counters equal to the highest number, series keys vs numbering scope, voucher series = header branch.
  WARN: unattributed clearing balances, transfers in transit with age, shared series starting above 1, active
  branches without a series code in an open per-branch year, inactive branches with open work
  (`BranchDeactivationGuard::blockers`). Not checked: I6 access (behaviour, tests), store immutability history (no
  audit trail; a moved used store shows as I5 movement mismatches), material issue / payroll run document numbers.)
- [x] **BR24 Central rollout.** (written 2026-11-10 and reviewed by reading; no tests written or run, config change
  pending the coordinator: label "Multiple branches", a `description`, and `branches` out of `default_modules`.
  Module checkboxes now show an optional per-module `description` (PermissionCatalog, TenantController,
  ModuleSelector). `App\Support\Branches\BranchModuleGuard::blockers()` (inside tenancy, unrestricted, reusable by
  BR23) / `forTenant()` (central; no database = nothing blocks, unreadable = fail closed) returns keyed reasons:
  extra branches, restricted users (`all_branches` false or `branch_user` rows), transfers `in_transit`, fiscal
  years numbered `per_branch`, worded with the company's branch label. `UpdateTenantModulesRequest::after()` refuses
  a switch-off (including one caused by unticking Accounting) with one `enabled_modules` error. Central log
  `tenant.module_branches_enabled` / `tenant.module_branches_disabled` next to `tenant.update_modules`. Migration
  `2026_11_10_600000_grant_branch_permissions_to_existing_admin_role`: frozen four keys onto `slug = admin` only,
  idempotent, down() removes those four from Admin.) Module checkbox in the central tenant modules UI, block switch-off when the tenant
  uses more than Main, activity-log entry. Also an idempotent grant migration that adds `branches.*` and
  `stock_transfers.receive` to the Admin role of existing tenants (literal key list, following
  `2026_11_06_100100_grant_area_permissions_to_existing_roles`), because the Admin role is stored as frozen JSON.
- [x] **BR25 (own approval) Perpetual-class transfers.** GL legs on AS12/AS14 through clearing, `perpetual_value`
  split of INI23/EXE11 vs inventory, interleaved perpetual costing at dispatch, report and audit changes; WIP is not
  an item class so out of scope; no separate P1 work: I8 holds because in-transit value sits only in clearing.

Suggested order: BR01 to BR04 serially, BR05 to BR09 (BR07 last) serially, BR10 with BR11 in parallel, then BR12,
BR13 to BR16, BR17 to BR19, BR20 and BR21, then BR22 to BR24.

## 10. Test matrix (Pest, run by the user)

1. Backfill: legacy-shaped DB to Main, idempotent, rollback, SQLite and MySQL safe.
2. I1 balance rules and `autoBalance` hub and spoke, with 2 and 3 branches, rounding exact (`Money`, no floats).
3. Reversal, roll-forward and year close keep branch tags and per-branch balance.
4. Receipt and payment across branches: party line follows the invoice's branch, outstanding correct, clearing lines
   present.
5. Store immutability and document-store-branch consistency (I5).
6. Cross-branch transfer: consolidated nets, in-transit rules, cancel, close-year refusal.
7. Per-branch closing stock sums to company closing stock (I4).
8. Access: restricted user lists, shows, reports, party balances, posting outside allowed set refused (I6), owner
   and all-branch users unaffected.
9. Numbering: shared series byte-identical to today; per-branch gapless (I7); scope change guard.
10. Route authorization audit (`RouteAuthorizationAuditTest`) for every new route.
11. Single-branch parity for every report, PDF and export.
12. JS: switcher, `useBranch`, selectors, label composable.
13. Query-count guard on one list page and one report for restricted and unrestricted users.

## 11. Risks and mitigations

| Risk | Mitigation |
|---|---|
| Some posting path forgets a branch and the voucher is imbalanced per branch | I1 enforced in the single choke point, default to context branch, parity tests, `branches:audit`. |
| Scope silently corrupts domain math for restricted users | Scope only for restricted users, `unrestricted()` for system code, restricted-user matrix tests. |
| Large-table migration locks | Rehearse on a copy, window if needed, additive only. |
| Branch gross profit looks odd because cost is company-wide | Documented in section 5.5 and in report help text; consolidated numbers are unaffected. |
| Mid-year switch-on confuses P&L | Help text recommends fiscal-year start; cut-over by transfer keeps books auditable. |
| Numbering rules unknown for Nepal | Shared default, per-branch behind a per-year scope, CA confirmation before enabling. |
| Dirty working tree collides with edits | Clean baseline before BR01. |

## 12. Deferred, explicitly out of scope

Group consolidation across tenants, per-branch fiscal years or chart, budgets and variance, branch price lists and
discounts, shared-cost allocation engine (manual journal covers it), re-tagging of history, partial receive and
discrepancy workflow, asset transfer between branches, cash or bank account restriction per branch, branch-wise
e-billing registration.

## 13. Open questions (none block Phases 0 to 3)

1. **Numbering format and legality.** Is a per-branch invoice series acceptable to IRD for this tenant mix, and in
   what printed format? Ask a Nepali CA or IRD before enabling per-branch scope anywhere. Default stays shared.
2. **Transfer valuation basis.** Company average cost on the transfer date is the plan; confirm an accountant is
   happy that branch closing stock is quantity times company average.
3. **Branches with their own VAT registration.** Supported through print details and branch-filtered VAT books;
   a branch that files separately is better as a separate tenant. Confirm with the first such customer.
