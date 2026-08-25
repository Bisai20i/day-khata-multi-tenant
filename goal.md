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
- **Core business schema, backend only** (2026-08-25): chart of accounts (normalized
  heads/groups/subgroups/accounts hierarchy), customers/suppliers (with auto-linked ledger
  accounts), item categories/subcategories/items. Migrations, models, a seeder, thin
  controllers/routes, and 18 focused tests — see `mem.md` for the full breakdown. **No Vue pages
  yet** — deliberately deferred to a dedicated frontend pass (see roadmap item 2 below). Full test
  suite + `npm run build` not re-verified together yet after this slice — do that before building
  on top of it.

**Next, roughly in order** (not a committed sequence — re-evaluate against sibling
`05-phase-plan.md` before starting each):
1. ~~Git init + first commit.~~ Done 2026-08-25 — 4 commits on `master`, see `mem.md`.
2. **Frontend pass for the core business schema**: Vue/Inertia pages for chart-of-accounts,
   customers/suppliers, item categories/items (the backend/routes already exist and are tested —
   see `mem.md`). Run `npm run build` + a real browser smoke test once built, per the "how we work"
   testing standard below.
3. **Ledger/financial-transaction engine** (journal vouchers, `mainaccountledger`/
   `mainaccountledgerdetails`) — the posting engine every transactional module writes through.
   `05-phase-plan.md` Phase 1 bundles this with chart-of-accounts/customers, but it's materially
   bigger (the actual double-entry posting logic) and was treated as separate, not-yet-started
   scope when the master data above was built.
4. **Sales/purchase/inventory modules** once the ledger engine exists to post against.
5. **Fiscal year handling** — needs a real design decision: either port a simplified version of the
   legacy trigger/view mechanism (MySQL-only, breaks SQLite dev) or find an Eloquent-portable
   equivalent (e.g. application-level enforcement + a nightly consistency check). Don't default to
   either without discussing trade-offs first.
6. **Reporting** (the 52-report-view module in the legacy app) — leans heavily on `DataTable`,
   already built.
7. Production hardening pass: MySQL credential-role separation, queued (not synchronous) tenant
   provisioning, 2FA for platform admins, CSP/security headers — see
   `../day_khata/migration_plan/02-security-hardening.md`. Deliberately deferred until there's a
   real deployment target, not because it's unimportant.

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
