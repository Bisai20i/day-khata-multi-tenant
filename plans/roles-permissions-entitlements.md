# Roles, permissions and module entitlements (plan, drafted 2026-09-24)

Status: DRAFT for review. Nothing here is built. Task chunks are sized for the ~150k-token subagent cap
(`.ai/rules/general.md`): 2-3 checkboxes each, chunks touching the same files run serially.

## 1. Problem

The rewrite has role scaffolding but no real authorization:

- `roles` / `permissions` / `permission_role` exist per tenant, seeded with 4 placeholder permissions that
  **nothing reads** (`Role::hasPermission()` has no callers).
- The only enforcement is `role:admin` (`EnsureUserHasRole`, compares the role slug). Staff can reach every
  ungated route, Admin everything. No middle ground, so a Manager and a Cashier cannot differ.
- The central panel cannot decide which features a company is allowed to use (POS, agents, quotations...).
- The legacy app had ~79 per-feature privilege keys per employee (`config/privileges.php`,
  `employee_privileges`). That granularity was lost in the rewrite.

## 2. Locked decisions (agreed 2026-09-24, do not re-ask)

| Topic | Decision |
|---|---|
| Who controls what | Central panel decides which **modules** a tenant may use. The tenant **owner** creates roles and assigns permissions, only from the entitled modules. |
| Granularity | Per module and action (`sales.create`, `sales.cancel`), roughly 100-150 keys. Not flat page-level keys. |
| Assignment | One role per user. **No per-user overrides.** A one-off exception is its own role. |
| Plans | Not now. Per-tenant module checkboxes only. The storage shape allows plans later without a rewrite. |
| Delegation | Role and permission editing is **owner-only**. User management (`users.manage`) can be granted to a role, with the escalation guard in section 5. |
| Owner-only list | `roles.manage`, `backups.manage`, `fiscal_year.close_archive`, ownership transfer. Nothing else for now. |
| Starting roles | Fresh Manager and Cashier templates only. **No legacy `employee_privileges` import.** |
| Owner selection for existing tenants | Active admin whose email matches the tenant `contact_email`, else the lowest-id active admin. Other admins keep an Admin role. Central can reassign the owner. |
| Trial expiry | Does not trim modules (separate concern). |
| Style | No em dashes in docs, code comments or UI text. |

## 3. Architecture

Effective permission, evaluated in exactly one place:

```
can(P) = tenant is entitled to module(P)
         AND user.is_active
         AND ( user.is_owner  OR  ( role.permissions contains P  AND  P is not owner_only ) )
```

Entitlement is checked first and applies to the owner too. The owner bypasses role checks, never entitlements.

### 3.1 Catalog (code, single source of truth)

`config/permissions.php` (coordinator-owned, per `.ai/rules/js-lib.md`) plus a thin
`App\Support\Permissions\PermissionCatalog` accessor. Shape:

```php
'modules' => [
    'core'  => ['label' => 'Core', 'always_on' => true, 'requires' => []],
    'pos'   => ['label' => 'Point of sale', 'requires' => ['sales']],
    ...
],
'permissions' => [
    'sales.create' => ['module' => 'sales', 'group' => 'Sales', 'label' => 'Create sale', 'legacy' => ['AddSell']],
    'roles.manage' => ['module' => 'core', 'owner_only' => true, ...],
],
```

- `legacy` records the old privilege key each permission replaces, for reference only (no import is built).
- `owner_only` permissions can never be granted to a role (see 3.4).
- `requires` gives module dependencies (POS needs Sales). Resolved transitively in one place.
- The catalog is static config, so `config:cache` covers it. No DB, no per-tenant seeding: a permission
  shipped in code is available to every tenant on deploy. This deliberately avoids the "tenant migrations
  are not automatic" trap.

Draft modules (final permission keys are derived in P01 by walking `php artisan route:list`, not invented):

| Module | Covers |
|---|---|
| core (always on) | dashboard, profile, items, customers/suppliers, chart of accounts, categories/brands/units |
| sales | sales, sales returns, receipts, cancel, print, export |
| pos | POS screen and till operations (requires sales) |
| agents | sales agents and agent reports (requires sales) |
| quotations | quotations |
| purchases | purchases, purchase returns, payments, capital purchases |
| inventory | stock adjustments, transfers, conversions, item varieties, stores |
| accounting | journal vouchers, fixed assets, fiscal year archive |
| reports | grouped: accounting, sales, purchase, inventory, tax (VAT/TDS), print log, damage/lost |
| admin | activity log, backups, notices, users, roles |

### 3.2 Central entitlements

- New nullable JSON column `tenants.enabled_modules` (central migration), cast to array on `Tenant`, added
  to `getCustomColumns()` and `#[Fillable]`.
- **Why a column and not a pivot table:** the tenant row is already loaded by `InitializeTenancyByDomain`
  on every request, so entitlements cost **zero extra queries** and need no cache (the cache bootstrapper
  is intentionally disabled in this app).
- Semantics are **fail closed**: `null` or missing means core only. The migration backfills every existing
  tenant with the full module list so nothing changes on deploy. A module added later is off by default,
  and the release that adds it grants it to existing tenants explicitly in its own data migration.
- `Tenant::hasModule()`, `Tenant::entitledModules()` (dependency-resolved, always includes `always_on`).
- Changing entitlements writes a central activity-log entry (before and after).

### 3.3 Tenant storage

- `roles.permissions` JSON array of permission keys (replaces the `permissions` + `permission_role` tables).
  One row read yields the whole grant set: **1 query per request**, memoized on the user instance.
  Keys are validated against the catalog on write. Unknown or removed keys are ignored on read, never fatal.
- `users.is_owner` boolean, **not mass-assignable**, set only by provisioning and the ownership-transfer
  action. Exactly one owner per tenant, enforced in the transfer action and covered by a test.
- Revoking a module leaves role grants in place (dormant, hidden in the role editor). Re-enabling restores
  them. No cleanup job, no stale-state race.

### 3.4 Enforcement

- Routes use Laravel's native `can:<permission>` middleware. `AppServiceProvider` (or a dedicated
  `AuthorizationServiceProvider`) registers `Gate::define` for every catalog key plus one
  `Gate::before` implementing the formula above.
- `Gate::before` **must return null** unless tenancy is initialized and the user is a tenant `User`, so the
  central `can:platform-owner` gate is untouched. Explicit test for both directions.
- `owner_only` permissions pass only for `is_owner`, regardless of what a role row contains.
- Deny by default: unknown ability on a tenant user returns false, not null.
- Forbidden results keep the existing behavior (403). Inertia requests get the existing error page.
- `EnsureUserHasRole` and the `role` alias stay through the transition (both mechanisms coexist safely,
  see 6) and are removed in P16.

### 3.5 Frontend

- `HandleInertiaRequests` shares `auth.can` (the user's effective permission keys, already intersected with
  entitlements) and `auth.isOwner`. Computed once per request from the memoized set.
- `usePermissions()` composable: `can('sales.cancel')`, `canAny([...])`.
- `nav-items.js` (coordinator-owned) gets an optional `permission` per item; `AppLayout` filters. Empty
  groups disappear.
- The UI only hides. **The server is the authority**, every gated route is checked server-side.

## 4. Performance budget

- Central entitlement check: 0 queries, in-memory array lookup on the already-loaded tenant.
- Tenant permission check: 1 indexed query per request (role row by primary key), memoized per user
  instance. Permission lookups are `in_array` over a small list, or a flipped-array hash if the set is large.
- Catalog resolution (module to permission map, dependency closure) is computed once per process and memoized
  in a static, nothing per request beyond one array intersect.
- No cache dependency, so no tenant-cache-bleed risk (`CacheTenancyBootstrapper` stays off).
- A Pest test asserts a gated page issues at most the agreed number of permission-related queries
  (regression guard against an accidental N+1 such as calling `hasPermission()` per nav item).
- Role editor and central modules page load the catalog from config: no DB reads for it.

## 5. Guard rails (security and robustness)

- **Escalation guard.** A user holding `users.manage` may assign only roles whose permission set is a
  subset of their own effective set. They cannot edit the owner, their own role, or roles above them.
- **Owner protection.** The owner cannot be deactivated, demoted or deleted (only transferred, by the owner or by a platform admin via P15). Replaces the current
  `guardLastActiveAdmin` with an owner-based rule. Ownership transfer is an explicit, confirmed,
  audit-logged action.
- **Role deletion** blocked while users hold the role (the FK is `nullOnDelete`, which would silently strip
  access, so the app must forbid it first).
- **Server-side entitlement re-validation** on every role save. A crafted request granting a permission
  from a non-entitled module is rejected (422), not silently dropped.
- **Deactivated user** is denied by `Gate::before` even if a session still exists.
- **Concurrency.** Role saves replace the whole JSON list inside a transaction with an `updated_at` check,
  so two owner sessions cannot silently overwrite each other.
- **Audit.** Central: entitlement changes. Tenant: role create/update/delete, role assignment, ownership
  transfer. Uses the existing activity-log mechanisms on each side.
- **Impersonation** (`tenant.impersonate`) lands as the tenant's owner user, so it inherits owner rights
  and still respects entitlements.
- `is_owner` is excluded from `#[Fillable]` and from every validated request payload.

## 6. Rollout and rollback (this is the risky part)

Release is one deploy, then in this order: deploy code, central `php artisan migrate`, then
`php artisan tenants:migrate` (tenant migrations do not run for existing tenants automatically).

Safe intermediate states, so a half-finished branch never locks users out:

- Existing "Admin" role rows are **kept**, so `role:admin` keeps working until P16 removes it. Routes can
  therefore be converted from `role:admin` to `can:` one file at a time.
- **Behavior-preserving backfill (P03/P14):**
  - the owner (`is_owner = 1`) is the active admin whose email equals the tenant's central `contact_email`,
    else the lowest-id active admin (the account provisioning created first);
  - any other admin-role user keeps the Admin role, whose grant set becomes every non-owner-only permission;
  - the Staff role gets exactly the permissions whose routes are **not** behind `role:admin` today, derived
    from the route files, so Staff loses and gains nothing on deploy. A test asserts this parity.
- Rollback: all migrations have working `down()`, are idempotent, and the old tables are dropped only in
  P16 after a full release cycle.

## 7. Task chunks

Route files and `nav-items.js` are coordinator-edited (`.ai/rules/js-lib.md`), so route-wiring chunks are run
by the coordinator with the mapping from P01, not by workers. Agents never run tests or migrations, the user
does. Owned-file lists are indicative, the coordinator finalizes them per chunk.

### Phase A: foundation

- [ ] **P01 Catalog.** `config/permissions.php`, `App\Support\Permissions\PermissionCatalog`, derive keys by
  scanning `route:list` (keep a mapping table route name to permission in the plan appendix for P05-P08).
  Tests: every permission has a module, every module known, `requires` acyclic, no duplicate legacy keys,
  owner-only never inside `always_on`-only leaks.
- [ ] **P02 Central entitlements storage.** Central migration `enabled_modules` (backfill all modules,
  idempotent), `Tenant` cast/fillable/custom column, `hasModule()`, `entitledModules()`, factory default.
  Tests: fail-closed null, dependency closure, always-on core.
- [ ] **P03 Tenant schema.** Tenant migrations: `users.is_owner`, `roles.permissions` JSON, `roles.is_system`;
  backfill per section 6 (idempotent, SQLite and MySQL safe, no doctrine/dbal). `Role` and `User` model
  updates (`hasPermission`, `isOwner`, memoized effective set). Tests for the backfill on a seeded legacy-shaped DB.
- [ ] **P04 Gate.** Provider with `Gate::define` loop and `Gate::before`, tenancy-guarded. Tests: owner
  bypass, entitlement wins over owner, role grant, owner-only never via role, inactive denied, platform
  gate unaffected, unknown ability denied, query-count guard.

### Phase B: enforcement (coordinator wires routes; workers adjust controllers/tests)

- [ ] **P05 Sales side.** sales, sales returns, receipts, POS, agents, quotations routes and pages.
- [ ] **P06 Purchase and accounting side.** purchases, returns, payments, capital purchases, ledger/journal,
  fixed assets, fiscal year archive.
- [ ] **P07 Masters and inventory.** business/masters, stores, item varieties, stock adjustments,
  transfers, conversions.
- [ ] **P08 Reports and admin.** every `tenant-reports-*.php`, activity log, backups, notices, users.
- [ ] **P09 Frontend plumbing and route audit.** Inertia shared `auth.can`/`isOwner`, `usePermissions`,
  `nav-items.js` permission field and `AppLayout` filtering, hide gated buttons on the main pages.
  **Route-audit test:** iterate `Route::getRoutes()`, for every tenant route under `auth:web` require a
  `can:` middleware, or presence in an explicit allowlist in the test (dashboard, profile, logout,
  impersonate). A forgotten gate fails CI. JS test for the composable.

### Phase C: management UIs

- [ ] **P10 Central modules UI.** `Central\Tenants\TenantModuleController` (edit/update), route in
  `central-tenants.php`, "Modules" section on tenant Show, checkboxes with dependency auto-select and
  disabled always-on core, default selection on Create, activity-log entry with before/after.
- [ ] **P11 Tenant Roles UI (owner-only).** `Tenant\Admin\RoleController` + FormRequests, `Roles.vue`
  permission matrix grouped by module, only entitled modules shown, select-all per group, duplicate role,
  in-use delete guard, optimistic-concurrency check.
- [ ] **P12 Users page.** Role assignment with the escalation guard, owner protection replacing
  `guardLastActiveAdmin`, ownership transfer action with confirmation.
- [ ] **P13 Provisioning.** `TenantDatabaseSeeder` seeds starter role templates (Manager, Cashier) limited
  to entitled modules, `CreateTenantFirstAdmin` sets `is_owner`, welcome mail unchanged. Tests via the
  existing provisioning test path.

### Phase D: migration and hardening

- [ ] **P14 Existing-tenant verification.** Read-only dry-run command listing the owner the backfill would
  pick per tenant (review before migrating), plus a test proving Staff parity and owner selection on a
  copy of a real tenant schema. Deployment checklist (section 6) executed and documented in `mem.md`.
- [ ] **P15 Central owner reassignment.** Platform-admin action on the tenant page to reassign the owner to
  another active tenant user (recovery for a departed or locked-out owner). Runs the same transfer action as
  P12, keeps exactly one owner, writes a central activity-log entry. Tests: platform admin only, target must
  be active, exactly one owner afterwards.
- [ ] **P16 Cleanup.** Remove `role` alias and `EnsureUserHasRole` once no route uses them, drop the old
  `permissions` / `permission_role` tables (needs your approval), record durable rules with `record-rule`
  (permission naming, "every tenant route needs `can:`", owner-only list), update `mem.md`.

Suggested order: P01 to P04 serially, P05 to P09 next (coordinator, serial), P10 in parallel with P11/P12
once P04 is done, P13 after P11, P15 after P12, then P14 and P16.

## 8. Test matrix (all Pest unless noted, run by the user)

1. Catalog integrity (P01), including a test that every gated route's permission exists in the catalog.
2. Gate truth table: owner / role grant / no grant / inactive / module off / owner-only / platform admin.
3. Route audit: no ungated tenant route outside the allowlist (P09).
4. Central: only platform admins can edit modules, dependency validation, activity entry written.
5. Roles: cannot grant non-entitled or owner-only permissions, delete guard, concurrent edit rejected.
6. Users: escalation guard, owner cannot be demoted or deactivated, transfer keeps exactly one owner.
7. Migration backfill: owner selection, Staff parity, idempotent second run, SQLite and MySQL safe.
8. Query-count guard on a gated page (section 4).
9. Revoking a module immediately 403s its routes for every user, including the owner, and hides its nav.
10. JS: `usePermissions`, nav filtering.

## 9. Open questions

None blocking. Items resolved on 2026-09-24 are in the locked decisions table (owner-only list, no legacy
import, owner selection rule, trial expiry). Revisit only if a real tenant's data contradicts the owner
selection rule in the P14 dry run.
