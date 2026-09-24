# P15 Central owner reassignment

Phase C | Depends on: P12 | Executor: worker, coordinator (route)
Design refs: section 5 in `plans/roles-permissions-entitlements.md`.

## Owned files

New: `app/Http/Controllers/Central/Tenants/TenantOwnerController.php`,
`app/Http/Requests/Central/ReassignTenantOwnerRequest.php`, `tests/Feature/Central/TenantOwnerReassignmentTest.php`,
a small section component under `resources/js/pages/Central/Tenants/`. Modified: `Show.vue`.
Coordinator: `routes/central-tenants.php`.

## Tasks

- [ ] 1. **Backend.** `POST /tenants/{tenant}/owner` (`auth:platform` plus `can:platform-owner`, name
  `central.tenants.owner.update`). Runs `$tenant->run(...)` calling `OwnershipTransfer::run()` from the current
  owner (or, if a tenant somehow has none, promotes the target) to the chosen active tenant user. Validates the
  target belongs to that tenant's users and is active. Central activity-log entry with before and after.
- [ ] 2. **UI.** "Owner" section on the tenant Show page: current owner, a select of active users, a confirm dialog.
- [ ] 3. **Tests.** Only platform owners can reassign, exactly one owner afterwards, inactive target rejected, a tenant
  with no owner gets one, the activity entry is written.

## Done when

A platform admin can recover a tenant whose owner has left, audited.
