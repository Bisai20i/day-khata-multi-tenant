# Invoicing settings, fiscal-year correction & Sale/Purchase UX upgrade plan

**Status as of 2026-09-08**: research complete, scope locked with the user (see "Locked decisions"),
**no phase started yet**. This doc is the build plan - read it before starting work, update its Status
line as phases land, same discipline as `plans/central-panel-build.md`.

## Why this exists

The user compared the live tenant app against the legacy single-tenant predecessor (`D:\Projects\day-khata\
day_khata`) and found the rewrite has fallen behind in three places: invoice type/format/numbering has no
real settings surface, fiscal years can be closed but never reopened for correction, and the Add Sale /
Add Purchase pages are missing usability legacy already had (percent discount, chalani number, negative-
stock policy, save-and-print). **The mandate is to upgrade past legacy, not just port it** - legacy's own
implementation is often inconsistent (chalani number on 3 of 6 sale screens but 0 purchase screens,
negative-stock checkbox on 2 of 6 sale forms, 6 separate hardcoded sale entry screens instead of one
configurable form) and this plan deliberately does not replicate that fragmentation.

## Sources

Two full-codebase research passes (this session, 2026-09-08), one per app, each covering: invoice
configuration, fiscal year management, general settings, and the Add Sale / Add Purchase pages. Findings
below are condensed from those passes plus direct verification of `FiscalYear`, `CompanySetting`, and
route files. Full per-file findings are in this session's transcript if deeper detail is ever needed.

## Locked decisions (user sign-off via `AskUserQuestion`, 2026-09-08)

1. **Invoice type model**: keep the current single unified Create-Sale form with a per-sale invoice-type
   dropdown (abbreviated / full / pan). Do **not** replicate legacy's tenant-wide "invoice category"
   setting that gates which of 6 separate sale screens even exist. Instead, invoice types become
   **config-driven**: a Settings section controls which types are enabled and their numbering
   prefix/print behavior, but the entry point stays one form.
2. **Negative stock**: new tenant-level `CompanySetting.allow_negative_stock` toggle, **off (blocking) by
   default**. When off, a real server-side check rejects a sale that would drive stock below zero - on
   every entry point (plain Create form + POS), not a frontend-only warning. When on, allowed everywhere
   consistently.
3. **Fiscal year reopen/correction**: in scope for this plan. A closed year can be reopened by an admin
   (`role:admin`, mandatory reason, audited) for corrections to Purchase / Journal Voucher / Stock
   Adjustment postings only - **Sales stays permanently locked outside the single currently-open fiscal
   year, no exception**, matching legacy's policy exactly.

## Non-goals (explicitly out of scope for this plan)

- **Batch/lot/serial inventory tracking.** Neither app has this - legacy explicitly descoped it
  (`plans/financial-year.md §5.4` in the legacy repo). Real gap versus "mature inventory software" in the
  abstract, but not a legacy-parity gap, so it doesn't belong in *this* plan. Flag as a separate future
  epic if the user wants it.
- **Multi-currency.** Legacy is NPR-only with no currency setting; matching that, not adding one.
- **Replicating legacy's 6-screen sale UI, its 8-value `billingType` menu-gating system, or its
  DB-view/session-variable fiscal-year-scoping trick.** These are legacy implementation details, not
  requirements - this app's `fiscal_year_id`-per-row scoping is already a cleaner design that avoids two
  documented legacy data-integrity bugs (silent-drop-on-missing-opening-row; single-row-instead-of-summed
  aggregate). Keep it.
- **Global keyboard-shortcut scheme on the plain Sales/Purchases Create forms.** POS already has one
  (F1/F2/F4/F7/F8/F9/Esc); porting an equivalent to the ledger-style Create forms is a bigger redesign of
  those forms' interaction model. Worth a later, separate pass - not bundled here.

## Already strong - do not re-litigate

Confirmed by direct code reading, not just the research agents' word:

- **Fixed Assets** (`app/Models/FixedAsset.php`, `FixedAssetController`) already exist with
  depreciation posted automatically at fiscal-year close (`FiscalYear::close()` calls
  `FixedAsset::postDepreciationForFiscalYear()` first). Legacy has the same; no gap.
- **TDS** already exists on **both** Sale and Purchase (`tds_account_id`/`tds_amount`) - legacy only has
  it on Tax Purchase and Service Billing (sales side), not general Sale. This app is already ahead here.
- **`FiscalYear::close()`'s balance math is self-balancing by construction** (it derives closing/opening
  entries directly from real `JournalVoucherLine` sums, never recomputes independently) - this sidesteps
  both of legacy's documented closing-balance bugs by design. No "trial balance must match before close"
  guard needs adding; there's nothing for it to catch that the derivation itself doesn't already prevent.
- **Party/item search UX** (`Combobox.vue`) is already a real searchable autocomplete on every form, and
  POS's product grid + scan-to-add + multi-cart-draft + F-key shortcuts already exceed legacy's POS in
  several ways (cross-terminal reservation aside, which legacy has and this app doesn't - not in scope
  here, flag separately if wanted).

## Gap inventory (condensed)

| Area | Current state | Gap |
|---|---|---|
| Invoice numbering | Bare per-fiscal-year integer, prefix hardcoded in PHP `match` in controllers | No configurable prefix, no logo, no per-type enable/disable |
| Company logo | No column, no upload, not rendered in any PDF | Missing entirely |
| Default VAT rate | Hardcoded `13` client-side in each form's `useForm()` | No settings-level default |
| Negative stock | Zero server-side check anywhere; POS shows an advisory toast only | No policy, no enforcement |
| Discount | Flat-Rs only on plain Create forms; percent/flat toggle exists but is POS-only and client-resolved | Not a real field on Sale/Purchase, not on plain forms |
| Chalani number | Does not exist anywhere in this app | Missing entirely (legacy has it inconsistently) |
| Barcode | POS simulates scan-to-add but `Item` has no `barcode` column | Missing entirely |
| Save & print | Print is a fully separate manual step from the Index page; POS's "complete sale" only shows a frontend-only receipt modal | No integrated save-and-print flow anywhere |
| Receipt paper size | Only A4/A5 | No thermal (58/80mm) option for POS |
| Fiscal year reopen | `close()` only, one-way | No reopen/correction path at all |
| Default store | `Store` model + `store_id` exist; `CompanySetting` has no default | Falls back to "first active store by id" |

## Shared schema - design once, before any phase starts

**`company_settings` gains** (one migration): `logo_path` (nullable string, storage-relative path),
`default_vat_rate` (decimal 5,2, default `13.00`), `allow_negative_stock` (boolean, default `false`),
`default_store_id` (nullable FK `stores`), and three enable/prefix pairs for the three existing sale
invoice types - `sale_full_prefix` (default `SL`), `sale_full_enabled` (default `true`),
`sale_abbreviated_prefix` (default `SLA`), `sale_abbreviated_enabled` (default `true`),
`sale_pan_prefix` (default `SLP`), `sale_pan_enabled` (default `true`), plus `purchase_prefix` (default
`PU`). Real typed columns, not a generic settings table - this project's established "no speculative
abstraction" rule (see `plans/central-panel-build.md`'s `platform_settings` design note) applies the same
way here: there are exactly 3 known sale invoice types today, not an open-ended list, so a per-type child
table would be solving a problem that doesn't exist yet.

**`sales` and `purchases` gain**: `chalani_number` (nullable string, both tables - deliberately symmetric,
unlike legacy's sales-only/inconsistent-per-form treatment), `discount_type` (string enum
`percentage`|`flat`, default `flat`) alongside the existing `discount` column (reinterpreted as "the
value in whichever unit `discount_type` says" - server computes the actual Rs amount at post time rather
than trusting a client-resolved number, more correct than legacy's client-side math). Same
`discount_type`/`discount` pairing added to each `sale_lines`/`purchase_lines` row (currently flat-only).

**`items` gains**: `barcode` (nullable, unique when not null).

**`fiscal_years` gains**: `closed_by` (nullable FK `users`), `closed_at` (nullable timestamp),
`reopened_by` (nullable FK `users`), `reopened_at` (nullable timestamp), `reopen_reason` (nullable text),
`relocked_at` (nullable timestamp). A closed year with `reopened_at` set and `relocked_at` null is "open
for correction" - this is a flag on the closed year, not a new `FiscalYearStatus` case, so
`FiscalYear::current()`'s `where('status', Open)` invariant (exactly one open year) is untouched.

## Phase A - Settings foundation (schema + redesigned Settings page)

- Migration for all `company_settings` columns above.
- `CompanySetting` model: add new fields to `#[Fillable(...)]`, add `logoUrl()` accessor (returns a
  `Storage::url()` or null).
- `SettingsController::update()`: validate + persist new fields; logo upload via a dedicated
  `POST /settings/logo` action (file input, standard Laravel validation `image|max:2048`, stored under
  `storage/app/public/tenant-logos`, old file deleted on replace) - kept separate from the main
  `update()` so a logo upload doesn't require re-submitting the whole settings form.
- `Settings/Edit.vue` restructured from one flat form into sectioned cards (not a hard app-wide lock like
  legacy's `EnsureInvoiceSettingConfigured` middleware - this app's existing `CompanySetting::current()`
  `firstOrCreate` pattern already means there's always a usable default row, so no first-run gate is
  needed): **Company Info** (existing fields + logo upload with preview), **Invoicing** (per-type
  enable/prefix pairs, default VAT rate, print paper size), **Stock & Discount Policy** (negative-stock
  toggle, default store select).
- Sale/Purchase Create forms and POS: read `default_vat_rate` from a shared prop (already-present
  `CompanySetting::current()` call site in the controller) instead of each Vue file's own hardcoded
  `'13'`.
- Tests: `SettingsControllerTest` extended for new fields + logo upload/replace/validation.

## Phase B - Invoice numbering & print polish

- `SaleController::print()` / `PurchaseController::print()`: replace the hardcoded `match` prefix logic
  with reads from `CompanySetting::current()`'s new prefix columns.
- `pdf/layout.blade.php`: render the logo (`<img>`) in the header when `logo_path` is set; graceful
  no-logo layout unchanged otherwise.
- Remove the hardcoded "10% digital payment VAT rebate" block in `pdf/sale.blade.php` (its own code
  comment already notes legacy never actually wired this up either) - dead, unbacked-by-any-toggle
  behavior shouldn't ship on a real printed legal document. If the user actually wants this feature, it
  needs its own settings toggle + real backend calculation, not a hardcoded print-time assumption; noting
  it here rather than silently deciding either way.
- New thermal receipt option: extend `print_paper_size` to accept `58mm`/`80mm` alongside `a4`/`a5`, add
  a lightweight `pdf/sale-receipt.blade.php` variant (narrow single-column, no letterhead) used when the
  tenant's paper size is thermal.
- Wire a real "Print" action from POS's complete-sale flow: after `Sale::post()` succeeds, open
  `sale.print` in a new tab (respecting the configured paper size) instead of only showing the existing
  frontend-only receipt-snapshot modal. Keep the modal as an on-screen confirmation, add print as an
  additional action, not a replacement - some cashiers won't want a physical printer to fire on every
  sale.
- Tests: PDF-rendering smoke tests already established in this app's pattern (check existing
  `SaleController`/`PurchaseController` print tests for the convention to follow) extended for prefix
  read from settings + logo presence.

## Phase C - Sale & Purchase form UX (the core usability complaint)

- **Discount**: add a percent/flat toggle (matching POS's existing `Pos.vue` toggle component) to the
  plain `Sales/Create.vue` and `Purchases/Create.vue` forms, at both header and per-line level. Backend
  (`Sale::post()`/`Purchase::post()`) computes the actual Rs discount from `discount_type` +
  `discount_value` server-side (validated `discount_type in:percentage,flat`, `discount_value` numeric
  ≥ 0, percentage ≤ 100) rather than trusting a client-resolved flat number - closes the correctness gap
  where POS's percent math currently happens only in JS.
- **Negative stock**: `Sale::post()` gains a stock-availability check per line (`Item::currentStock()` at
  the sale's store) before posting, skipped only when `CompanySetting::current()->allow_negative_stock`
  is true. Same check applied uniformly whether the sale comes from POS or the plain Create form (both
  already funnel through the same `Sale::post()`). On rejection, a clean validation error naming the
  short-stock item(s) - not a 500, not a silent oversell. POS's existing frontend warning stays as an
  earlier, softer signal; the backend check is the real enforcement.
- **Chalani number**: add an optional text input to both `Sales/Create.vue` and `Purchases/Create.vue`
  (and POS, since POS already collapses into the same `Sale::post()` contract) - symmetric across sale
  and purchase, unlike legacy.
- **Barcode**: add a `barcode` field to the Item create/edit form; wire POS's existing scan-to-add input
  and the plain forms' item `Combobox` search to match against `barcode` first, falling back to
  name/SKU search - makes the POS scan behavior (which already exists in the UI) actually functional
  against real data instead of simulating a feature the schema never supported.
- **Save & print**: add a "Save & Print" button alongside the existing "Save" on both plain Create forms
  - submits the form, then on success opens the new sale/purchase's print route in a new tab rather than
  only redirecting to the index list.
- **Inline "add new customer/supplier"**: port POS's existing inline-modal pattern
  (`openCustomerModal()`/`submitCustomer()` in `Pos.vue`) to the plain Sales/Purchases Create forms -
  today only POS has this, the ledger-style forms force a full navigation away to `/customers` or
  `/suppliers` and back.
- Tests: extend `SaleControllerTest`/`PurchaseControllerTest` (or wherever `Sale::post()`/`Purchase::
  post()` are covered today) for percentage-discount computation, negative-stock rejection and the
  allow-toggle bypassing it, chalani number persistence, barcode-based item lookup.

## Phase D - Fiscal year reopen & closed-period correction

- New `App\Support\ClosedFiscalYearGuard`-equivalent check: when posting a Purchase, Journal Voucher, or
  Stock Adjustment against a fiscal year that is `Closed`, allow it only if that year has `reopened_at`
  set and `relocked_at` still null, and require the request to carry a mandatory `reason` string -
  logged via this app's existing tenant `ActivityLogController`/activity-log mechanism (reuse, don't
  build a second logging path). **Sales is never allowed into a closed year, reopened or not** - no
  guard branch for it at all, matching the locked decision.
- `FiscalYearController::reopen()` (`POST /fiscal-years/{fiscalYear}/reopen`, `role:admin`, validates:
  target year is `Closed`, not archived, `reason` required) sets `reopened_by`/`reopened_at`/
  `reopen_reason`. `FiscalYearController::relock()` (`POST /fiscal-years/{fiscalYear}/lock`) sets
  `relocked_at`, only valid while `reopened_at` is set and `relocked_at` is null.
- **Open design question to resolve at build time, not pre-decided here**: today, new Purchase/Journal
  Voucher/Stock Adjustment records are created against `FiscalYear::current()` (the single open year) -
  there's no existing UI path to target a *different*, specific fiscal year. A correction posted during
  a reopened-year window needs to explicitly target that older year. Two viable approaches: (a) when a
  reopened-and-not-yet-relocked year exists, the Purchase/Journal Voucher/Stock Adjustment create forms
  gain a fiscal-year picker (defaulting to the current open year, only showing the reopened year as an
  alternate option) plus the mandatory reason field; (b) a dedicated "post correction" flow separate from
  the normal create forms. **(a) is recommended** - reuses the existing forms and `::post()` methods with
  one added parameter, rather than a parallel code path to maintain - but confirm with the user once
  Phase C is done and this phase starts, since it touches the same forms Phase C is also modifying.
- `FiscalYears/Index.vue`: surface reopen/relock actions (owner-gated in the UI, real enforcement
  server-side same as everywhere else in this app), a visible "reopened for correction - {reason}" badge
  on the affected year, and the closed-year detail/archive view gets a small activity-log excerpt of any
  corrections posted during a reopen window.
- Tests: `FiscalYearReopenTest.php` - reopen sets fields + logs, non-admin blocked, Sales still rejected
  into a reopened year, Purchase/Journal/Adjustment accepted with reason during the window and rejected
  without one, relock clears the window and re-blocks corrections, archived years can't be reopened.

## Suggested build order

A → B → C → D. A is a hard prerequisite for B and C (both read the new settings fields). C and D both
touch Sale/Purchase-adjacent code but don't conflict directly (C is entry-form UX, D is posting-eligibility
policy) - could run in parallel via subagents once A is committed, but D's open design question (fiscal-
year picker on the same forms C is rebuilding) means D should land *after* C's form changes are settled,
not concurrently, to avoid rebasing one on top of the other mid-flight.
