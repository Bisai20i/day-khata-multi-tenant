# P13 Provisioning and role templates

Phase C | Depends on: P10, P11 | Executor: worker
Design refs: sections 3.3, 6 in `plans/roles-permissions-entitlements.md`.

## Owned files

New: `app/Support/Permissions/RoleTemplates.php`, `tests/Feature/Tenant/ProvisioningRolesTest.php`.
Modified: `database/seeders/Tenant/TenantDatabaseSeeder.php`, `app/Jobs/CreateTenantFirstAdmin.php`, existing tests that
reference the old `staff` slug or the old placeholder permissions.

## Tasks

- [ ] 1. **Templates and seeder.** `RoleTemplates` holds frozen key lists for Manager and Cashier (defined below).
  The seeder stops creating the 4 placeholder permissions and the `permission_role` attach; it seeds `admin`
  (system, all grantable keys), `manager` and `cashier`, each filtered at seed time to keys of
  `tenant()->entitledModules()` (the seeder runs inside tenant context). Stop referencing the old `Permission`
  model in the seeder.
  Cashier: view/create/print sales, POS use, create receipts, view and create customers, view items, no
  cancels, no reports, no purchases. Manager: all operational create/edit/cancel/print/export and all report keys
  in the entitled modules, no `users.manage`, no `activity_log`, no owner-only keys.
- [ ] 2. **First admin.** `CreateTenantFirstAdmin` creates the user with `is_owner = true` and the `admin` role
  (kept for the transition), via explicit attribute assignment since `is_owner` is not fillable.
- [ ] 3. **Tests.** Provisioning a tenant with a module subset seeds roles without grants from missing modules;
  the first admin is the only owner; the `staff` slug no longer exists for new tenants, so update existing tests
  that used it to `cashier` or `roleWithPermissions()`; no test references the removed placeholder permissions.

## Done when

New tenants come up with an owner and sensible Manager and Cashier roles limited to their entitlements.

## Notes

Lists in RoleTemplates are proposals for the user to review in the diff before commit. Prefer fewer grants when unsure.
