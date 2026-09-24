# P11 Tenant roles UI (owner only)

Phase C | Depends on: P04, P09 tasks 1-2 | Executor: worker (1, 2, 3), coordinator (routes, nav)
Design refs: sections 3.3, 5 in `plans/roles-permissions-entitlements.md`.

## Owned files

New: `app/Http/Controllers/Tenant/Admin/RoleController.php`, `app/Http/Requests/Tenant/Admin/StoreRoleRequest.php`,
`UpdateRoleRequest.php`, `resources/js/pages/Tenant/Admin/Roles/` (Index.vue, Edit.vue, permission matrix child
component), `tests/Feature/Tenant/Admin/RoleManagementTest.php`.
Coordinator: new `routes/tenant-roles.php` (required in `routes/tenant.php`), nav entry.

## Tasks

- [ ] 1. **Backend.** Routes (all `can:roles.manage`, an owner-only key): index, create/store, edit/update,
  duplicate, destroy. Validation: name unique, `permissions` array of catalog keys only, **each key must belong to
  an entitled module and not be owner-only** (422 otherwise, never silently dropped). Update runs in a transaction
  and compares `updated_at` sent by the form to reject a stale overwrite (409 with a message). Destroy is refused
  while any user holds the role and always for `is_system` roles. Slug generated and unique, never user-supplied.
  Log role create/update/delete in the tenant activity log (locate the mechanism by Grep).
- [ ] 2. **UI.** Index lists roles with user counts. Edit shows a permission matrix grouped by module then group,
  only entitled modules, select-all per group and per module, changes highlighted, unsaved-changes warning.
  Duplicate role action. System roles show a badge, name locked for `admin`. Keep files under the JS size cap by
  extracting the matrix into its own component.
- [ ] 3. **Tests.** Non-owner gets 403 even when `roles.manage` is placed in their role JSON (owner-only key);
  granting a non-entitled or owner-only permission is rejected with 422; a stale update is rejected; delete is
  blocked while the role is in use and for system roles; duplicate copies the permission set; the edit page only
  lists entitled modules.

## Done when

Owner can fully manage roles, guarded, audited and tested.
