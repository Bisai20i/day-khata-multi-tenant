---
paths:
  - 'routes/tenant*.php'
---

# Routes

## Every tenant route needs a catalog can: gate
Every authenticated tenant route carries `->middleware('can:<key>')` with a key from config/permissions.php, or is listed (with a reason) in the allowlist / controller-authorized lists in tests/Feature/Tenant/Authorization/RouteAuthorizationAuditTest.php, which fails CI otherwise. Never use `role:` middleware or `role?->slug === 'admin'` checks; use `$user->can('key')` server side and `usePermissions().can('key')` in Vue. Add the route to todo/permissions/ROUTE-MAP.md and the nav item a `permission` field.
