# Day Khata — Multi-Tenant Rewrite: Goal

## What this is

A ground-up rewrite of Day Khata (a Nepali accounting/billing SaaS, currently deployed as one
Laravel instance per client — see the sibling repo `../day_khata`) into a proper multi-tenant
platform: one codebase, one deployment, database-per-tenant isolation, provisioned on demand.

The sibling repo's `migration_plan/00-07-*.md` docs are the authoritative source of the *why*
behind almost every architectural decision here — tenancy model, security posture, schema
philosophy, phasing, risk register. Read them before making a decision that contradicts something
built so far. This file and `mem.md` exist so a new session doesn't have to re-read that whole plan
set (or this project's full chat history) just to keep working — read `mem.md` for current state,
this file for direction.

## Non-negotiable architecture decisions (already locked in, don't relitigate without cause)

- **`stancl/tenancy` v3.10.1, multi-database mode.** One physical database per tenant. This is a
  deliberate trade against single-DB + `tenant_id` scoping, made because a missed scope clause in
  accounting software is a cross-tenant financial data leak, not a cosmetic bug. See
  `../day_khata/migration_plan/01-architecture-tenancy.md` §2.
- **SQLite in dev, MySQL in production.** Every migration and query goes through Eloquent /
  the query builder / Schema builder — no raw SQL, no MySQL-only DDL (triggers, views, stored
  procedures, native enum columns). This is a deliberate simplification versus the legacy app's
  MySQL-trigger-based fiscal-year mechanism (`01-architecture-tenancy.md` §3) — that machinery is
  explicitly deferred, not forgotten. Revisit only when the real fiscal-year/ledger schema is being
  designed, and treat it as a real decision point, not a default.
- **Two separate auth guards, two separate user tables.** `platform` guard /
  `App\Models\PlatformAdmin` (central DB, cross-tenant staff) and `web` guard / `App\Models\User`
  (tenant DB, one business's own users). Never merge these.
- **Frontend: Inertia.js + Vue 3 (Composition API, `<script setup>`) + Tailwind v4 + Reka UI**,
  matching `../day_khata/migration_plan/03-design-system-frontend.md` and
  `../day_khata/design-preference.md` (the canonical visual spec: purple/Inter, flat corners
  everywhere, tight spacing). Blade is not used for app screens, only reserved for future PDF/print
  output (`barryvdh/laravel-dompdf`) per `03-design-system-frontend.md` §4 — not built yet.

## Roadmap

**Done** — see `mem.md` for the full inventory:
- Tenancy foundation, central platform-admin auth, tenant management/provisioning, tenant
  auth + roles/permissions skeleton.
- Frontend foundation: Inertia wired up, full component library, two layouts, all existing pages
  converted from Blade to real Vue pages.
- A standalone client-facing design-system demo page (`docs/day-khata-design-system.html`).
- **Core business schema, backend + frontend** (2026-08-25): chart of accounts (normalized
  heads/groups/subgroups/accounts hierarchy), customers/suppliers (with auto-linked ledger
  accounts), item categories/subcategories/items. Migrations, models, a seeder, thin
  controllers/routes, backend tests, and — built in a second pass the same day via 3 parallel
  subagents — the matching Vue/Inertia pages for all 8 resources, plus a component-render guard
  test. `npm run build` succeeds, full suite green (45/45). See `mem.md` for the full breakdown,
  including a real gotcha the parallel pass surfaced (Inertia doesn't remount same-route pages, so
  `onMounted`-based flash toasts silently stop firing after the first load — fixed with `watch`).
- **Enterprise UI redesign** (2026-08-25): app shell (grouped sidebar, topbar), row-action
  differentiation + tooltips across all 8 business pages, and a real (non-placeholder) tenant
  dashboard with live KPIs. Design tokens (`--shadow-*`) centralized alongside the existing
  `--radius-*` tokens in `resources/css/app.css`. See `mem.md` for the full breakdown.
- **Ledger/journal-voucher posting engine, backend + frontend** (2026-08-25): fiscal years,
  double-entry journal vouchers, sequential per-type voucher numbering, automatic P&L year-end
  closing with balance-sheet carry-forward, and a super-admin closed-year correction override with
  multi-year roll-forward — all built Eloquent-portable (no DB views/triggers/session variables),
  resolving roadmap item 5 below as part of building item 3. `npm run build` succeeds, full suite
  green (53/53). See `mem.md` for the full breakdown (schema, the exact posting/closing algorithms,
  what's deliberately deferred).
- **Sales/Purchase modules, Stock Adjustment, an MVP Reporting slice, and partial-line Sales/
  Purchase Returns** (2026-08-26): the transaction modules post money-side-only journal vouchers
  against the ledger engine and track quantity via a new decoupled `ItemStockMovement` table
  (periodic, not perpetual, inventory accounting — confirmed from legacy). Full-invoice cancel
  (reversing voucher) AND real partial-line Sales/Purchase Return documents (credit/debit notes
  against specific original line quantities) both exist now. Stock Adjustment (manual quantity
  correction + opening stock, folded into one `reason_type`) rounds out the quantity side. 8 MVP
  reports built (Trial Balance, Income Statement, Balance Sheet, Sales/Purchase Register,
  Sales/Purchase VAT Book, Stock Summary) — chosen out of legacy's ~52-report module as the
  compliance-critical + highest-value subset, not all of them. All built via parallel subagents
  (forks). Full suite green (121/121), `npm run build` succeeds. See `mem.md` for the full
  breakdown. Committed 2026-08-26 as one catch-up commit alongside the enterprise UI redesign,
  ledger engine, and reporting MVP (all of which were also sitting uncommitted at the time) — see
  `mem.md`.
- **Production hardening pass** (2026-08-26): queued (not synchronous) tenant provisioning, 2FA for
  platform admins (TOTP + recovery codes), CSP/security headers, and a MySQL credential-role
  separation doc (deploy-time only, not app code — see roadmap item 7 below for why). Built via 3
  parallel forks. Committed (`617b655`). A real regression this pass introduced (a request into a
  still-provisioning tenant crashed instead of getting a clean 403, with a knock-on transaction
  corruption cascade) was found and fixed 2026-08-27 — see `mem.md`. Full suite now 132/132,
  `npm run build` succeeds.
- **Closed-year correction UI** (2026-08-27): the backend for posting an admin-only, reasoned
  correcting journal voucher into a closed fiscal year already existed and needed no changes — this
  was the frontend (fiscal-year picker + warning + reason field in `JournalVouchers/Create.vue`) plus
  new HTTP-level test coverage that had been missing. Writing that test coverage caught and fixed a
  real bug in `JournalVoucher::rollForward()` (a closed year could get a spurious duplicate
  roll-forward voucher posted into itself). Full suite now 134/134. See `mem.md`.
- **5 more reports + all 3 return-fidelity gaps closed** (2026-08-29): Day Book, Cash Book, Bank
  Book, Aged Receivables, Aged Payables (13 reports total now), plus cancel-a-return,
  header-discount/TDS reversal, and an optional cash/bank refund voucher for both Sales and Purchase
  Returns. Built via 4 parallel forks. Full suite now 151/151, `npm run build` succeeds. See
  `mem.md` for the full breakdown, including a real MVP gap Aged Receivables/Payables documents
  (no payment-receipt feature exists yet) and a design gap both return-fidelity forks independently
  caught (cancelling a refunded return must also reverse the refund settlement voucher).
- Not yet manually smoke-tested in an actual browser (any of the above, including this pass) — this
  is now the single biggest gap between "tests green" and "actually production ready."

**Next, roughly in order** (not a committed sequence — re-evaluate against sibling
`05-phase-plan.md` before starting each):
1. ~~Git init + first commit.~~ Done 2026-08-25 — 5 commits on `master`, see `mem.md`.
2. ~~Frontend pass for the core business schema.~~ Done 2026-08-25, see above.
3. ~~Ledger/financial-transaction engine.~~ Done 2026-08-25 (from-scratch portable schema, not a
   port of `mainaccountledger`/`mainaccountledgerdetails`), see above and `mem.md`.
4. ~~Sales/purchase/inventory modules.~~ Done 2026-08-26 — Sales, Purchase, Stock Adjustment, and
   (in a follow-up same-day pass) partial-line Sales/Purchase Returns are all built and verified,
   see above and `mem.md`.
5. ~~Fiscal year handling design decision.~~ Resolved 2026-08-25 as part of item 3: Eloquent-portable
   (app-level invariant + model events + one posting method), not the legacy MySQL trigger/view
   mechanism. See `mem.md`.
6. **Reporting**, continued — an MVP slice of 8 reports shipped 2026-08-26, then Day Book/Cash
   Book/Bank Book/Aged Receivables/Aged Payables shipped 2026-08-29 (13 reports total, see above).
   The remaining ~40ish legacy report views (see `mem.md` for the categorized list a research pass
   produced) are a real next slice, not forgotten — re-evaluate priority against actual usage before
   picking the next batch rather than building all of them speculatively.
7. ~~Production hardening pass~~ Built 2026-08-26, committed and verified 2026-08-27 (132/132 tests
   — see `mem.md`): queued tenant provisioning, 2FA for platform admins, CSP/security headers are
   real code. MySQL credential-role separation is scoped down to a deploy doc + GRANT script only
   (can't be functionally tested against the SQLite dev DB, and the security doc's own MVP guidance
   already accepts a single shared runtime user) — see
   `../day_khata/migration_plan/02-security-hardening.md` §7 and `mem.md` for the reasoning. Wiring
   the actual dual-connection split remains real future work once there's a MySQL deployment target.

## Explicit non-goals for now (deferred, not forgotten)

- Dark mode (matches the legacy app; `03-design-system-frontend.md` §2 flags it as a conscious
  future decision, not an oversight).
- The ~79-key legacy privilege port — the current `admin`/`staff` role split is a placeholder.
- Per-tenant MySQL credentials, DEFINER-pinned DDL roles, archive databases — all MySQL-specific,
  all deferred until the SQLite-portable core is further along.
- Async/queued tenant provisioning — currently synchronous (`shouldBeQueued(false)`), fine at
  today's scale, not fine in production.

## How we work on this project

Treat this as production software for a real accounting business, not a prototype — money math has
to be right, and "good enough for a demo" is not the bar.

- **Verify, don't assume.** Read the actual installed package source (`vendor/`, `node_modules/`)
  before writing code against its API — package APIs have broken guesses in this project before
  (TanStack Table v9 vs. the v8 API most examples show; Reka UI's flat export names; a deprecated
  `lucide-vue-next` swapped for `@lucide/vue`). A senior engineer checks; a junior one guesses.
- **Automated tests are not optional.** Every feature ships with Pest tests, and "tests pass" means
  actually running them, not assuming they would. Before calling anything done, also do at least one
  real end-to-end check beyond the test suite where practical (a live HTTP request through the
  actual server) — the test suite has already caught things a curl smoke test then caught *again*
  from a different angle (missing `cache`/`jobs` tables in tenant DBs, invisible to tests because
  `phpunit.xml` uses the `array` cache driver).
- **Stay portable.** Every migration, query, and seeder must work identically on SQLite and MySQL
  until a specific, discussed decision says otherwise for a specific feature.
- **No speculative abstractions.** Build what the current task needs. Don't add configurability,
  service layers, or "future-proofing" nobody asked for.
- **Parallel agent work needs hard file ownership.** When splitting work across agents, give each
  one a disjoint file set and an explicit written contract (exact class names, column names, route
  paths) for anything a sibling agent's in-flight code needs to reference. This has worked cleanly
  every time it's been done carefully here — don't get casual about it as it scales.
- **Keep `mem.md` current.** Any session that makes an architectural decision, discovers a gotcha,
  or finishes a substantial chunk of work updates `mem.md` before signing off. Stale memory is worse
  than no memory — it actively misleads the next session.
