# Day Khata — Multi-Tenant Rewrite: Memory

Living state doc. Read this before starting work, update it before stopping. See `goal.md` for
direction/roadmap — this file is "what exists and why," not "what's next."

**Last updated:** 2026-08-26. **Git status: initialized**, 5 commits on `master` (git init, dev-DB
gitignore fix, mem/goal update, core business schema, business schema frontend pages), plus
everything since (enterprise-UI redesign, ledger/journal-voucher posting engine, Sales/Purchase,
Stock Adjustment, the MVP Reporting slice, and partial-line Sales/Purchase Returns — all described
below) **not yet committed** — sitting uncommitted in the working tree as of this update, fully
verified (121/121 Tenant tests, `npm run build` green). No remote configured yet.

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
npm run build              # must succeed — last verified 2026-08-25 with all 8 business-schema pages
php artisan test --compact # 45/45 as of last update (44 backend + BusinessPagesRenderTest)
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

## How to verify the app is actually working, updated (2026-08-26)

Full suite is now **121/121 tests, 747 assertions**, `npm run build` succeeds. Confirmed via a fresh
authoritative run after all 6 parallel forks across both passes (Stock Adjustment + 3 Reporting
forks, then Sales Return + Purchase Return) landed — the same `php artisan test --compact` / `npm
run build` commands under "How to verify" above, just a higher test count than when that section
was first written.

## Open items (also see `goal.md` roadmap)

- Chart-of-accounts/customers/suppliers/items, the enterprise UI redesign, the ledger/journal
  voucher posting engine, Sales/Purchase/Stock Adjustment, the Reporting MVP, and partial-line
  Sales/Purchase Returns are all backend AND frontend complete, verified via the automated suite
  (121/121) and `npm run build`. **None of this has been manually smoke-tested in an actual browser
  yet** — worth a real click-through (create a fiscal year, post a journal voucher and a
  sale/purchase, close a year, run each report, post a stock adjustment, post a partial return)
  before considering any of it fully polished.
- Neither return type reverses header-level discount or TDS, and neither auto-generates a cash/bank
  refund voucher — see the Returns section above for the reasoning; real gaps if closer fidelity is
  ever wanted.
- No cancel-a-return path — returns are immutable/append-only in this pass, same as everything else,
  but there's no correction mechanism if a return itself was entered wrong (would need a new
  document type, e.g. a "return reversal," not an edit).
- The remaining ~44 legacy report views beyond the 8-report MVP — see the Reporting section above.
- Synchronous tenant provisioning, no 2FA, no MySQL credential-role separation — all deliberately
  deferred, see `goal.md`.
- No UI yet for the closed-year correction override (backend-complete, frontend deliberately
  deferred this pass — see the ledger section above).
