# P09 Frontend plumbing, nav filtering and route audit

Phase B (tasks 1-2 first, task 3 last) | Depends on: P04 (tasks 1-2); P05-P08 (task 3) | Executor: worker (1, 3), coordinator (2 nav-items.js)
Design refs: sections 3.5, 8 in `plans/roles-permissions-entitlements.md`.

## Owned files

New: `resources/js/composables/usePermissions.js`, `tests/js/usePermissions.test.js`,
`tests/Feature/Tenant/Authorization/RouteAuthorizationAuditTest.php`.
Modified: `app/Http/Middleware/HandleInertiaRequests.php`, the shared layout that renders the nav (find via Grep for
`nav-items` imports, `resources/js/layouts/AppLayout.vue`).
Coordinator: `resources/js/lib/nav-items.js` (add a `permission` field per item).

## Tasks

- [ ] 1. **Shared props and composable.** `HandleInertiaRequests` shares `auth.can` (list of the user's effective
  permission keys from the memoized set, empty for guests and platform admins) and `auth.isOwner`, both computed
  lazily (closures). `usePermissions()` exposes `can(key)`, `canAny(keys)`, `isOwner`. JS test for both helpers.
- [ ] 2. **Nav filtering.** Coordinator adds an optional `permission` string (or `permissions` array meaning any) to
  every tenant nav item in `nav-items.js` using the catalog keys; the layout filters items and drops groups that
  become empty. Central nav is untouched. Keep the layout file under the JS size cap.
- [ ] 3. **Route audit (run LAST, after P05-P08).** Feature test iterating `Route::getRoutes()`: every route whose
  middleware includes `auth:web` under the tenant domain must carry a `can:` middleware whose ability exists in
  `PermissionCatalog`, unless its name is in an explicit allowlist array inside the test (dashboard, profile
  routes, logout, impersonate, and only those from ROUTE-MAP's allowlist table). Also assert no tenant route
  still uses the legacy `role:` middleware except a documented list that must be empty by P16. The failure
  message names the offending route.

## Done when

Shared props and nav gating work, JS test written, route audit test written and (after P05-P08) passing.

## Notes

The UI only hides things. Never rely on it for security, the server gate is the authority.
