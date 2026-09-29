# Capital and service purchases audit

Scope: CapitalPurchase (type capital or service), controller, routes, posting, cancel, settlement, fixed asset link. Read only audit, nothing was run.
Files: app/Models/CapitalPurchase.php, app/Http/Controllers/Tenant/Purchases/CapitalPurchaseController.php, routes/tenant-purchase.php, tests/Feature/Tenant/Purchases/CapitalPurchaseTest.php. Legacy: day_khata commit 43995c6.

## CS-01 (P0) FIXED: No later settlement of a credit or partial capital/service bill (legacy parity gap)
- FIXED: new CapitalPurchaseSettlement model, migration 2026_09_21_070100, CapitalPurchaseSettlementController, two Form Requests, routes settlements.store and admin-only settlements.cancel, outstanding balance (CapitalPurchase::outstandingAmount) plus Settle UI in CapitalPurchases/Index.vue; cancelling a bill is blocked while live settlements exist. PaymentAllocation left untouched. Tests: tests/Feature/Tenant/Purchases/CapitalPurchaseSettlementTest.php. Not done: ActivityLogObserver registration for the new model (AppServiceProvider is not in scope).
- Where: whole module. Routes at routes/tenant-purchase.php:35-41 offer only index, store, export, cancel. No settlement model, migration, controller or route exists (grep for CapitalServiceSettlement and capital_service finds nothing in app, database, routes, resources/js, tests).
- Evidence: legacy 43995c6 added settleCapitalServicePurchase(), cancelCapitalServiceSettlement(), listCapitalServiceSettlements(), CapitalServiceOutstanding and a capital_service_settlements table. Here PaymentAllocation (app/Models/PaymentAllocation.php:10,34) only relates to Purchase (purchase_id), so a generic Payment cannot be allocated to a CapitalPurchase, and nothing shows a per-bill outstanding balance.
- Impact: a credit capital purchase credits the supplier ledger for the full total and can never be tied to its payment. The bill shows no paid or outstanding amount.
- Fix: add CapitalPurchaseSettlement (posted JV via JournalVoucher::post, Dr supplier account, Cr cash AS1 or bank), exact Money amount capped at outstanding (computed under lockForUpdate), closed-FY guard, admin-only cancel via JournalVoucher::reverse, and block cancelling the bill while live settlements exist (legacy did). Add tests.

## CS-02 (P1) FIXED: Cancelling a capital purchase leaves the linked FixedAsset active and depreciating
- FIXED: CapitalPurchase::cancel() now refuses when a linked asset has depreciation or is disposed, otherwise marks it cancelled (app/Models/CapitalPurchase.php); tests in CapitalPurchaseSettlementTest.php.
- Where: app/Models/CapitalPurchase.php:476-518 (cancel) versus createAssetForLine at ~line 380-425.
- Evidence: cancel() only calls JournalVoucher::reverse() and updates the purchase row. It never touches capital_purchase_lines.fixed_asset_id or FixedAsset. FixedAsset::postDepreciationForFiscalYear (FixedAsset.php:360-363) processes every status 'active' asset, so the asset from a reversed purchase keeps accruing depreciation against a cost that was reversed out, and can later be disposed with proceeds.
- Fix: in cancel(), load lines with fixed_asset_id; refuse if the asset has depreciation rows or is disposed (message: reverse those first), otherwise mark it cancelled or delete it. Add a test.

## CS-03 (P1) FIXED: Payment account and expense/asset account are not constrained
- FIXED: CapitalPurchase::assertPaymentAccount and assertLineAccount (P&L, party, supplier/customer, ASA23, AS1 and same-account rejected) called inside post() and settle(); controller now also maps AuthorizationException to a field error (CS-05); tests in CapitalPurchaseSettlementTest.php.
- Where: CapitalPurchaseController.php:63 (bank_account_id: only exists:accounts) and :70 (lines.*.account_id: only exists:accounts).
- Evidence: any account, including cash, a supplier or customer party account, ASA23 Input VAT or a fixed-asset group account for a service line, is accepted. A bank_account_id pointing at a supplier control account, or a line account equal to the credited account, posts a balanced but meaningless voucher. Legacy limited pickers to expense/asset groups.
- Fix: validate bank_account_id belongs to the bank/cash group, and line accounts exclude ASA23, party accounts and the payment account. Re-check in CapitalPurchase::post since it is callable outside the controller.

## CS-04 (P2) Cash or bank mode with a supplier posts nothing to the supplier, so the supplier statement omits the bill
- FIXED (2026-09-29, F03): a cash or bank bill with a supplier posts Cr supplier, Dr supplier, Cr cash/bank (new bills only, decision D4). Tests: tests/Feature/Tenant/Purchases/CapitalPurchasePostingRulesTest.php.
- Where: CapitalPurchase.php:~250-265 (cash and bank branches).
- Evidence: for cash/bank the credit goes straight to cash/bank, the supplier account is never touched even when supplier_id is set. Balanced, but the supplier ledger has no purchase and payment pair (credit and partial do post the pair). Legacy said cash/bank "net to zero" on Sid, i.e. legacy posted the pair.
- Fix: when a supplier is set, post Cr supplier total then Dr supplier total and Cr cash/bank, matching the partial shape.

## CS-05 (P2) No permission or role gate on posting, only on cancel
- Where: routes/tenant-purchase.php:35-38. Store, export and index have no role middleware (same as Purchase, so consistent), but posting into a reopened fiscal year is admin-only inside JournalVoucher::post and surfaces as an unhandled AuthorizationException (controller catches only BillingException and InvalidArgumentException, CapitalPurchaseController.php:96-110). Result is a 403 page rather than a form error.
- Fix: catch AuthorizationException and return a field error.

## CS-06 (P2) vat_rate is client supplied per document
- FIXED (2026-09-29, F03): vat_rate must be the company rate or 0, checked in the request and in post(); the form offers only those two. Tests: tests/Feature/Tenant/Purchases/CapitalPurchasePostingRulesTest.php.
- Where: CapitalPurchaseController.php:67, CapitalPurchase.php:456.
- Evidence: any rate 0 to 100 is accepted and Input VAT posted at it, defaulting to the company rate. Contract intent (T07 task 2) is VAT computed, not typed. A user can claim VAT at 100 percent.
- Fix: restrict to the company default rate or 0 (or an allowed-rate list), and reject other values server side.

## CS-07 (P2) Missing tests
- Not covered in tests/Feature/Tenant/Purchases/CapitalPurchaseTest.php: cancelling a purchase that created a FixedAsset (CS-02), cancel in a closed fiscal year, non-admin cancel route returns 403, double cancel through the route, posting into a closed year, bank_account_id of a non-bank account, bill number re-entry after cancel (guard release), cash mode with supplier, purchase without VAT account seeded.
- No settlement tests (CS-01).

## CS-08 (P0) No view or print of a capital or service purchase, live or cancelled
- Where: routes/tenant-purchase.php:36-48 (index, store, export, cancel and settlement routes only; no show or print); resources/views/pdf has capital-sale.blade.php but no capital purchase layout; resources/js/pages/Tenant/Purchases/CapitalPurchases/Index.vue:156-157 and :185-190 (the Accounts column lists account names only).
- Evidence: line amounts, line narrations, the vatable split and VAT are sent to the page (CapitalPurchaseController.php:32 loads lines) but never displayed or printable. Legacy: day_khata resources/views/instock/listCapitalServices.blade.php:196,201 offers Print (printJournalCapitalRecord) for capital and service journals.
- Impact: a user cannot review or print what a capital or service bill contained, and after cancelling cannot see the lines that were reversed. The only workaround is finding the purchase voucher in Journal Vouchers and printing it (routes/tenant-ledger.php:51), which the capital page does not link to. No ledger effect.
- Fix: add a print route and PDF (lines, VAT, totals, payment mode, settlements) usable for cancelled bills too, with a Print action on every row.

## CS-09 (P2) Cancel reason, date and user are stored but never shown
- PARTLY FIXED (2026-09-29, F01): list and export show cancel date, user and reason. Print part is F04. Tests: tests/Feature/Tenant/Purchases/PurchaseListCancelInfoTest.php.
- Where: app/Models/CapitalPurchase.php:633-640 (writes cancelled_at, cancelled_by, cancel_reason); CapitalPurchaseController.php:32 (index loads no canceller), :144 (export has status only); CapitalPurchases/Index.vue:211-219 (Status column is a badge only).
- Evidence: grep for cancel_reason, cancelled_at and canceller in resources/js/pages/Tenant/Purchases and CapitalPurchaseListExport finds nothing.
- Impact: no one can see why, when or by whom a capital purchase was cancelled. Legacy: day_khata InventoryStockController::cancelJournalRecord now writes purchasecancelrecords so the cancelled list shows it.
- Fix: load canceller:id,name; show reason, date and user in the list, the print from CS-08 and the export.

## CS-10 (P2) Totals row and export total include cancelled bills
- FIXED (2026-09-29, F01): totals row and export total skip cancelled bills. Tests: tests/Feature/Tenant/Purchases/PurchaseListCancelInfoTest.php.
- Where: CapitalPurchaseController.php:41-45 (totals over every row), :147 (export total).
- Evidence: no status filter in either sum; sales excludes cancelled rows (SaleController.php:154).
- Impact: the capital purchase total on screen and in Excel overstates spending by every cancelled bill and disagrees with the ledger.
- Fix: sum only rows where status is not cancelled.

## CS-11 (P2) Cancel button shown to non-admins although canCancel is passed
- FIXED (2026-09-29, F01): Cancel button wrapped in canCancel. Tests: tests/Feature/Tenant/Purchases/PurchaseListCancelInfoTest.php.
- Where: CapitalPurchases/Index.vue:239-246 (Cancel offered on every posted row); canCancel prop at :30 is only used for settlement cancel (:340); CapitalPurchaseController.php:48; routes/tenant-purchase.php:40-42 (role:admin).
- Evidence: a non-admin can open the dialog and submit; EnsureUserHasRole aborts 403, which is not a validation error, so the dialog shows nothing and Inertia shows a bare error page.
- Fix: wrap the purchase Cancel button in `canCancel` like the settlement one.

## TDS
Legacy TDS handling is in saveInStock (regular purchase), not in capital or service purchase, so there is no parity gap. Multi-tenant capital purchases carry no TDS; noted only in case the business rule changes.

## Checked and fine
- Voucher balance: line totals (gross incl. per-line amount) plus Input VAT debit equal the credit total in every mode; partial mode adds supplier Cr total, settlement Cr cash/bank, supplier Dr total, net equal.
- Exact partial split via DocumentCalculator::assertExactSplit; VAT, taxable and nontaxable via DocumentCalculator with expected_total check (C8).
- Money uses Decimal casts and Money value objects, no floats.
- Cancel: row lock, status re-check inside transaction, JournalVoucher::reverse in Reversal series, closed FY refused, bill guard released, admin-only route.
- Duplicate bill guard (unique guard column plus locked lookup), cleared on cancel.
- No stock effect: no inventory posting, store defaults to the active store only as a label.
- Fixed asset creation reuses the purchase voucher (no double booking), enforces Fixed Assets group, salvage not above cost, capital type only.
- Tenancy: routes are in the tenant auth group; models resolve through the tenant connection; ActivityLogObserver attached (AppServiceProvider.php:57-58).
- Purchase VAT book consumption fields (bill_number, supplier_pan snapshot) present.

Checked 2026-09-28 against the legacy cancelled-purchase fixes (day_khata InventoryStockController::cancelJournalRecord, listCapitalServices.blade.php, uncommitted):
- Cancel records a reason: required in the controller (CapitalPurchaseController.php:154-156) and re-checked in the model (CapitalPurchase.php:596-604), stored with the cancel date and user (:636-638).
- Cancel is one transaction: row lock, settlement and fixed asset checks, JournalVoucher::reverse, asset status and header update all run inside DB::transaction (CapitalPurchase.php:588-643).
- Cancel lines stay intact (the model never touches capital_purchase_lines on cancel), and cancelled bills stay in the list with a badge. The gap is only that nothing displays them (CS-08).
- No edit path (routes/tenant-purchase.php:36-48, no update method on CapitalPurchase), so no superseded lines.
- Cancel trusts only the route-bound record; the request validates only `reason`.
- Cancel dialog requires a reason and shows server errors under the field (CapitalPurchases/Index.vue:386-405, controller :160-161).
- Closed years: JournalVoucher::reverse refuses any year that is not open, even one reopened for correction (JournalVoucher.php:432-436), and the message reaches the dialog. The legacy missing-reason failure cannot happen. This differs from legacy on purpose (legacy let a Super Admin cancel with a reason); confirm that is intended.
