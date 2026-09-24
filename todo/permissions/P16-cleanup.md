# P16 Cleanup

Phase D | Depends on: P14 and one full release cycle without incident | Executor: worker (1, 2), coordinator (3)
Design refs: sections 6, 7 in `plans/roles-permissions-entitlements.md`.

## Owned files

New: `database/migrations/tenant/2026_09_25_160000_drop_legacy_permission_tables.php`. Modified/removed:
`app/Http/Middleware/EnsureUserHasRole.php`, `bootstrap/app.php` (alias, coordinator), `app/Models/Permission.php`,
`app/Models/Role.php` (old relation), related tests.

## Tasks

- [ ] 1. **Remove the `role` middleware.** First Grep `routes/` and the route-audit test: zero remaining
  `role:` usages. Remove `EnsureUserHasRole` and the `role` alias in `bootstrap/app.php` (coordinator). Its own
  tests: **ask the user before deleting any test**, prefer converting them to assert the new `can:` gates.
- [ ] 2. **Drop legacy tables (needs explicit user approval before the migration is written).** Tenant migration
  dropping `permission_role` then `permissions`, with a `down()` that recreates both empty and `Schema::hasTable`
  guards. Delete the `Permission` model, `Role::legacyPermissions()` and any factory referencing the old model.
- [ ] 3. **Durable rules and docs (coordinator).** Record with the Boost `record-rule` tool (if the laravel-boost
  server is unavailable, ask the user to reconnect it or put the rule text in the final report): permission
  key naming, "every tenant route needs `can:` or must be in the audit allowlist", the owner-only list, "never
  read config inside a data migration", entitlement semantics. Update `mem.md` and `goal.md`.

## Done when

No dead authorization code remains, rules recorded, docs current.

## Notes

P03 already renamed the old relation to `Role::legacyPermissions()`, so this chunk just deletes it.
