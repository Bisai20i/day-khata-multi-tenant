# P10 Central modules UI

Phase C | Depends on: P02, P04 | Executor: worker (1, 2, 3), coordinator (route wiring)
Design refs: sections 3.2, 5 in `plans/roles-permissions-entitlements.md`.

## Owned files

New: `app/Http/Controllers/Central/Tenants/TenantModuleController.php`,
`app/Http/Requests/Central/UpdateTenantModulesRequest.php`, `tests/Feature/Central/TenantModuleControllerTest.php`,
child component(s) under `resources/js/pages/Central/Tenants/`.
Modified: `TenantController::store`, `Show.vue`, `Create.vue` (locate exact request class names by Grep).
Coordinator: `routes/central-tenants.php`.

## Tasks

- [ ] 1. **Backend.** `TenantModuleController@update` (route request: `PUT /tenants/{tenant}/modules`, name
  `central.tenants.modules.update`, `auth:platform`). The FormRequest validates keys against the catalog, rejects
  `core` being removed (always on), and auto-adds required dependencies server-side (never trust the client).
  Writes `enabled_modules` and logs a central activity entry with before and after (reuse the existing platform
  activity-log mechanism, locate it by Grep). `TenantController::store` validates an explicit module selection
  (default all) and passes `enabled_modules` into `Tenant::create` so provisioning sees it (P13 depends on this).
- [ ] 2. **UI.** "Modules" section on the tenant Show page and module checkboxes on Create. Grouped checkbox list
  with labels from the catalog (passed as a prop), `core` shown checked and disabled, ticking a module auto-ticks
  its dependencies and unticking a required module warns and unticks dependents. Use existing `components/ui/*`.
- [ ] 3. **Tests.** Only platform admins can update; dependency closure applied server-side; `core` cannot be
  removed; unknown keys 422; activity entry written with before and after; store persists the selection; a
  tenant with a module removed gets 403 on that module's route while the others keep working.

## Done when

Platform admins can set modules on create and later, dependency-safe and audited.
