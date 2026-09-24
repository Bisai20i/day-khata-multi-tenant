# P04 Gate and effective permissions

Phase A | Depends on: P01, P02, P03 | Executor: worker (1, 2, 3), coordinator (registers the provider)
Design refs: sections 3.4, 4 in `plans/roles-permissions-entitlements.md`.

## Owned files

New: `app/Support/Permissions/EffectivePermissions.php`, `app/Providers/AuthorizationServiceProvider.php`,
`tests/Feature/Tenant/Authorization/GateTest.php`. Modified: `app/Models/User.php`.
Coordinator: `bootstrap/providers.php` (register the provider).

## Tasks

- [ ] 1. **Effective permissions.** `EffectivePermissions::for(User $user): array` returning the flipped key set:
  empty when the user is inactive or the tenant is not initialized; the owner gets every catalog key of the
  entitled modules (owner-only included); a role user gets `role.permissions` intersected with keys of entitled
  modules and minus owner-only keys; unknown keys ignored. `User::effectivePermissions()` memoizes on the
  instance (1 role query per request) and `User::hasPermission(string $key): bool` uses it.
- [ ] 2. **Provider.** `AuthorizationServiceProvider::boot()`: `Gate::define` for every catalog key delegating to
  `hasPermission`, plus one `Gate::before` that returns null (fall through) unless tenancy is initialized and the
  user is an instance of `App\Models\User`, and returns false when the user is inactive or the ability's module is
  not entitled. It must not interfere with the central `can:platform-owner` gate in `AppServiceProvider`.
  Undefined abilities fall through to Laravel's default deny. Coordinator adds the provider to
  `bootstrap/providers.php`.
- [ ] 3. **Tests (truth table).** owner allowed; owner denied when the module is off; role grant allowed; missing
  grant denied; owner-only key denied for a role whose JSON row contains it; inactive user denied; unknown ability
  denied; `Gate::forUser($platformAdmin)->allows('platform-owner')` unaffected in central context (both
  directions); revoking then restoring a module restores the role grants untouched; **query-count guard**: 20
  `can()` calls on one request issue at most 1 role query (use `DB::listen`).

## Done when

Gate behaves per the formula, tests written, provider registered.

## Notes

Use `Gate::before` only for the tenant-user cases above. Never return true from `before` for non-catalog abilities.
