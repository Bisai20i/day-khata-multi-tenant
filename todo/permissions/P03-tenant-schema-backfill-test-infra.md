# P03 Tenant schema, backfill and test infrastructure

Phase A | Depends on: P01, P02 | Executor: worker
Design refs: sections 3.3, 5, 6 in `plans/roles-permissions-entitlements.md`.

## Owned files

New: tenant migrations `database/migrations/tenant/2026_09_25_020000+` (schema) and `..._020100+` (data),
`app/Support/Permissions/RoleBackfill.php`, `tests/Unit/Support/Permissions/RoleBackfillTest.php`.
Modified: `app/Models/Role.php`, `app/Models/User.php`, `database/factories/UserFactory.php`,
`tests/Pest.php` (helpers only).

## Tasks

- [ ] 1. **Schema migration (tenant).** `users.is_owner` boolean NOT NULL default false; `roles.permissions` JSON
  nullable; `roles.is_system` boolean NOT NULL default false. Working `down()`. Do not drop the old
  `permissions`/`permission_role` tables here (P16 does that after a release cycle).
- [ ] 2. **Backfill.** `RoleBackfill` class with **frozen constants** (never read config, never edited after
  release): the full grantable key list, and the Staff parity list from ROUTE-MAP. `run()` is idempotent and
  does: `admin` role gets all grantable (non-owner-only) keys and `is_system = true`; `staff` role gets the
  Staff parity keys; any other existing role gets an empty list; the **owner** is the active admin-role user
  whose email equals `tenant('contact_email')` (guard `tenancy()->initialized`), else the lowest-id active
  admin-role user, else nobody (never throw); if an owner already exists it changes nothing.
  A second data migration calls `RoleBackfill::run()`. Unit tests on seeded legacy-shaped rows: owner by
  contact email, owner fallback by lowest id, deactivated admins skipped, second run changes nothing, other
  admins keep the admin role, Staff receives exactly the frozen list, no owner-only key ever appears in a role.
- [ ] 3. **Models, factory, helpers.** `Role`: fillable `permissions` (array cast), `is_system`. The existing
  `permissions()` belongsToMany relation clashes with the new JSON attribute of the same name, so **rename that
  relation to `legacyPermissions()` here** (and its inverse on `Permission` if it references it, plus the one seeder
  use, P13 removes it) and Grep for other callers; P16 deletes it. `User`: `is_owner` boolean cast, **not** in `#[Fillable]`,
  `isOwner(): bool`. `UserFactory`: `is_owner` as a closure attribute that is true when no `role_id` was given
  and false otherwise (factories bypass guarding). `tests/Pest.php`: add `roleWithPermissions(array $keys,
  string $name = 'Test role'): Role` and `userWithPermissions(array $keys): User` (creates the role, then a
  user with that role, `is_owner` false). Keep `tests/Pest.php` edits limited to these helpers.

## Done when

Schema and backfill migrations exist and are idempotent; backfill unit tests written; factory and helpers ready for every later chunk.

## Notes

Backfill must never throw inside `tenants:migrate`: a tenant with no admin simply gets no owner and P14's dry run surfaces it.
