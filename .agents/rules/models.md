---
paths:
  - app/Models/Tenant.php
---

# Models

## Tenant entitlements: enabled_modules semantics
tenants.enabled_modules is fail-closed: NULL means core only. Tenants created without the attribute default to config('permissions.default_modules') (tests, tinker); an explicit [] means core only. Stored form is the dependency-resolved list minus always-on modules, in config order. Entitlements apply to the owner too. Revoking a module leaves role grants dormant; the role editor preserves them on save.
