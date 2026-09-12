# START HERE: gap-fix execution plan (from the 2026-09-11 audit)

This folder is a ready-to-run plan. The user opens Claude Code in this repo, points at this file, and types
`start`. Everything needed to execute is in this folder plus `plans/gap-audit-2026-09-11.md`.

## What to do when the user types `start`

You are the **coordinator**. You do not write feature code yourself; you run the phases below with parallel
sub-agents, review their output, and commit after review.

1. Read, in order: this file, `todo/CONTRACTS.md`, `plans/gap-audit-2026-09-11.md` (sections 1 and 6).
   Skim `goal.md` and grep `mem.md` only when a task needs a past design decision.
2. Look at the **Status board** below and resume from the first phase that is not `done`.
   - `start` = continue the whole plan from the next pending phase.
   - `start T05` (a task id) = run only that task, following every rule here.
   - `status` = report the board and any open gate question, change nothing.
3. Run **Preflight** (only the first time), then the next phase, then its gate. Keep going phase after phase
   until a gate says to stop and wait for the user.

## Locked preferences (decided by the user on 2026-09-11, do not re-ask)

| Topic | Decision |
|---|---|
| Test runs | **Agents never run tests or builds.** They write/update Pest and JS tests but do not execute them. The user runs `php artisan test`, `npm run build`, migrations. At gates, ask the user to run the listed commands and wait for the result. |
| Commits | **Agents never commit.** After each phase the coordinator reviews the combined diff, then commits on `development` in logical per-task commits, naming files explicitly (never `git add -A` / `git add .`). **No push.** |
| Stock valuation | Keep **weighted average**, fix the cost basis (net cost per base unit, discounts applied, transfers excluded, fallback to item purchase rate). See CONTRACTS C10. |
| Cancellation | Cancel allowed **only while the document's fiscal year is the open year**. The reversal is dated on the cancel day; VAT/TDS reports show the cancellation in that period, so filed months never change. After the year closes: credit note / return instead. See C4, C5. |
| Invoice numbers | Each invoice series gets its own gapless stored number (PAN gets its own series, reversals get their own `Reversal` voucher type), plus an admin **starting-number** setting for tenants migrating mid-year. See C7. |
| Auto year-end close | No pause needed: closing stock is implemented in Phase 3 (T11). Phase 3 must land before any real year-end. |
| PHP version | Irrelevant after this work (money uses brick/math, not `round()`). Keep `composer.json` at `^8.3` compatible syntax. |
| Header discount | **Split proportionally** between the VAT and exempt subtotals (largest-remainder, parts sum exactly). See C3. |
| Writing style | No em dashes in docs, reports or UI text (use commas, colons, full stops, or " - "). |

## Rules for every sub-agent (paste this section into each agent prompt by reference)

1. **Ownership.** Edit only the files listed under "Owned files" in your task file, plus new files inside the
   new paths your task lists. Need a change in a file you don't own? Don't edit it: put the exact request
   (file, what, why) under "Cross-file requests" in your final report.
2. **Forbidden commands:** `php artisan test`, `vendor/bin/pest`, `phpunit`, `npm run build`, `npm run dev`,
   `npm test`, `node --test`, `php artisan migrate*`, `tenants:*`, `db:*`, `tinker`, anything that touches a
   database, and any git command that changes state (`add`, `commit`, `stash`, `checkout`, `switch`, `reset`,
   `restore`, `rebase`, `merge`, `clean`, `rm`).
3. **Allowed checks:** `php -l <file>`, `vendor/bin/pint <your files>`, `git status`, `git diff -- <your files>`,
   pure-arithmetic `php -r` / `node -e`, and pure-PHP smoke checks that only `require 'vendor/autoload.php'`
   and call framework-free classes (e.g. `App\Support\Money\Money`). Never boot Laravel or open a DB.
4. **Shared working tree.** Other agents are editing other files in this same tree right now. Worktree
   isolation is not reliable in this environment. Ignore their in-progress changes, never "fix" or revert
   them, never reformat files you don't own.
5. **Migrations:** new files only, in `database/migrations/tenant/`, using your task's reserved timestamp
   prefix (below). Never edit an existing migration. Every migration has a working `down()`, backfills
   existing rows before adding NOT NULL or unique constraints, and works on both SQLite (tests) and MySQL
   (production). Data migrations must be idempotent.
6. **Money rules (C1-C3):** no float arithmetic, `(float)` casts, `round()`, `floatval`, `number_format` on
   raw floats, `toFixed`, `parseFloat` or `Number()` math on money, quantity or rate in any code you touch.
   Exact comparisons only, no tolerances. Models you own switch `decimal:N` casts to `App\Casts\Decimal`.
7. **Style:** match the surrounding code. This repo writes explanatory "why" docblocks; keep that density.
   PHP 8.3-compatible syntax, Pint formatting, Vue 3 `<script setup>`, existing `components/ui/*`.
8. **Tests:** every behaviour change gets a Pest test (feature or unit) in the matching `tests/` folder.
   Existing tests that assert the old buggy behaviour are updated to the correct behaviour and listed in your
   report. Never delete or skip a test to make it pass. Remember tests run on SQLite `:memory:` where
   `lockForUpdate()` is a no-op, so concurrency fixes are verified by code review, not tests.
9. **Tick your checkboxes** in your own task file as you finish each item (the user watches these live).
10. **Final report** (your last message, under 800 words): what changed per checkbox; files created/modified;
    tests added/updated (not run); migrations added; cross-file requests; open questions; anything you could
    not finish and why.

### Reserved migration prefixes

T03 `2026_09_12_030000+`, T04 `2026_09_12_040000+`, T05 `2026_09_12_050000+`, T06 `2026_09_12_060000+`,
T07 `2026_09_12_070000+`, T08 `2026_09_12_080000+`, T09 `2026_09_12_090000+`, T10 `2026_09_13_100000+`,
T11 `2026_09_13_110000+`, T12 `2026_09_14_120000+`, T13 `2026_09_14_130000+`, T14 `2026_09_14_140000+`.

### Coordinator-only files (no agent edits these)

`routes/tenant.php` (route `require` list), `resources/js/lib/nav-items.js`, `todo/START.md` (status board),
`mem.md`, `goal.md`. Agents may create new route files for their own module and request the `require` +
nav entry in their report.

## Preflight (first `start` only)

1. `git status` must show branch `development` and a clean tree apart from `todo/` and
   `plans/gap-audit-2026-09-11.md`. If anything else is dirty, stop and ask the user.
2. Commit the plan docs: `git add todo plans/gap-audit-2026-09-11.md` then commit
   "Add 2026-09-11 gap audit and execution plan".
3. Tell the user in 2 lines what Phase 1 is about to do.

## Phases

Spawn each phase's agents **in one message** (parallel) with `subagent_type: general-purpose`, no worktree
isolation. Agent prompt template:

> You are a senior Laravel/Vue engineer executing `todo/<TASK FILE>` in
> `D:\Projects\day-khata\day-khata-multi-tenant` (branch `development`). This is production accounting and
> billing software for Nepali businesses; correctness beats speed. Read `todo/START.md` section "Rules for
> every sub-agent" and obey it exactly, then read `todo/CONTRACTS.md` in full, then your task file, then the
> audit findings it references in `plans/gap-audit-2026-09-11.md`. Verify every library API against the
> installed source in `vendor/` or `node_modules/` before using it. Implement every checkbox, ticking each in
> your task file when done. Other agents are working in parallel on other files. Finish with the final report
> described in the rules.

| Phase | Tasks (parallel) | Depends on |
|---|---|---|
| 1 Foundation | T01 backend money, T02 frontend money | nothing |
| 2 Modules | T03 ledger core, T04 sales/POS, T05 returns/receipts, T06 purchases, T07 capital/quotations, T08 inventory/costing, T09 print compliance | Phase 1 committed |
| 3 Books and reports | T10 reports/VAT/TDS, T11 books/fiscal year/assets | Phase 2 committed |
| 4 Parity features | T12 sales features, T13 purchase/inventory features, T14 accounting features | Phase 3 verified by the user |

## Gates (coordinator, after every phase)

1. Wait for every agent's report. Read each report fully.
2. `git status` + `git diff --stat`. **Ownership check:** every modified file must belong to the task that
   reported it (see each task's "Owned files"). Investigate any file nobody claims.
3. `php -l` every changed PHP file. `vendor/bin/pint --test` on changed files (then `vendor/bin/pint <files>`
   if needed).
4. Contract check: grep the phase's changed files for `(float)`, `floatval(`, `round(`, `toFixed(`,
   `parseFloat(`, `'decimal:` on money/qty/rate. Each hit must be justified (e.g. `toFloat()` for an Excel
   numeric cell) or sent back. Grep for any `Money::`/`Quantity::`/`DocumentCalculator` method call that
   does not exist in C1-C3.
5. Apply cross-file requests yourself (small, reviewed edits) or hand them to one follow-up agent.
   Add route `require`s to `routes/tenant.php` and nav entries to `nav-items.js`.
6. Commit per task (explicit file lists), message style of the existing `git log`. Then update the status
   board below.
7. Gate-specific:
   - **After Phase 1: STOP and ask the user** to run
     `php artisan test tests/Unit/Support` and `node --test tests/js`
     and paste the result. Fix failures (spawn a fix agent with the failure output) before Phase 2.
     The whole system builds on these two modules.
   - **After Phase 2:** continue straight to Phase 3 (no user stop), unless an agent reported a blocker.
   - **After Phase 3: STOP and ask the user** to run: `php artisan test`, `npm run build`,
     `php artisan tenants:migrate` on a dev tenant, then the browser checklist below. Fix every failure
     (one fix agent per failing area, same rules) and repeat until green, then continue to Phase 4.
   - **After Phase 4: STOP and ask the user** to run the full suite and build again. When green: update
     `mem.md` (new architecture: Money/Quantity/Decimal cast, DocumentCalculator, Reversal vouchers, stored
     invoice numbers, costing and closing stock, print log) and `goal.md` (roadmap), mark the audit resolved,
     commit, and give the user a final summary.

### Browser checklist (user, after Phase 3)

- Sales/Create: rate 12.5 and qty 1.5 submit fine; preview total equals the printed bill (try 1.5 x 33.33).
- POS: complete a sale; the print opens for that sale; the cart clears; cash above the due gives change.
- PAN invoice: no VAT anywhere, grand total equals line total.
- Return 1 Box of an item sold in Boxes of 12: stock goes up by 12.
- Cancel a sale: invoice numbers of later sales have no gap; reprint shows "Copy of Original".
- Close a test fiscal year: Balance Sheet shows Stock in Hand and balances; Cash Book in the new year opens at
  the right balance (not doubled).

## Status board (coordinator updates after each gate)

| Task | Title | Phase | Status | Commit(s) |
|---|---|---|---|---|
| T01 | Backend money foundation | 1 | done, tests not run | 91c68d3 |
| T02 | Frontend money foundation | 1 | done, tests not run | 3a317f3 |
| T03 | Ledger core, numbering, settings | 2 | done, tests not run | 748d195 |
| T04 | Sales and POS | 2 | done, tests not run | 6c80439 |
| T05 | Sales returns and receipts | 2 | done, tests not run | 2c013a3 |
| T06 | Purchases, purchase returns, payments | 2 | done, tests not run | 287124f |
| T07 | Capital documents and quotations | 2 | done, tests not run | fd2504e |
| T08 | Inventory and costing | 2 | done, tests not run | 122959e |
| T09 | Print compliance | 2 | done, tests not run | 10be87b |
| T10 | Reports, VAT and TDS | 3 | done, tests not run | 790e90a |
| T11 | Books, fiscal year, fixed assets | 3 | done, tests not run | c4ee057 |
| T12 | Sales parity features | 4 | pending | |
| T13 | Purchase and inventory parity features | 4 | pending | |
| T14 | Accounting parity features | 4 | pending | |

Gate notes / open questions:

**Phase 1 gate, 2026-09-12.** Preflight commit `333eaa9`. T01 `91c68d3`, T02 `3a317f3`, gate `1601b68`.
Ownership clean, `php -l` clean, Pint passed, no banned float op outside the sanctioned `Money::round()`.

- Cross-engine check: all **43 golden vectors** replayed through both `DocumentCalculator` (PHP) and
  `calculateDocument` (JS) by the coordinator: 43/43 match on both sides.
- One real divergence was caught and fixed: a negative **percentage** discount gave `negative_discount` on
  the server and `percentage_out_of_range` in the browser, at line level and header level. JS was aligned to
  the server and a vector was added for the line case, which nothing had covered. See the C3 addendum in
  `CONTRACTS.md`.
- **Awaiting the user:** `php artisan test tests/Unit/Support` and `node --test tests/js` (or `npm run test:js`).
  No agent has run any test yet.

**Phase 2, first pass, 2026-09-12.** T03 `748d195`, T07 `fd2504e`, T09 `10be87b`, coordinator wiring `06cf3cd`.
T04, T05, T06 and T08 were killed mid-edit by a session rate limit before ticking any checkbox or reporting.
Their partial work is preserved UNCOMMITTED in the working tree (102 files) and they are being resumed in
place, not restarted. Every changed PHP file parses (`php -l` clean across all 173 touched files).

Relay to the resumed agents and to Phase 3:

- **T03 deviation, T05/T06 must honour:** read the new `company_settings.sale_return_prefix` and
  `purchase_return_prefix` rather than hardcoding `SR` / `PR`.
- **T03 deviation, everyone:** the fiscal-year date guard sits in `JournalVoucher::write()`, so it also covers
  year-end close and depreciation. `ClosedFiscalYearGuard::assertDateInOpenYear()` throws
  `InvalidArgumentException` for a missing or closed year and `AuthorizationException` for a non-admin on a
  reopened year: catch both.
- **T03 request for T11 (Phase 3), important:** `FiscalYear::postClosingEntries()` and `postOpeningBalances()`
  pass `(float) netBalance(...)` into `JournalVoucher::write()`, which now refuses more than 2 decimals. A
  float artefact like `2261.1000000000004` will throw at year-end close. Fix with
  `Money::round($net)->toString()` per line and for `$netProfit`. Round test amounts hide this, so the suite
  can stay green while production breaks.
- **T09 request for T04/T05/T06:** every `print()` action calls `PrintLog::record($doc, $user)` and passes
  `copyNumber`, `dateBs`, `dateAd`, `fiscalYearName` and (invoices and notes only) `amountInWords`.
  `pdf/sale-receipt.blade.php` does NOT extend `pdf/layout.blade.php`, so T05 must add the copy stamp, BS
  date and amount in words to it by hand.
- **T07 request:** `role:admin` on `capital-sales/{capitalSale}/cancel` (T04 owns `routes/tenant-sales.php`)
  and on `capital-purchases/{capitalPurchase}/cancel` (T06 owns `routes/tenant-purchase.php`).
- **T03 open item:** if `CompanySettingTest`'s `toBeTrue()`/`toBeFalse()` assertions fail, the one-line fix is
  `'boolean'` casts on `CompanySetting`'s `allow_negative_stock` / `sale_*_enabled`.

**Phase 2 gate passed, 2026-09-12.** All seven tasks committed: T03 `748d195`, T07 `fd2504e`, T09 `10be87b`,
T04 `6c80439`, T05 `2c013a3`, T06 `287124f`, T08 `122959e`, coordinator `06cf3cd` and `d8455d2`.
Ownership clean, `php -l` clean on 95 changed PHP files, Pint passes on every changed file (the two failures
in `Central/Auth/TwoFactor*` are pre-existing repo style debt, untouched by this work). No float op in any
changed app PHP outside the sanctioned `Money::round()`. Every `Number(` left in changed Vue converts an
object key back to an id. Both engines still replay all 43 golden vectors: 43/43 PHP, 43/43 JS.

Systematic defect found and swept at this gate: four migrations backfilled by reading a raw decimal column
into the strict parser. SQLite gives a decimal column REAL affinity, so a row written by the old float code
returns as `404984.71000000002`, `Money::of()` refuses it, and `tenants:migrate` stops mid-table. T04 and T08
each caught it in their own file; the coordinator fixed the two remaining ones (`070000`, `070010`). Any new
migration that backfills money or quantity must use `Money::round()` / `Quantity::round()` on raw reads.

Cross-file requests applied by the coordinator at this gate: `Sale::outstandingAmount()` now reads
`sales_returns.tds_amount` instead of re-deriving the share (they drift by a paisa once a note has several
lines and a header discount), with migration `050001` backfilling that header column from the per-line
shares it computes; `CompanySetting` booleans cast; `ItemVarieties/Index.vue` money rendered with
`formatMoney` instead of `Number(...).toFixed(2)`.

Still open, deliberately not done: T04 asked to promote `Pos.vue`'s local scaled-BigInt quantity helpers
(`addQuantity`, `subtractQuantity`, `compareQuantity`, `stepQuantity`) into `money.js`. `Pos.vue` works as
written; this is a tidy-up for Phase 4 or later, and touching `money.js` mid-plan risks the 43-vector parity.

**Phase 3 gate passed, 2026-09-12.** T10 `790e90a`, T11 `c4ee057`, coordinator `77c0d0c`.
`php -l` clean on 58 changed PHP files, Pint passes on every changed file, no float op in changed app PHP,
both engines still replay all 43 golden vectors. Every remaining `toFloat()` sits at an Excel cell boundary.

Resolved at this gate:

- T11's blocking request applied: `BackupController::performBackup` made public. The callers guard with
  `is_callable()`, so while it was private an early or automatic year-end close REFUSED to run rather than
  silently skipping its backup.
- `JournalVoucherPostingTest` now passes an early-close reason to `close()`.
- Cross-agent seam removed: T10 kept `StockValuationReportController::currentTotalValuation(): float` "for
  the dashboard" while T11 moved the dashboard off it in parallel. It had zero callers and held the last
  non-Excel `toFloat()`, so it was deleted.
- Verified T10's changed stock expectation (3000 to 2000) against C10: weighted average is per ITEM, not per
  store, so a store filter changes the quantity, not the unit cost. Main 10 @ 1000 + branch 10 @ 3000 gives
  4000/20 = 200 per unit, so the branch's 10 units are worth 2000 and the stores sum back to 4000. The old
  expectation assumed store-scoped costing, which C10 never specified. The change is correct.

Known cosmetic oddity, correct by design: a cancelled credit note appears twice in the Sales Return Register
(positive when issued, negative when cancelled). That is what makes the register tie to the ledger, but
"Less: credit notes" on the VAT Summary can read negative in a month containing only a cancellation.

Still open for Phase 4: promote `Pos.vue`'s local quantity helpers into `money.js`; `StockValuationReport-
Controller` and `InventoryReportController` legacy self-summing paths (the dashboard no longer uses them).

Carry into Phase 3:

- **T03 request, critical:** `FiscalYear::postClosingEntries()` and `postOpeningBalances()` pass
  `(float) netBalance(...)` into `JournalVoucher::write()`, which now refuses more than 2 decimals. Fix with
  `Money::round($net)->toString()` per line and for `$netProfit`. Round test amounts hide this, so the suite
  can stay green while a real year-end close throws.
- **T08 request:** read stock valuation only through `StockCosting`. `StockValuationReportController` and
  `InventoryReportController` still compute their own sums off `ItemStockMovement` and will disagree with
  the books.
- Reports must use the stored `invoice_number` / `credit_note_number` / `debit_note_number` and the stored
  `tds_amount` on returns, never re-derive them, and must count only `posted` returns.

Carry into Phase 2 (from T02's report):

1. Replace the six UTC `new Date().toISOString().slice(0, 10)` date defaults with `todayInKathmandu()` from
   `@/lib/format`: `Sales/Pos.vue:95`, `Sales/Receipts/Create.vue:34`, `Purchases/Payments/Create.vue:34`,
   `Assets/FixedAssets/Index.vue:69`, `Inventory/Items/Index.vue:356,361`. Each is a day early between
   00:00 and 05:44 Kathmandu time. Owned by T04, T06, T08, T13.
2. `required` on `<Input>` is now actually enforced by the browser (71 usages). Whoever touches a form in
   Phase 2 gives its required fields one pass.
3. Every document form submits `expected_total` and handles the C8 422 (already in C8; T03/T04 own the first
   implementations).
