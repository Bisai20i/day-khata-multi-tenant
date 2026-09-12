# T06 Purchases, purchase returns, payments

Phase 2 | Parallel with T03-T05, T07-T09 | Consumes C1-C11, implements C6 (purchase side) | Audit refs:
P0-1, P0-3, P0-4, P0-12, P0-13, P0-14, P0-16, P0-17 (cost basis at the source), P1 outstanding with TDS and
refunds, duplicate supplier bill, cancel/return stock guard, return store default, Save & Print.

## Owned files

`app/Models/Purchase.php`, `app/Models/PurchaseLine.php`, `app/Models/PurchaseReturn.php`,
`app/Models/PurchaseReturnLine.php`, `app/Models/Payment.php`, `app/Models/PaymentAllocation.php`,
`app/Http/Controllers/Tenant/Purchases/PurchaseController.php`,
`app/Http/Controllers/Tenant/Purchases/PurchaseReturnController.php`,
`app/Http/Controllers/Tenant/Purchases/PaymentController.php`, `routes/tenant-purchase.php`,
`routes/tenant-purchase-returns.php`, `routes/tenant-payments.php`,
`resources/js/pages/Tenant/Purchases/Create.vue`, `Index.vue`, `Returns/*.vue`, `Payments/*.vue`,
`resources/views/pdf/purchase.blade.php`, `resources/views/pdf/purchase-return.blade.php`,
`tests/Feature/Tenant/Purchases/PurchasePostingTest.php`, `PurchaseControllerTest.php`,
`PurchaseListFilterTest.php`, `PurchasePrintTest.php`, `PurchaseStoreScopingTest.php`,
`PurchaseReturnTest.php`, `PurchaseReturnPrintTest.php`, `PurchaseReturnStoreScopingTest.php`,
`PaymentTest.php`, new tests under `tests/Feature/Tenant/Purchases/`. Migrations with prefix `2026_09_12_06`.

## Tasks

- [ ] 1. `Purchase::post()` on `DocumentCalculator` (C3), VAT rate from `default_vat_rate`, header discount
  split proportionally, the per-account discount split via `Money::allocate()` (no float division, no
  leftover paisa), exact partial split, TDS `0 <= tds <= taxable + nontaxable`. Casts to `Decimal`.
- [ ] 2. Stock cost at the source (C10): each purchase line's movement records `value` = the line's net value
  after line and header discount (excluding VAT, from the allocation above) and `unit_cost_rate` = value /
  base quantity (r4), via the new `Item::recordStockMovement()` signature. Store default from
  `CompanySetting::default_store_id` when none is sent.
- [ ] 3. Duplicate supplier bill protection: validation plus a migration adding a unique index on
  `(supplier_id, bill_number)` for non-null bill numbers among non-cancelled purchases (use a generated or
  partial approach that works on SQLite and MySQL; if not possible portably, enforce in the model inside the
  transaction with a lock on the supplier row and document why).
- [ ] 4. `Purchase::outstandingAmount()` returns `Money` with the same formula shape as C6/T04: `total - tds -
  settled_at_posting - posted returns (net of their TDS share) + refunds received on those returns -
  allocations of non-cancelled payments`. Only `posted` returns count.
- [ ] 5. `Purchase::cancel()` per C5 via `JournalVoucher::reverse()`: blocked while live returns or
  allocations exist; **stock guard**: lock the items (`Item::lockForStockOut()`) and refuse if the store's
  stock would go negative (unless `allow_negative_stock`); cancel columns; admin-only route.
- [ ] 6. `PurchaseReturn`: C6 amounts (remainder rule), credit the account the original purchase line
  actually debited (not the item's current account; store it on the purchase line at posting, backfilled from
  the original voucher), stock = return qty x the purchase line's `unit_conversion_factor`, movement
  `value` = the credited net value, stock guard with lock, `distinct` +
  aggregation + lock on caps, store defaults to the purchase's store, date rules, cancel per C5, migration for
  `fiscal_year_id` + `debit_note_number` and cancel columns, casts to `Decimal`. Return form shows each line's
  unit name and 4dp quantities, server-side purchase search with pagination.
- [ ] 7. `Payment`: allocations via `Purchase::outstandingAmount()`, `distinct`, aggregation, lock, exact
  comparisons, only purchases with positive outstanding; `cancel()` per C5; casts to `Decimal`.
- [ ] 8. `Purchases/Create.vue`: preview via `calculateDocument()` (fixes the unrounded VAT), exact partial
  check, `expected_total`, C11 flash for Save & Print, bank and TDS pickers limited to bank and TDS-payable
  accounts (by account group), inactive items hidden, default date `todayInKathmandu()`, unit switch restores
  the base rate. `Index.vue`, return and payment pages: stored values, BS dates, `formatMoney`.
- [ ] 9. PDFs: stored values, supplier bill number, `formatQuantity`/`formatRate`, alternate unit names, C9
  variables, amount in words.
- [ ] 10. `routes/tenant-purchase.php` also holds the capital purchase routes (T07 owns the controller): add
  `role:admin` to the capital purchase cancel route.
- [ ] 11. Tests (write, do not run): golden-vector purchase posts; discount split sums exactly with three
  expense accounts; stock `value`/`unit_cost_rate` per base unit for a Box purchase; duplicate supplier bill
  rejected (and allowed after the first is cancelled); outstanding with TDS and with a refunded return;
  cancel after the stock was sold rejected; a purchase return of 1 Box (factor 12) removes 12 from stock;
  three 1/3 returns sum exactly; duplicate payment allocation rows rejected; cancel uses the Reversal series.
