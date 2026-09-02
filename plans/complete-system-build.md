# Complete-system build plan

**Status as of 2026-09-02**: written, not yet started. Read `goal.md` roadmap items 10+ and `mem.md`
for what's actually landed before trusting this doc's "Status" lines — update both as each phase
ships, the same discipline every prior pass in this project has followed.

## Why this exists

The legacy `day_khata` system has no support left — the user wants the rewrite to reach full feature
parity (plus real fixes where legacy was actually broken) as fast as possible, with **no test/build
runs until everything below is built**. This doc is the phase-by-phase execution plan so work can be
approved and tracked phase by phase rather than as one undifferentiated pile of work. See `goal.md`'s
"Non-negotiable architecture decisions" before touching anything here — those still apply.

**Working rules for every phase below** (apply to every agent spawned, not just the coordinator):

- **Coordinator (this session) never runs `php artisan test`, `npm run build`, or any other heavy
  command** — the user runs those and reports back. Agents may run `php -l` on files they touched and
  a **file-scoped** `vendor/bin/pint --format agent -- <exact files>` (never `--dirty`, which touches
  siblings' in-flight files) — nothing heavier.
- **Every agent gets a token budget of roughly 200k of its own context.** If, after reading the
  necessary existing-code context, an agent's assigned task looks like it needs substantially more
  than that (rule of thumb: more than ~5–7 substantial new files, or genuinely separable concerns —
  "backend engine" vs "frontend pages" vs "a distinct sub-entity"), the agent must NOT push through
  solo. Instead it:
  1. Establishes the shared contracts itself first (migrations, model method signatures, route/nav
     stubs) — this project's established "coordinator pre-stubs shared files" convention
     (`mem.md` gotcha #5).
  2. Spawns 2–4 child agents via the `Agent` tool (`subagent_type: general-purpose`), each with a
     fully self-contained brief (no assumed shared memory) and an exclusive, disjoint file list.
  3. Reads its children's actual output files itself and verifies correctness (balance math, guard
     conditions, convention match) before reporting up — never just trusts a child's self-report.
  This rule is recursive: whatever a child spawns must follow it too.
- **File-ownership discipline**: every parallel agent gets an exclusive file list. Shared/pre-existing
  files (routes/tenant.php, nav-items.js, any model two features both touch) are edited by the
  coordinator BEFORE forking, never by two agents in parallel.
- **Money-math and guard logic gets hand-traced by whoever verifies it** (coordinator or a parent
  agent reading a child's work) — the standard this project has held since the Fixed-Assets/
  Payment-Receipt passes, not relaxed just because the pace is picking up.
- Update `mem.md` (what shipped, gotchas found) and this doc's phase "Status" lines after each phase
  completes — not after every individual agent, to avoid churn.

---

## Phase 0 — Foundational (build first, everything else depends on it)

**Status: fully done and verified 2026-09-02 — 0A + 0B core, plus both Wave 2 sweeps (report `store_id`
filters, date-input retrofit across all transaction + report pages), all hand-checked by the coordinator
against actual output, not agent self-reports. See `mem.md` for the full breakdown, including a real gap
found and fixed along the way (4 report tests called `Item::recordStockMovement()` directly, bypassing
the `Sale::post()`-level fallback). Not yet run through the user's test/build pass.**

**Design amendment found before delegating 0A**: a grep for `Sale::post(`/`Purchase::post(`/
`StockAdjustment::post(`/`SalesReturn::post(`/`PurchaseReturn::post(` turned up 29 call sites, most of
them existing tests (Receipts, Payments, Quotations, every report test) that don't know about stores
and never will need to. Making `store_id` hard-required in each `post()` method's `$data` array — as
originally written below — would break all 29 without me being able to run the suite to confirm the
fixes. Amended: `$data['store_id']` is **optional** at the `post()` layer; when omitted it resolves to
`Store::where('is_active', true)->orderBy('id')->value('id')` (the seeded default "Main Store").
The `store_id` *column* on `sales`/`purchases`/etc. and on `item_stock_movements` stays a real,
required, non-nullable FK — only the caller-facing data contract is optional. This means none of the
29 existing call sites need to change; new Create-page UI can pick a store explicitly, old/other-module
callers keep working unmodified.

Two independent tracks, both designed in full below (not left to an agent to architect — these are
the highest-risk, most interconnected changes in the whole plan, same standard applied to the
Payment/Receipt module's `outstandingAmount()`/allocation design). The coordinator briefs one agent
(or a tight 2-agent fork pair) per track against this concrete spec, then verifies the actual files
before Phase 1 starts.

### 0A — Multi-location (Store) scoping

Legacy's own "multi-store" is cosmetic (a label field on the item master only — confirmed via
research, no per-store stock pools, no scoping on sales/purchases/reports/users anywhere). This is
genuinely new design, not a port.

- New `stores` table: `id, name, address?, phone?, is_active default true`. `App\Models\Store`,
  `App\Http\Controllers\Tenant\...\StoreController` (plain CRUD, no update/destroy restrictions beyond
  the usual), nav entry. `TenantDatabaseSeeder` seeds one default **"Main Store"** so a fresh tenant
  can transact immediately without extra setup (mirrors the chart-of-accounts pre-seed pattern).
- `item_stock_movements` gains a **required** `store_id` (FK `stores`, `restrictOnDelete`).
  `Item::recordStockMovement()` gains a required `$storeId` param. `Item::currentStock(?int $storeId =
  null): float` — filtered per-store when given, a cross-store total when omitted (so any caller that
  doesn't care about store scoping keeps working).
- `sales`, `purchases`, `stock_adjustments`, `sales_returns`, `purchase_returns` each gain a required
  `store_id`, threaded into their `post()` `$data` shape and on into `recordStockMovement()` calls.
- **Deliberately out of scope, flag this explicitly rather than silently expanding**: no per-store
  *financial* reporting — P&L/ledger stays tenant-wide. Fixed Assets, Quotations, Receipts, Payments
  are NOT store-scoped (not inventory-relevant). Revisit only if a real need surfaces.
- `users` gains a nullable `store_id` (staff optionally scoped to one store; null = all stores, same
  nullability pattern `role_id` already uses). Enforcement is a soft default (pre-fill the user's own
  store on new transaction forms), not a new authorization subsystem.
- **Report sweep** (can run as its own wave once the above lands): 17 of 21 report methods gain an
  optional `store_id` filter. Most are a mechanical `->where('store_id', $storeId)` added to an
  existing Sale/Purchase query. Four need real per-store stock math since `currentStock()`'s signature
  changed: `StockValuationReportController`, `InventoryReportController::stockSummary`,
  `StockMovementRegisterController`, `CategoryWiseReportController::stockByCategory`.

### 0B — Nepali Bikram Sambat (BS) calendar support

Legacy stores plain Gregorian dates in SQL `date` columns everywhere and converts to/from BS only at
the UI boundary, via a self-contained table-based algorithm — **no database migration needed for
this**, it's an input/output layer only.

- `App\Support\NepaliCalendar` — port legacy's `Nepali_Calendar` conversion (`../day_khata/app/Models/
  Nepali_Calendar.php`, table-driven, BS 2000–2090, epoch-anchored day counting) as a pure,
  dependency-free PHP utility (`adToBs()`/`bsToAd()`). Do NOT use `pratiksh/nepalidate` — legacy
  declares it but never calls it; dead weight.
- A matching JS port, `resources/js/lib/nepali-calendar.js`, so date inputs convert client-side (no
  AJAX round-trip per keystroke, unlike legacy).
- New reusable `resources/js/components/ui/NepaliDateInput.vue` — masked BS input, emits the converted
  AD date string (drop-in replacement contract for the native `<Input type="date">` it replaces).
- `App\Models\FiscalYear` gains a BS-label accessor (e.g. `"2081/82"`, computed from `start_date` via
  `NepaliCalendar` — not stored, matches this app's no-DB-triggers portability rule). Fiscal-year
  creation UI can pick a BS year to auto-fill the Shrawan-1→Ashad-end AD boundary.
- New tenant-aware scheduled command `App\Console\Commands\AutoStartFiscalYear`, mirroring legacy's:
  at BS year rollover, auto-opens the next fiscal year for every active tenant.
- **Retrofit sweep** (its own wave once the component exists): swap every native date input in
  already-existing pages (Sales, Purchases, Sales/Purchase Returns, Stock Adjustment, Fixed Assets,
  Quotations, Receipts, Payments, Fiscal Years, Journal Vouchers) for `NepaliDateInput` — pure
  component swap, no logic change, parallelizable one agent per module (disjoint Vue files).
  **Every NEW module built in Phase 1 uses `NepaliDateInput` from the start** — no separate retrofit
  needed for those.

---

## Phase 1 — New feature modules

**Status: FULLY DONE 2026-09-02 — all 12 items built and hand-verified. See `mem.md` for the complete
breakdown.** Items 1 (Sales Agent commission) and 4 (PDF/print) were sequenced, not parallelized, since
both touch `Sale.php`/`SaleController.php`. PDF/print required approval + manual install of
`barryvdh/laravel-dompdf` (a new Composer dependency) — the user ran `composer require` themselves
before that item started.

Each item below is one agent brief unless its own agent judges it needs to split (per the recursive
budget rule above). Items are independent of each other — can run as concurrent waves of ~4–6 agents,
verified batch by batch.

1. **Sales Agents + commission** — build properly, not a port: `Agent` model with its own ledger
   account (mirrors `Customer`/`Supplier`'s `HasLedgerAccount` pattern), a real `agent_id` FK + a
   commission amount on `Sale`, posted as a real ledger line the same way TDS already works in
   `Sale::post()` (an optional extra debit/credit pair, not a same-side correction line). Fixes
   legacy's actual gaps (flat un-calculated amount, string-keyed linkage, dead ledger account, a report
   query referencing a nonexistent `cancel` column) rather than reproducing them.
2. **POS / walk-in quick-sale** — frontend-only. New Vue page hitting the existing
   `SaleController::store()` (item-tile grid, quick customer picker/create, on-screen numpad, receipt
   print view, barcode scan input). Check whether `Item` already has a barcode field before assuming
   one needs adding.
3. **Settings / Invoice Setup** — company info, invoice number/format config, custom printed notes.
4. **PDF/print invoice output** — `barryvdh/laravel-dompdf` (already flagged as a planned dependency in
   `goal.md`), printable Sale/Purchase/Return/Quotation views.
5. **Item expiry-date tracking** — per-item/batch expiry dates, active/expired toggle.
6. **Item varieties** — size/color/variant sub-records under an item.
7. **Activity log / audit trail** — tenant-scoped audit of financial writes.
8. **Backup module** — per-tenant on-demand DB dump, authenticated download only. Legacy had a real
   security bug here (backups reachable from the public webroot) — fix it, don't replicate it.
9. **Fiscal-year archive DB** — the largest item here. Read `../day_khata/migration_plan/01-
   architecture-tenancy.md` §3.4 first (a previous planning pass already sketched this design) before
   building. Likely needs the recursive-split rule (backend archive-DB mechanism vs. the UI to browse
   an archived year are genuinely separable concerns).
10. **Admin impersonation** — central platform-admin "log in as this tenant's admin," audited.
11. **Dashboard notices** — small announcement/banner feature.
12. **CI skeleton check** — first confirm what already exists (lint/static-analysis/`composer audit`/
    `npm audit`) before treating this as a build task; it may already be partially there.

---

## Phase 2 — Full verification (end of the whole effort, not per-phase)

**Status: DONE 2026-09-02 — all green.** `vendor/bin/pint --dirty --format agent` clean,
`php artisan test` 345/347 (2 real failures found and fixed — see `mem.md`'s Phase 2 entry for both),
`npm run build` succeeded. **This closes out the entire complete-system-build effort.** The only thing
left is committing: batch by batch (0A, 0B, then each Phase-1 item or small related group), starting
with the still-earlier uncommitted Payment/Receipt module — not started yet, next session's first task.
