# Day Khata — Multi-Tenant Rewrite: Memory

Living state doc. Read this before starting work, update it before stopping. See `goal.md` for
direction/roadmap — this file is "what exists and why," not "what's next."

**Last updated:** 2026-08-25. **Git status: initialized**, 5 commits on `master` (git init, dev-DB
gitignore fix, mem/goal update, core business schema, business schema frontend pages). No remote
configured yet.

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

## Open items (also see `goal.md` roadmap)

- Chart-of-accounts/customers/suppliers/items are now backend AND frontend complete, verified end
  to end (`npm run build` succeeds, 45/45 tests including a real authenticated-HTTP Inertia
  component-render check for all 8 pages). Not manually smoke-tested in an actual browser this
  session (curl-based CSRF handling was fought and abandoned in favor of the Pest-based check,
  which is more reliable anyway) — worth a real browser click-through before considering the UI
  polished, but functionally verified.
- Ledger/financial-transaction engine (journal vouchers, `mainaccountledger`) not started — Phase 1
  in `05-phase-plan.md` bundles this with chart-of-accounts/customers, but it's a materially bigger
  piece (the actual posting engine) and was treated as separate scope this session.
- Synchronous tenant provisioning, no 2FA, no MySQL credential-role separation — all deliberately
  deferred, see `goal.md`.
- Fiscal year design is an open question, not yet started — needs a real decision (see `goal.md`
  roadmap item 4) before any ledger/transaction schema work begins.
