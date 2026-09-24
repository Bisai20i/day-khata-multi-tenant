# P14 Existing-tenant dry run and parity checks

Phase D | Depends on: P03, P13 | Executor: worker (1, 2), coordinator (3)
Design refs: section 6 in `plans/roles-permissions-entitlements.md`.

## Owned files

New: `app/Console/Commands/PermissionsOwnerDryRun.php`, `tests/Feature/Console/PermissionsOwnerDryRunTest.php`,
`tests/Unit/Support/Permissions/RoleBackfillParityTest.php`. Modified: `mem.md` (coordinator).

## Tasks

- [ ] 1. **Dry-run command.** `permissions:owner-dry-run` iterates all tenants (tenant context per tenant) and
  prints, **read-only**, for each: the user the backfill would pick as owner and why (contact email match or
  lowest id), other admins that will keep the Admin role, tenants with no active admin (flagged), and the
  role/user counts. It writes nothing. Exit code 1 if any tenant has no owner candidate.
- [ ] 2. **Parity tests.** Unit test: frozen Staff parity keys contain no owner-only key and only keys present in
  the catalog at release time; the Admin frozen list equals all non-owner-only keys; a legacy-shaped fixture
  (admin + staff + inactive admin) backfills as documented; console test for the dry-run output and exit codes.
- [ ] 3. **Coordinator.** Run through the START.md deployment checklist with the user on a copy of production
  data if available, record the outcome and the architecture summary in `mem.md`.

## Done when

Dry run and parity tests exist, and the deployment checklist has been executed once on non-production data.
