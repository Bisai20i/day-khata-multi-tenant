# P06 Purchase and accounting enforcement

Phase B | Depends on: P05 | Executor: coordinator (1), worker (2, 3)
Design refs: sections 3.4, 6 in `plans/roles-permissions-entitlements.md`.

## Owned files

Coordinator: `routes/tenant-purchase.php`, `tenant-purchase-returns.php`, `tenant-payments.php`, `tenant-ledger.php`, `tenant-fixed-assets.php`, `tenant-fiscal-year-archive.php`, `resources/js/lib/nav-items.js` if entries are missing.
Worker: the controllers and Vue pages for these modules (purchases, capital purchases, returns, payments, journal vouchers, fixed assets, fiscal year), plus existing tests under
`tests/Feature/Tenant/` that hit these routes (update, never delete).

## Tasks

- [ ] 1. **Route wiring (coordinator, first).** In the route files above replace each `role:admin` (and add where
  currently ungated) with `can:<key>` exactly as in `ROUTE-MAP.md`; group routes sharing a key. Add the
  `owner_only` keys where they appear. Do not remove the `role` alias yet.
- [ ] 2. **Page gating (worker, after 1).** Using `usePermissions()`, hide or disable buttons, row actions and links
  the user lacks (create, edit, cancel, print, export, delete). Do not change layout otherwise. Keep files under the JS
  size cap (extract child components instead of growing a page).
- [ ] 3. **Tests (worker).** Update existing tests in this area that now 403 (use `userWithPermissions()`), then add
  one feature test per gated route group covering: allowed with the key, 403 without it, 403 when the module is off
  even for the owner, and the owner allowed when entitled. Data-driven with a Pest dataset over the ROUTE-MAP rows for
  this chunk to keep it short.

## Done when

Every route in the listed files is gated by a catalog key, pages hide unavailable actions, tests written.

## Notes

Modules: purchases, accounting. Fiscal-year close and archive use the owner-only key `fiscal_year.close_archive`.
