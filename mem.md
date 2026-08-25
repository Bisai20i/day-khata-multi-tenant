# Day Khata — Multi-Tenant Rewrite: Memory

Living state doc. Read this before starting work, update it before stopping. See `goal.md` for
direction/roadmap — this file is "what exists and why," not "what's next."

**Last updated:** 2026-08-25. **Git status: initialized**, 2 commits on `master` (`032a8c1` initial
commit, `b47a842` untrack dev SQLite tenant DBs). No remote configured yet.

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
npm run build              # must succeed
php artisan test --compact # 26/26 as of last update
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

- No business schema yet (chart of accounts, customers, items, etc.) — next planned slice.
- Synchronous tenant provisioning, no 2FA, no MySQL credential-role separation — all deliberately
  deferred, see `goal.md`.
- Fiscal year design is an open question, not yet started — needs a real decision (see `goal.md`
  roadmap item 4) before any ledger/transaction schema work begins.
