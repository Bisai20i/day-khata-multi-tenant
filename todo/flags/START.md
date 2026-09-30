# START HERE: fill the open flag gaps (flags/*.md)

Built 2026-09-28 from `flags/README.md`, `flags/sales.md`, `flags/purchases.md`, `flags/journal-entries.md`,
`flags/capital-and-services.md`. Every open flag was re-checked against the `development` head (976f7c5), after
the permissions, fiscal-year and sales/purchase UI commits. Line numbers below are from that head.

Commands work like `todo/START.md`: `start` runs the next chunk that is not done, `start F03` runs one chunk,
`status` reports the board. Locked preferences, sub-agent rules, migration and money rules are inherited from
`todo/START.md`, `todo/CONTRACTS.md` and `.ai/rules/general.md` (agents never run tests, migrations or builds,
never commit; one chunk of 2-3 checkboxes per agent; same-file chunks run serially; no em dashes).

## 1. Re-triage (one rubric)

P0: the books are wrong, or a legacy feature is missing. P1: the books or a report can be made wrong, or a
control is missing. P2: hardening, UX, performance. Flags about the same defect in two modules are merged.

| Id | Flags merged | Was | Now | Verified on head | Chunk |
|---|---|---|---|---|---|
| G-01 | CS-08 (view/print capital or service bill) | P0 | P0 | open: no show/print route, no `pdf/capital-purchase` view | F04 |
| G-02 | PUR-11, CS-10 (list totals and export include cancelled bills) | P2 | P1 | open: `PurchaseController::filteredTotals` (:320) and `CapitalPurchaseController` (:41-45) have no status filter | F01 |
| G-03 | CS-06 (capital VAT rate typed by the client, 0-100) | P2 | P1 | open: `CapitalPurchaseController.php:72` | F03 |
| G-04 | CS-04 (cash/bank capital bill with a supplier skips the supplier ledger) | P2 | P1 | open: `CapitalPurchase.php:405-410`; regular purchases post the pair (`Purchase::settlementVoucherLines`) | F03 |
| G-05 | SAL-05 (stock checked against today, not the sale date) | P2 | P1 | open: `Sale.php:616` calls `currentStock($storeId)` with no `$asOf` | F06 |
| G-06 | SAL-08, JE-05 (cancel fails after the open year's end date) | P2 | P1 | open: `JournalVoucher::reverse()` dates the reversal `static::today()` (:448) | F07 (needs decision D1) |
| G-07 | SAL-11 (receipts cannot be printed, no C9 stamp) | P2 | P1 | open: `routes/tenant-receipts.php` has no print route; `pdf/sale-receipt` is the thermal sale layout, not a receipt | F05 |
| G-08 | JE-10 (trial balance silently drops unclassified accounts) | P2 | P1 | open: `Reports/AccountingReportController.php:1150, 1201, 1246` `continue` with no warning | F08 |
| G-09 | JE-08 (ledger, print, export open to all staff) | P2 | P1 | partly fixed: JV index now paginates; `accounts/{account}/ledger*` still ungated (`routes/tenant-ledger.php:55-57`) | moved to permissions P06 (decision D2) |
| G-10 | Follow-up: unlinked purchase return form | open item | P1 | open: `Purchases/Returns/Create.vue:563` says reason "(optional)" but the request requires it; `paymentModeOptions` (:300) has no `credit` | F02 |
| G-11 | PUR-10, CS-09 (cancel reason, date, user never shown on list, print, export) | P2 | P2 | open: no `canceller`/`cancel_reason` in either controller, page, PDF or export (the Cancelled Documents report does show them) | F01, F04 |
| G-12 | PUR-12, CS-11 (Cancel button shown to non-admins, 403 not shown) | P2 | P2 | open: `Purchases/Index.vue:359` no gate, `PurchaseController` passes no `canCancel`; `CapitalPurchases/Index.vue:239` ignores `canCancel` | F01 |
| G-13 | SAL-09, PUR-04 (inactive items and stores accepted) | P2 | P2 | open: `SaleController.php:411,423`, `PurchaseController.php:128,156`, `PurchaseReturnController.php:107` (unlinked request already fixed) | F06 |
| G-14 | SAL-10, PUR-08 (allocation dated before the bill; stray bank id on cash payment) | P2 | P2 | open: `Receipt::prepareAllocations`, `Payment.php:152` | F05 |
| G-15 | SAL-06 (commission stored with no agent) | P2 | P2 | open: `Sale::validatedCommission` (:635) ignores `agent_id` | F06 |
| G-16 | PUR-06 (expected_total optional on purchases; `empty()` on unlinked return) | P2 | P2 | open: `PurchaseController.php:151` nullable; `PurchaseReturn.php:485` uses `empty()` | F02 |
| G-17 | PUR-07 (payments index unbounded and N+1) | P2 | P2 | open: `PaymentController.php:28, 94-108` | F09 |
| G-18 | PUR-05 (no stored purchase/payment number) | P2 | P2 | open: print derives `{prefix}-{voucher_number}`, falls back to `{prefix}-{id}` (`PurchaseController.php:267-268`) | F09 |
| G-19 | JE-06 (reverse() accepts Reversal, ClosingEntry, RollForwardAdjustment) | P2 | P2 | open | F07 |
| G-20 | JE-07 (no max amount, no narration cap in the model, manual journal lacks `distinct`) | P2 | P2 | open: `JournalVoucherController.php:153` no `distinct` (cash/bank at :205 has it) | F07 |
| G-21 | JE-09 (ledger/report inputs not validated) | P2 | P2 | open: `AccountController.php:175, 179, 229` | F08 |
| G-22 | New: nine models rewrite line narration with a query-builder `lines()->update()` after posting | new | P2 | Sale:375, Receipt:189, SalesReturn:382, Purchase:502, PurchaseReturn:350/374/584, Payment:171, CapitalPurchase:475, CapitalPurchaseSettlement:136. Works only because bulk updates skip the JE-01 model guard | F10 |
| G-23 | Follow-up: `CapitalPurchaseSettlement` not on `ActivityLogObserver` | open item | P2 | open: `AppServiceProvider.php:47-58` | F03 |
| G-24 | Follow-up: SAL-04 decision not in CONTRACTS C5 | open item | doc | open: C5 lists only cancel routes (`todo/CONTRACTS.md:171`) | F00 |
| G-25 | Follow-up: protected system account list inferred, not specified | open item | decision | `Account::SYSTEM_CODES` | D3 |
| - | SAL-07 (commission not reversed by returns) | P2 | deferred | already listed in `todo/DEFERRED.md` (commission policy) | none |
| - | SAL-12, PUR-09, CS-07, JE-11 (test gaps) | P2 | folded | each chunk below writes the tests for its own flags | all |

## 2. Decisions needed before the marked chunks

**Answered by the user 2026-09-29, each as recommended:** D1 clamp the reversal date to
`min(today, fiscal_year.end_date)`; D2 no interim admin gate, ledger keys go to permissions P01/P06; D3 accept
the list and add any code the engine looks up by code or name; D4 yes, new cash/bank capital bills with a
supplier post the pair, old bills stay as posted. Copied into CONTRACTS C4/C5 by F00.

**Further answers 2026-09-29:** F09 freezes today's printed format (`{purchase_prefix}-{voucher_number}`, and the
same shape for payments), so old bills keep the number they printed with. F07 `reverse()` refuses Reversal,
ClosingEntry and RollForwardAdjustment unless internal year-close or reopen code passes a flag. F06 checks the
whole run: a backdated sale is refused if stock goes negative on its date or any later day (respecting
`allow_negative_stock`).

**Working rules for this plan (user, 2026-09-29), overriding the inherited ones:** the coordinating cloud session
runs Pint, targeted Pest tests and `npm run build` itself before each commit, clicks through changed pages in a
browser, and pushes to `development` after each chunk. The 5 tests already failing on `development`
(AccountingReportTest x2, DamageLostStockReportTest x3) get fixed first. Migrations on real databases stay with
the user.

- **D1 (G-06, blocks F07 task 1).** When today is past the open year's `end_date` and the year is not yet closed,
  what date does a reversal take? Recommended: clamp to `min(today, fiscal_year.end_date)`, and say so in the
  success message. The alternative (refuse with a clear "close or roll over the year first" message) keeps
  C4/C5's "reversal dated on the cancel day" wording but blocks cancels during the rollover gap.
- **D2 (G-09).** Recommended: do not add a stop-gap `role:admin` to the ledger routes (counter staff use party
  ledgers). Add ledger view/print/export keys to `todo/permissions/P01` ROUTE-MAP and let P06 gate them, with a
  "party ledgers only" key for staff. Confirm, or ask for an interim admin gate now.
- **D3 (G-25).** Confirm the protected list in `Account::SYSTEM_CODES` (AS1, AS11, AS31, ASA23, CA2, EXE8,
  EXE20-22, INI20, INI30, LIA20, LIA21). Add any code the engine looks up by `code` or `name` that is missing
  (grep `Account::where('code'` and `where('name'`).
- **D4 (G-04).** Confirm legacy posted the supplier purchase/payment pair for cash/bank capital bills with a
  supplier (flag says legacy "net to zero on Sid"). If yes, old cash/bank capital bills stay as posted; only new
  bills get the pair (no back-posting).

## 3. Gate 0 (user, before any chunk)

The 2026-09-21 fixes were never run. Run, and report failures before starting:

```
php artisan tenants:migrate
php artisan test --compact tests/Feature/Tenant/Accounting/LedgerIntegrityTest.php
php artisan test --compact tests/Feature/Tenant/Sales/SalesP1FixesTest.php
php artisan test --compact tests/Feature/Tenant/Purchases/PurchaseFlagFixesTest.php
php artisan test --compact tests/Feature/Tenant/Purchases/CapitalPurchaseSettlementTest.php
php artisan test --compact
```

Also confirm the `purchases.fiscal_year_id` backfill filled every existing row (no nulls) on a real tenant.

## 4. Chunks

Order: F00, then P1 chunks F01-F08, then P2 chunks F09-F10. F01 and F04 both touch the capital purchase
controller and page, and F01, F02, F09 all touch purchase files, so run those serially. F05, F06, F07, F08 own
disjoint files and can run in parallel after F01-F04. Migration prefixes: `2026_09_29_1N` for chunk FN.

Run this plan **before** the permissions plan (`todo/permissions/`): its P05-P06 rewrite the same route files
and swap `role:admin` for `can:` keys. New gates added here use `role:admin` and a `canCancel`-style prop,
matching the existing code, so P06/P09 can convert them in one pass. List every new gate in the chunk report.

### F00 Record decisions (coordinator only, no agent)
- [x] 1. Add the SAL-04 rule to CONTRACTS C5: unlinked sales returns, approve and reject are `role:admin`; the
  requester cannot approve their own request; direct linked store and request stay open (G-24).
- [x] 2. Record D1-D4 answers in CONTRACTS (C4 for D1) and add G-09 to `todo/permissions/P01` if D2 is accepted.

### F01 Purchase and capital purchase lists: totals, cancel info, cancel button (G-02, G-11 list part, G-12)
Owned: `PurchaseController.php`, `CapitalPurchaseController.php` (index, export only), `Purchases/Index.vue`,
`Purchases/CapitalPurchases/Index.vue`, `app/Exports/PurchaseListExport.php`, `CapitalPurchaseListExport.php`, tests.
- [x] 1. Totals tiles and export total sum only rows where `status != cancelled`, both modules (mirror
  `SaleController.php:154`). Cancelled rows stay listed. Tests: a cancelled bill does not move totals.
- [x] 2. Index eager-loads `canceller:id,name`; list shows reason, cancel date and user under the Cancelled
  badge (tooltip or row detail); exports add Cancelled on, Cancelled by, Reason columns.
- [x] 3. `PurchaseController::index` passes `canCancel` like `CapitalPurchaseController.php:48`; both pages hide
  the purchase Cancel button unless `canCancel`. Test: prop is false for a non-admin; route still 403s.

### F02 Unlinked purchase return form and expected_total (G-10, G-16)
Owned: `Purchases/Returns/Create.vue` (705 lines, over the soft cap: extract the unlinked form into
`components/purchases/PurchaseReturnUnlinkedFields.vue` or a composable, as sales returns did), `PurchaseReturn.php`
(`postUnlinked` only), `PurchaseController.php` (rules only, after F01), tests.
- [x] 1. Unlinked form: reason required (label and placeholder), `credit` payment mode "Credit to supplier" shown
  only when a supplier is chosen, bank field hidden for credit. JS test for the options helper.
- [x] 2. `expected_total` required on `PurchaseController::store` rules; `postUnlinked` checks
  `$data['expected_total'] !== null` instead of `empty()`. Update any existing test that posts without it.
  Done with a user decision (2026-09-29): the unlinked form gets its total from a new read-only
  `GET purchase-returns/unlinked/quote` (same pricing code as the post, admin only), because an average-cost
  line is priced at 12 decimals the browser cannot reproduce.

### F03 Capital purchase posting: VAT rate, supplier pair, observer (G-03, G-04, G-23)
Owned: `CapitalPurchase.php` (`post()` only), `CapitalPurchaseController.php` (store rules, after F01),
capital purchase create page, `AppServiceProvider.php` (one line), tests. Needs D4 for task 2.
- [x] 1. `vat_rate` must be the company default rate or 0 (reject anything else in the rule and again in
  `post()`); the form offers only those two. Tests for 13, 0 and 100.
- [x] 2. Cash or bank mode with a supplier posts Cr supplier total, Dr supplier total, Cr cash/bank (the partial
  shape). No supplier: unchanged. Supplier ledger test shows both lines; voucher still balances.
- [x] 3. Register `CapitalPurchaseSettlement::observe(ActivityLogObserver::class)`; test an activity row.

### F04 View and print a capital or service bill (G-01, G-11 print part)
Owned: new route in `routes/tenant-purchase.php` (coordinator adds), `CapitalPurchaseController::print`, new
`resources/views/pdf/capital-purchase.blade.php`, `CapitalPurchases/Index.vue` (Print action, after F01), tests.
- [x] 1. `GET capital-purchases/{capitalPurchase}/print`, via `PrintLog::record()` (C9: copy number, BS date,
  fiscal year), layout from `pdf/capital-sale.blade.php`: supplier, bill number, type, lines with account,
  narration and amount, vatable split, VAT, total, payment mode, settlements and outstanding. Cancelled bills
  print with the Cancelled marker plus reason, date and user.
- [x] 2. Print action on every row (live and cancelled), and a link from the row to its purchase voucher print.
  Tests: prints for live and cancelled, logs a copy, shows settlements.

### F05 Receipts: print, allocation dates; payments: allocation dates, stray bank id (G-07, G-14)
Owned: `routes/tenant-receipts.php` (coordinator adds), `ReceiptController.php`, `Receipt.php`,
`Payment.php`, new `resources/views/pdf/receipt.blade.php`, receipts page, tests.
- [x] 1. `GET receipts/{receipt}/print` with `PrintLog::record()`: receipt number, customer, mode, bank,
  allocations per invoice, amount in words, Cancelled marker. Print action on the receipts page. Tests.
- [x] 2. Reject an allocation when the receipt date is before the sale date, and a payment allocation dated
  before the bill date (field error on the allocation row). Tests for both.
- [x] 3. `Payment::post` stores `bank_account_id = null` unless the mode is bank or partial. Test.

### F06 Sales posting guards (G-05, G-13, G-15)
Owned: `Sale.php` (stock check and `validatedCommission` only), `SaleController.php` (rules only),
`PurchaseController.php` and `PurchaseReturnController.php` (rules only, after F02), tests.
- [x] 1. Stock check uses stock as of the sale date AND the lowest running balance from that date to today, so
  a backdated sale cannot make any later day negative (respect `allow_negative_stock`). Add an `Item` helper if
  needed (owned for this chunk). Tests: backdated sale before a purchase is refused; today's sale unchanged.
- [x] 2. `Rule::exists(...)->where('is_active', true)` for item and store ids on sales, purchases and linked
  purchase returns. Tests for an inactive item and an inactive store.
- [x] 3. Commission above 0 with no `agent_id` is a validation error. Test.

### F07 Journal core hardening (G-06, G-19, G-20). Task 1 needs D1.
Owned: `JournalVoucher.php`, `JournalVoucherController.php` (rules only), tests. Serial with nothing else.
- [x] 1. `reverse()` dates the reversal per D1. The three document `cancel()` flows then need no change; test a
  sale cancel with the clock past `end_date` of a still-open year.
- [x] 2. `reverse()` refuses Reversal, ClosingEntry and RollForwardAdjustment targets unless an internal flag
  is passed (check the callers that legitimately reverse these, if any). Tests.
- [x] 3. `validateLines()` caps narration at 255 and amounts at the `decimal(20,2)` limit; manual journal rule
  adds `distinct` on `lines.*.account_id` and a max on debit/credit. Tests via `JournalVoucher::post()` directly.

### F08 Reports: trial balance integrity and input validation (G-08, G-21)
Owned: `app/Http/Controllers/Tenant/Reports/AccountingReportController.php`, `AccountController.php` (ledger
actions only), trial balance page, tests.
- [x] 1. Accounts with no resolvable head or group go into an "Unclassified" row instead of `continue`, in all
  three places (:1150, :1201, :1246); the page shows a warning when that row is non-empty; trial balance asserts
  closing debit total equals closing credit total like the balance sheet. Test with an orphaned account.
- [x] 2. Validate `fiscal_year_id` (`exists`), `from` and `to` (`date`, `to >= from`) on every ledger and report
  action; a bad year is a validation error, not a silent fallback. Tests.

### F09 Purchase numbers and payment list performance (G-17, G-18)
Owned: `Purchase.php`, `Payment.php` (post only, after F05), `PaymentController.php`, `PurchaseController.php`
(print only, after F02), new migration, payments page, tests.
- [x] 1. Add `purchases.purchase_number` and `payments.payment_number` (plus `payments.fiscal_year_id`), filled
  in `post()`, backfilled from the voucher (idempotent), unique per fiscal year. Print, list and export read the
  stored number; no `{prefix}-{id}` fallback.
- [x] 2. Paginate the payments index; compute outstanding purchases only for the chosen supplier (lazy prop or
  a supplier filter) with one grouped query instead of `outstandingAmount()` per row. Test the query count.

### F10 Narration without bulk line updates (G-22)
Owned: `JournalVoucher.php` (after F07) and the nine callers listed in G-22, tests. Largest blast radius, last.
- [ ] 1. Let `JournalVoucher::post()` accept a narration resolver called with the new voucher number inside
  `write()`, so lines are created with their final narration. Replace every `$voucher->lines()->update([...])`.
- [ ] 2. Add a test that no code path updates a `journal_voucher_lines` row after insert (for example a DB
  listener that fails the test on `update journal_voucher_lines`) across a sale, receipt, purchase, payment and
  capital purchase post.

## 5. Gates (user runs, coordinator waits)

- After F00-F04: `npm run build`, `php artisan test --compact tests/Feature/Tenant/Purchases`, then click through
  purchase list, capital list, capital print, unlinked purchase return.
- After F05-F08: `php artisan test --compact tests/Feature/Tenant/Sales tests/Feature/Tenant/Accounting
  tests/Feature/Tenant/Reports`, `npm run build`.
- After F09-F10: `php artisan tenants:migrate`, full `php artisan test --compact`, check payment numbers backfilled.

## 6. Status board

| Chunk | Title | Flags | Status |
|---|---|---|---|
| Gate 0 | Run 2026-09-21 fixes | all FIXED | pending |
| F00 | Record decisions | G-24, D1-D4 | done |
| F01 | Purchase lists | G-02, G-11, G-12 | done |
| F02 | Unlinked purchase return form | G-10, G-16 | done |
| F03 | Capital posting | G-03, G-04, G-23 | done |
| F04 | Capital view and print | G-01, G-11 | done |
| F05 | Receipt print, allocation dates | G-07, G-14 | done |
| F06 | Sales posting guards | G-05, G-13, G-15 | done |
| F07 | Journal core hardening | G-06, G-19, G-20 | done |
| F08 | Trial balance and inputs | G-08, G-21 | done |
| F09 | Stored numbers, payment list | G-17, G-18 | done |
| F10 | Narration without bulk updates | G-22 | pending |

When a chunk lands, also mark its flags FIXED in the matching `flags/*.md` file and update `flags/README.md`.
