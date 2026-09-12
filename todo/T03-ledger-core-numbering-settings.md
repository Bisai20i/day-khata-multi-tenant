# T03 Ledger core, numbering, settings

Phase 2 | Parallel with T04-T09 | Implements C4 and the numbering half of C7 | Audit refs: P0-2, P0-11,
P0-15 (voucher types), P0-16 (JV cancel), P1 opening balance import, admin gates, timezone, starting number,
prefix uniqueness, ledger `-0.00`.

Every module posts through `JournalVoucher`. Changes here are the ledger's integrity guarantee: be strict,
keep the signature of `post()` unchanged, and document every rule in the docblocks.

## Owned files

`app/Models/JournalVoucher.php`, `app/Models/JournalVoucherLine.php`, `app/Models/VoucherSequence.php`,
`app/Enums/VoucherType.php`, `app/Support/ClosedFiscalYearGuard.php`, `app/Models/CompanySetting.php`,
`app/Http/Controllers/Tenant/Accounting/JournalVoucherController.php`,
`app/Http/Controllers/Tenant/Accounting/AccountController.php`,
`app/Http/Controllers/Tenant/Accounting/AccountGroupController.php`,
`app/Http/Controllers/Tenant/Accounting/AccountSubgroupController.php`,
`app/Http/Controllers/Tenant/Admin/SettingsController.php`, `config/app.php`,
`routes/tenant-ledger.php`, `routes/tenant-business.php`, `routes/tenant-settings.php`,
`resources/js/pages/Tenant/Accounting/JournalVouchers/*.vue`, `resources/js/pages/Tenant/Accounting/Accounts/*.vue`,
`resources/js/pages/Tenant/Accounting/AccountGroups/Index.vue`,
`resources/js/pages/Tenant/Accounting/AccountSubgroups/Index.vue`,
`resources/js/pages/Tenant/Admin/Settings/Edit.vue`,
`tests/Feature/Tenant/Accounting/JournalVoucherPostingTest.php`, `LedgerControllerTest.php`,
`OpeningBalanceImportTest.php`, `ChartOfAccountsTest.php`, `FiscalYearReopenTest.php` (only the back-dating
assertion, see task 3), `tests/Feature/Tenant/Admin/CompanySettingTest.php`, new tests under
`tests/Feature/Tenant/Accounting/`. Migrations with prefix `2026_09_12_03`.

## Tasks

- [x] 1. `VoucherType`: add `SalePan` and `Reversal` (C4). Keep the existing docblock style; explain why each
  series is separate.
- [x] 2. `validateLines()` / `write()`: normalise each line through `Money::of()` (throws on more than 2
  decimals), reject zero lines, compare totals with `Money::sum(...)->isEqualTo()` (exact). Store exactly the
  normalised strings. `JournalVoucherLine` casts switch to `Decimal::class.':2'`.
- [x] 3. Date guard in `post()` (C4): the date must be inside the resolved fiscal year. Add
  `ClosedFiscalYearGuard::assertDateInOpenYear()` (C4) and use it. Update the existing
  `FiscalYearReopenTest` case that posts a back-dated sale into the new year so it now asserts the rejection.
  Keep `write()`'s system-bookkeeping path (close, roll-forward) working: it targets explicit years with dates
  inside them.
- [x] 4. `nextVoucherNumber()`: create the sequence row race-safely (insert-or-ignore then
  `lockForUpdate()`), never a 500 on a simultaneous first post. Add
  `VoucherSequence::setStartingNumber()` (C4).
- [x] 5. `JournalVoucher::reverse()` per C4, migration adding `journal_vouchers.reversal_of_id` (nullable FK,
  indexed). Rewrite `JournalVoucher::cancel()` (manual journals) on top of it, with `lockForUpdate()` + status
  re-check inside the transaction.
- [x] 6. Opening balance import (`AccountController`): re-import replaces the previous import batch (reverse
  the prior Opening Balance voucher via `reverse()`-style mirroring, allowed for import-created Opening Balance
  vouchers only), reject P&L accounts and the stock account `AS11` (opening stock value comes only from the
  opening stock import, T08), reject amounts with more than 2 decimals, admin-only route. Add a small
  "Opening balance imports" list with a clear (reverse) action on the Accounts page.
- [x] 7. Ledger view (`AccountController` ledger + `Accounts/Ledger.vue`): running balance with `Money`, no
  `-0.00`, amounts as strings, formatted with `formatMoney`, BS date column via `formatBsDate`.
- [x] 8. `JournalVouchers/Create.vue`: debit/credit totals and the balanced indicator via `money.js` (exact
  `moneyEquals`, no tolerance), inputs `inputmode="decimal"` with `step="0.01"`, default date
  `todayInKathmandu()`.
- [x] 9. Settings (`SettingsController`, `CompanySetting`, `Settings/Edit.vue`): prefixes must be distinct
  across all series (full, abbreviated, PAN, sales return, purchase return); new "Invoice numbering" section
  (admin) showing each series' next number for the open fiscal year with a "Set starting number" action using
  `VoucherSequence::setStartingNumber()` (disabled once a document of that series exists); casts to `Decimal`.
- [x] 10. `config/app.php`: `'timezone' => env('APP_TIMEZONE', 'Asia/Kathmandu')`. Grep the tests you own for
  UTC-sensitive date assertions and fix them.
- [x] 11. Admin gates (`role:admin`) in your route files for: journal voucher create/store/cancel, chart of
  accounts and groups/subgroups write routes, opening balance import, settings. Block changing an account
  group's head after its accounts have postings (`AccountGroupController`).
- [x] 12. Tests (write, do not run): exact balance with `333.333` rejected; zero line rejected; date outside
  the fiscal year rejected; reversal uses the `Reversal` series and never consumes a Sale/Receipt number;
  reverse of a voucher from a closed year rejected; starting number applies once and is refused after the
  first document; distinct prefixes; opening balance re-import replaces; AS11 and P&L accounts rejected;
  staff get 403 on the admin routes.

## Acceptance

- `JournalVoucher::post()` signature unchanged; every module keeps working without edits except for
  switching its own cancel to `reverse()`.
- No float math left in owned files (`grep -n "(float)\|round(" ` on them returns nothing unjustified).
