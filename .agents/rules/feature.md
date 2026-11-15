---
paths:
  - 'tests/Feature/**'
---

# Feature

## Permission test traps: factory owners, stale tenant, route() host
User::factory() with no role_id creates the tenant OWNER (full access); use userWithPermissions()/roleWithPermissions() for a restricted actor. After changing a tenant's enabled_modules between HTTP requests, call tenancy()->end() first or the next request reuses the stale tenant instance. route() reuses the previous request's host, so build central URLs as 'http://localhost'.route(..., false) after hitting a tenant domain, and auth()->forgetGuards() before switching guards.
