# Purchase flow audit

Scope: purchases, purchase returns (linked and unlinked), payments, ledger posting, input VAT, stock effects.
Read only audit, nothing was run. Files: app/Models/Purchase.php, PurchaseReturn.php, Payment.php, the three
Purchases controllers, routes/tenant-purchase*.php, routes/tenant-payments.php, tests/Feature/Tenant/Purchases.
Legacy note: the legacy repo's PurchaseRecord/PurchaseReturn controllers are empty scaffolds (the real logic is spread
across StockOutController, InventoryStockController, reportsController), so parity was judged against CONTRACTS.md
C3, C4, C5, C6, C10 rather than line by line.

## Flags

### PUR-01 (P1) Supplier bill number is unique across all fiscal years
- **FIXED** (2026-09-21): new purchases.fiscal_year_id (backfilled from voucher) and unique key (supplier_id, fiscal_year_id, bill_number_key); rule and Purchase::assertBillNumberUnused scoped by year. Files: database/migrations/tenant/2026_09_21_100000_scope_purchase_bill_number_unique_to_fiscal_year.php, app/Models/Purchase.php, PurchaseController.php. Tests: PurchaseFlagFixesTest.php.
- File: database/migrations/tenant/2026_09_12_060001_add_bill_number_key_to_purchases_table.php:34,
  app/Http/Controllers/Tenant/Purchases/PurchaseController.php:108-111, app/Models/Purchase.php:627-643
- Wrong: unique index is (supplier_id, bill_number_key) with no fiscal year. Nepali suppliers restart bill numbers each
  fiscal year, so "001" from a supplier in the new year is rejected because the same supplier's "001" exists in the
  previous year.
- Evidence: index columns above; assertBillNumberUnused() filters only on supplier_id and bill_number_key; purchases has
  no fiscal_year_id column (not in Purchase fillable).
- Fix: add purchases.fiscal_year_id (backfill from voucher) and make the key (supplier_id, fiscal_year_id,
  bill_number_key), mirror it in the validation rule and assertBillNumberUnused.

### PUR-02 (P1) Account pickers are validated only with exists:accounts
- **FIXED** (2026-09-21): new AccountUnderHead rule class (Assets for bank/refund, Liabilities for TDS, rejects customer/supplier ledger accounts) applied to purchase, purchase return, unlinked return and payment requests. Files: app/Rules/AccountUnderHead.php, PurchaseController.php, PurchaseReturnController.php, PaymentController.php. Tests: PurchaseFlagFixesTest.php.
- Files: PurchaseController.php:116,131 (bank_account_id, tds_account_id); PurchaseReturnController.php store
  refund_account_id and storeUnlinked bank_account_id; PaymentController.php:48 bank_account_id.
- Wrong: the UI narrows to Assets (bank) and Liabilities (TDS), but the server accepts any account id. A crafted request
  can credit "bank" money out of, or debit a refund into, a revenue/expense/supplier account, or withhold TDS into an
  expense account. Vouchers still balance so nothing catches it. A refund account that is a party ledger also breaks the
  outstanding math.
- Evidence: rules are ['nullable','exists:accounts,id']; Purchase::settlementVoucherLines (Purchase.php:861-863),
  Payment::settlementAccountId (Payment.php:227) and the refund voucher in PurchaseReturn::post use the id as given.
- Fix: a shared rule/helper requiring the account to sit under the Assets head (bank/refund) or Liabilities head (TDS)
  and not be a party ledger account. Add tests.

### PUR-03 (P1) Unlinked purchase return has no authorization or value bound
- **FIXED** (2026-09-21): route now role:admin; new StoreUnlinkedPurchaseReturnRequest requires reason and expected_total, caps an entered rate at average cost per unit, and adds payment_mode "credit" (debits the supplier account, needs a supplier) handled in PurchaseReturn::postUnlinked. Files: routes/tenant-purchase-returns.php, app/Http/Requests/Tenant/Purchases/StoreUnlinkedPurchaseReturnRequest.php, app/Models/PurchaseReturn.php, PurchaseReturnController.php. Vue return form not yet updated (reason required, credit option). Tests: PurchaseFlagFixesTest.php.
- Files: routes/tenant-purchase-returns.php:24 (no role middleware), app/Models/PurchaseReturn.php postUnlinked.
- Wrong: any authenticated tenant user can post a return with no source purchase, an arbitrary `rate`, and get cash or
  bank debited (refund received) with purchases and input VAT credited, plus stock removed. No cap against what was ever
  bought, reason is optional, and there is no admin gate (unlike cancel). With a supplier chosen the supplier ledger is
  not touched at all, so a return of a credit purchase becomes a phantom cash receipt.
- Evidence: storeUnlinked rules ('reason' nullable, 'rate' nullable min:0); postUnlinked never references the supplier
  account; only ClosedFiscalYearGuard::assertDateInOpenYear is applied.
- Fix: restrict to role:admin (or a dedicated permission), require a reason, bound the rate (for example to average
  cost) or require justification, and when a supplier is given offer a credit-to-supplier mode (debit the supplier
  account) instead of forcing cash/bank.

### PUR-04 (P2) Inactive items and inactive stores accepted by the server
- Files: PurchaseController.php:72 vs 145 and 117; Purchase.php:294 (Item::findOrFail); PurchaseReturn::postUnlinked item
  lookup.
- Wrong: the index comment says a retired item is rejected on post, but `lines.*.item_id` is only exists:items,id and
  Purchase::post never checks is_active (is_active appears in Purchase.php only in the store fallback). Same for store_id
  (exists:stores,id, not active) on purchases, returns and unlinked returns.
- Fix: validate against active items and stores in the rule or in post().

### PUR-05 (P2) No stored, fiscal-year-qualified document number for purchases and payments
- Files: PurchaseController.php:253-255 (print derives "{prefix}-{voucher_number}" at render time, falls back to
  "{prefix}-{id}"); Payment.php:170 ("PMT-{voucher_number}" narration only, no column).
- Wrong: C7 stores numbers at posting time for sales and returns; purchases and payments recompute from the voucher.
  Voucher numbers restart per fiscal year, and there is no fiscal_year_id on the purchase to disambiguate in exports,
  the VAT book and lookups. If the voucher relation is ever null the printed number silently becomes the row id.
- Fix: add purchase_number/payment_number plus fiscal_year_id columns filled inside post(), print from them.

### PUR-06 (P2) expected_total is optional on purchases and unlinked returns
- **FIXED** (2026-09-29, F02): `expected_total` required on purchases; `postUnlinked` compares it with `!== null`. The unlinked return form gets its exact total from `GET purchase-returns/unlinked/quote`. Tests: UnlinkedPurchaseReturnQuoteTest.php.
- Files: PurchaseController.php:140, Purchase.php:338, PurchaseReturn::postUnlinked (uses empty() on expected_total).
- Wrong: the client/server total cross-check (C3 step 9, C8) is skipped when the field is omitted, and on the unlinked
  path empty() also skips the string "0". API callers bypass the guard.
- Fix: require it on the HTTP path and test with `!== null` in the unlinked return.

### PUR-07 (P2) Payments index is unbounded and N+1
- File: PaymentController.php:23-27, 93-111.
- Wrong: ->get() of every payment ever made, and outstandingPurchases() loads every posted purchase and runs
  outstandingAmount() (several queries each) per row. Slows linearly with history.
- Fix: paginate payments; compute outstanding in SQL or restrict to the chosen supplier.

### PUR-08 (P2) Payment date not compared to bill date; cash mode stores a stray bank account
- File: Payment.php:112-158, PaymentController.php:48.
- Wrong: a payment allocated to a bill can be dated before that bill; for payment_mode=cash a submitted bank_account_id
  is persisted on the row although the voucher credits cash (AS1).
- Fix: reject allocation dates earlier than the bill date; null bank_account_id unless the mode is bank.

### PUR-09 (P2) Missing tests
- No 403 test for `role:admin` on purchase-return cancel (PurchaseReturnTest has no role assertions); payment cancel
  role coverage is thin; purchase cancel is covered in PurchaseControllerTest.
- No test for: unlinked return authorization, the same supplier bill number in a new fiscal year (PUR-01), account-type
  validation (PUR-02), inactive item purchase (PUR-04), a bonus-only return (thisPaidPortion = 0 yields a zero-value
  line in PurchaseReturn::prepareLine).
- Fix: add Pest cases for each.

### PUR-10 (P2) Cancel reason, date and user are stored but never shown
- **PARTLY FIXED** (2026-09-29, F01): list shows cancel date, user and reason under the Cancelled badge (PurchaseStatusCell.vue); export has Cancelled on/by and Reason columns. PDF print still pending (F04 covers capital only). Tests: tests/Feature/Tenant/Purchases/PurchaseListCancelInfoTest.php.
- Where: app/Models/Purchase.php:572-579 (writes cancelled_at, cancelled_by, cancel_reason); PurchaseController.php:52 (index loads no canceller), :221 (export has status only); resources/js/pages/Tenant/Purchases/Index.vue:230-240 (Status column is a badge only); resources/views/pdf/purchase.blade.php:10-12 (print shows a "Cancelled" badge only).
- Evidence: grep for cancel_reason, cancelled_at and canceller in resources/js/pages/Tenant/Purchases, the purchase PDF and PurchaseListExport finds nothing. The reason survives only inside the reversal voucher narration.
- Impact: a user reviewing or printing a cancelled bill cannot see why, when or by whom it was cancelled. Legacy: day_khata InventoryStockController::getcanceledpurchaserecordforprint and resources/views/instock/listcanceledinstock.blade.php now show all three.
- Fix: load canceller:id,name in index and print; show reason, cancel date and user in the list (row detail or tooltip), under the Cancelled badge on the PDF, and as export columns.

### PUR-11 (P2) Purchase list totals and export total include cancelled bills
- **FIXED** (2026-09-28/29): filteredTotals and the export total skip cancelled bills. Tests: PurchaseListSearchSortTest.php.
- Where: PurchaseController.php:288-302 (filteredTotals), :224 (export total); query builder at :277-283 has no status filter.
- Evidence: the "Total (filtered)", taxable, non-taxable and VAT tiles on Index.vue:351-368 sum every row, cancelled ones included. Sales already excludes them (SaleController.php:154 `where('status', '!=', 'cancelled')`).
- Impact: the on-screen and exported purchase totals overstate purchases and input VAT by every cancelled bill, so they disagree with the ledger and the VAT book.
- Fix: add `where('status', '!=', 'cancelled')` to filteredTotals and sum only posted rows for the export total (keep cancelled rows listed).

### PUR-12 (P2) Cancel button shown to non-admins; the 403 is not shown in the dialog
- **FIXED** (2026-09-29, F01): index passes canCancel (admin); the page hides Cancel otherwise. Tests: tests/Feature/Tenant/Purchases/PurchaseListCancelInfoTest.php.
- Where: resources/js/pages/Tenant/Purchases/Index.vue:262-270 (Cancel offered on every posted row); routes/tenant-purchase.php:30-32 (role:admin); app/Http/Middleware/EnsureUserHasRole.php (abort(403)).
- Evidence: PurchaseController::index passes no canCancel flag. A non-admin can fill the reason dialog and submit; the 403 is not a validation error, so reasonForm.errors stays empty and Inertia shows a bare error page instead of a message in the dialog.
- Fix: pass canCancel (as CapitalPurchaseController.php:48 does) and hide the button for non-admins.

## Checked and fine
- Purchase voucher balances: expense debits (net of line and header discount, grouped by account) plus input VAT (ASA23)
  equal the supplier credit of total; TDS is a separate credit TDS-payable and debit supplier pair; settlement debits the
  supplier against cash (AS1) or bank. JournalVoucher::post enforces exact debit = credit and date-in-fiscal-year.
- Math comes from DocumentCalculator (with a re-run for TDS); header discount, VAT and TDS shares use Money::allocate so
  no paisa is lost; TDS base is taxable + nontaxable and capped by the calculator.
- Bonus quantity: stock gets paid + bonus in base units at the paid-only value, so average cost falls correctly.
- Stock: purchase, return and cancellation flag movements; Item::currentStock and StockCosting both exclude cancelled
  movements; purchase cancel refuses when stock has been sold on (unless allow_negative_stock) and locks items in
  ascending id order.
- Cancellation: purchase, return and payment cancel run in a transaction with lockForUpdate, use JournalVoucher::reverse
  (reversal series, closed-year refusal), require a reason (max 500), and cancel routes carry role:admin. Purchase cancel
  is blocked by live returns and live payment allocations; the bill number is freed via bill_number_key = null.
- Returns: only posted returns count toward outstanding; remaining-quantity cap includes non-cancelled returns; the
  last-quantity return takes the remainder so no paisa is lost (C6); return date must be >= bill date and in an open
  year; the debit note number is stored from the return voucher; the refund voucher is reversed with the return.
- Payments: allocations aggregated per bill, purchases locked in ascending id, supplier ownership, posted status and exact
  outstanding cap are checked; voucher is balanced; over-allocation beyond the payment amount is rejected.
- Tenancy: database-per-tenant, so no tenant_id scoping gaps were found in these controllers.
- Correction posting into a reopened fiscal year is admin only and logged.

Checked 2026-09-28 against the legacy cancelled-purchase fixes (day_khata InventoryStockController, StockOutController, listcanceledinstock.blade.php, uncommitted):
- Cancelled bill lines stay intact: cancel() flags only the stock movements and the header (Purchase.php:570-579), and print loads every line (PurchaseController.php:253). A cancelled bill stays in the list with its badge and Print link (Index.vue:247-260), and the PDF shows its lines, rates and totals. The legacy empty item table cannot happen.
- Reason is required (PurchaseController.php:232, Purchase.php:528-532) and stored with the cancel date and user (Purchase.php:575-577).
- No edit path: the purchase routes are index, store, export, cancel and print only (routes/tenant-purchase.php:22-34), and Purchase has no update method, so there are no superseded lines to mix with the final ones.
- Cancel trusts only the route-bound purchase; the request validates only `reason` (PurchaseController.php:229-236).
- Cancel dialog requires a reason and shows server errors under the field (Index.vue:411-431); model errors come back as a `reason` error (PurchaseController.php:237-238).
- Closed years: JournalVoucher::reverse refuses any year that is not open, including one reopened for correction (JournalVoucher.php:432-436). The message reaches the dialog. The legacy missing-reason failure cannot happen here. This differs from legacy on purpose (legacy let a Super Admin cancel with a reason); confirm that is intended.
