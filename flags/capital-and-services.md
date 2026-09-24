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
- Where: CapitalPurchase.php:~250-265 (cash and bank branches).
- Evidence: for cash/bank the credit goes straight to cash/bank, the supplier account is never touched even when supplier_id is set. Balanced, but the supplier ledger has no purchase and payment pair (credit and partial do post the pair). Legacy said cash/bank "net to zero" on Sid, i.e. legacy posted the pair.
- Fix: when a supplier is set, post Cr supplier total then Dr supplier total and Cr cash/bank, matching the partial shape.

## CS-05 (P2) No permission or role gate on posting, only on cancel
- Where: routes/tenant-purchase.php:35-38. Store, export and index have no role middleware (same as Purchase, so consistent), but posting into a reopened fiscal year is admin-only inside JournalVoucher::post and surfaces as an unhandled AuthorizationException (controller catches only BillingException and InvalidArgumentException, CapitalPurchaseController.php:96-110). Result is a 403 page rather than a form error.
- Fix: catch AuthorizationException and return a field error.

## CS-06 (P2) vat_rate is client supplied per document
- Where: CapitalPurchaseController.php:67, CapitalPurchase.php:456.
- Evidence: any rate 0 to 100 is accepted and Input VAT posted at it, defaulting to the company rate. Contract intent (T07 task 2) is VAT computed, not typed. A user can claim VAT at 100 percent.
- Fix: restrict to the company default rate or 0 (or an allowed-rate list), and reject other values server side.

## CS-07 (P2) Missing tests
- Not covered in tests/Feature/Tenant/Purchases/CapitalPurchaseTest.php: cancelling a purchase that created a FixedAsset (CS-02), cancel in a closed fiscal year, non-admin cancel route returns 403, double cancel through the route, posting into a closed year, bank_account_id of a non-bank account, bill number re-entry after cancel (guard release), cash mode with supplier, purchase without VAT account seeded.
- No settlement tests (CS-01).

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
