# P02 Central entitlements storage

Phase A | Depends on: P01 | Executor: worker
Design refs: section 3.2 in `plans/roles-permissions-entitlements.md`.

## Owned files

New: `database/migrations/2026_09_25_010000_add_enabled_modules_to_tenants_table.php`,
`tests/Feature/Central/TenantModulesTest.php`. Modified: `app/Models/Tenant.php`.

## Tasks

- [ ] 1. **Migration (central).** Add nullable JSON `enabled_modules` to `tenants`. Backfill every existing
  tenant where the column is NULL with a **frozen literal list** of all non-core module keys as of P01 (do not
  read `config/permissions.php` inside the migration, config changes later). Idempotent (only touches NULL
  rows). Working `down()` dropping the column. SQLite and MySQL safe.
- [ ] 2. **Tenant model.** Add `enabled_modules` to `#[Fillable]`, to `getCustomColumns()` and to casts as
  `array`. `Tenant::creating` hook: when the attribute was not set, default to
  `config('permissions.default_modules')` (so tests and tinker-created tenants get everything; the central form
  always sends an explicit list). Methods: `entitledModules(): array` (via `PermissionCatalog::resolveModules`,
  always includes core, treats NULL as core only), `hasModule(string $module): bool`. Add a docblock stating the
  fail-closed semantics for NULL and why a column beats a pivot table (already loaded per request, zero queries).
- [ ] 3. **Tests.** NULL means core only; explicit list resolves dependencies (`pos` implies `sales`); unknown keys
  ignored; the creating hook fills all modules when unset and respects an explicit list (including an explicit
  empty list = core only); `hasModule('core')` always true; the value survives a fresh model load
  (`Tenant::find`).

## Done when

Existing tenants keep every feature after migration, new tenants default to all modules unless given a list, model methods tested.

## Notes

Do not touch `TenantController`: the central form and store validation belong to P10.
