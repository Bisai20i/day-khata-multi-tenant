# P01 Permission catalog and route map

Phase A | Depends on: nothing | Executor: worker (checkboxes 1 and 3), coordinator (checkbox 2)
Design refs: sections 3.1, 6 in `plans/roles-permissions-entitlements.md`.

## Owned files

New: `todo/permissions/ROUTE-MAP.md`, `app/Support/Permissions/PermissionCatalog.php`,
`tests/Unit/Support/Permissions/PermissionCatalogTest.php`.
Coordinator writes `config/permissions.php` (config is coordinator-only).

## Tasks

- [ ] 1. **Route map (worker, read-only on routes).** Read every `routes/tenant*.php` with Grep/Read (do not
  boot Laravel, no `route:list`). Write `todo/permissions/ROUTE-MAP.md`: one row per route with columns
  `route name | method + URI | controller@method | gated today (admin / any authenticated) | proposed permission key`.
  Key convention `<resource>.<action>`, actions from: `view, create, edit, delete, cancel, print, export,
  import, manage`. One permission may cover several routes (e.g. the index and a data endpoint). Also list
  the proposed modules (`core, sales, pos, agents, quotations, purchases, inventory, accounting, reports, admin`,
  adjust if the routes demand it) with their `requires`, and mark which keys are `owner_only`:
  `roles.manage`, `backups.manage`, `fiscal_year.close_archive`, `ownership.transfer` (no others).
  Add a second table listing every key whose routes are **all** "any authenticated" today (these are the
  Staff parity keys P03 freezes). Routes that must stay authenticated-only (dashboard, profile, logout,
  impersonate) go in an "allowlist" table, they get no permission.
- [ ] 2. **Config (coordinator).** From the reviewed ROUTE-MAP write `config/permissions.php` with `modules`
  (`label`, `always_on`, `requires`), `permissions` (`module`, `group`, `label`, `owner_only`, `legacy`
  optional) and `default_modules` (all non-core module keys). Order permissions by group for the role editor.
- [ ] 3. **Accessor and tests (worker).** `PermissionCatalog` (static, memoized per process, no DB): `modules()`,
  `permissions()`, `has($key)`, `moduleOf($key)`, `isOwnerOnly($key)`, `keysForModules(array $modules)`,
  `resolveModules(array $enabled)` (adds `always_on` and transitive `requires`, drops unknown keys),
  `grantable()` (all non-owner-only keys), `grouped()` (for the UI). Pest unit tests: every permission
  references a known module, `requires` is acyclic and references known modules, owner-only keys are exactly
  the four agreed, `core` is `always_on`, `resolveModules(['pos'])` includes `sales` and `core`, unknown
  module keys are ignored, no permission key duplicated.

## Done when

ROUTE-MAP.md covers every tenant route (count matches the route files), config exists, catalog unit tests written.

## Notes

Key names chosen here are permanent API (stored in role JSON). Prefer stable nouns over UI wording.
