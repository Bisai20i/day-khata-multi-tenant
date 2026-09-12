# T04 Sales and POS

Phase 2 | Parallel with T03, T05-T09 | Consumes C1-C5, C7-C11, implements C11 | Audit refs: P0-1, P0-5,
P0-6, P0-8, P0-10, P0-13 (outstanding), P0-15, P0-16, P1 invoice/cancel/POS/Enter-key items.

`Sale::post()` is the most used money path in the product. Rebuild its arithmetic on `DocumentCalculator`,
keep the posting structure (accounts, voucher shape) that the audit verified as correct.

## Owned files

`app/Models/Sale.php`, `app/Models/SaleLine.php`, `app/Http/Controllers/Tenant/Sales/SaleController.php`,
`app/Http/Controllers/Tenant/Sales/PosController.php`, `app/Http/Middleware/HandleInertiaRequests.php`,
`routes/tenant-sales.php`, `routes/tenant-pos.php`, `resources/js/pages/Tenant/Sales/Create.vue`,
`resources/js/pages/Tenant/Sales/Pos.vue`, `resources/js/pages/Tenant/Sales/Index.vue`,
`resources/views/pdf/sale.blade.php`, `resources/views/pdf/sale-receipt.blade.php`,
`tests/Feature/Tenant/Sales/SalePostingTest.php`, `SaleControllerTest.php`, `SaleListFilterTest.php`,
`SalePrintTest.php`, `SaleStoreScopingTest.php`, `SaleCommissionTest.php`, `PosTest.php`, new tests under
`tests/Feature/Tenant/Sales/`. Migrations with prefix `2026_09_12_04`.

## Tasks

- [x] 1. `Sale::post()` on `DocumentCalculator` (C3): VAT rate always from
  `CompanySetting::current()->default_vat_rate` (ignore any client `vat_rate`); header discount split
  proportionally; exact partial split via `assertExactSplit()`; commission, TDS and every voucher line as
  `Money` strings. Remove every `(float)`/`round()`. Casts to `Decimal`.
- [x] 2. PAN invoices (P0-10): `force_non_taxable = true`, voucher type `VoucherType::SalePan`. Reject invoice
  types whose "enabled" flag is off in `CompanySetting`. Abbreviated invoices above Rs 10,000 total are
  rejected with a clear message ("Use a full tax invoice above Rs 10,000").
- [x] 3. Migration: `sales.fiscal_year_id`, `invoice_number` (unique together), buyer snapshot columns (C7),
  cancel columns (C5). Backfill existing rows from their voucher (`{prefix}-{voucher_number}` using current
  settings) and customer. Set all of them at posting.
- [x] 4. `Sale::outstandingAmount()` returns `Money`: `total - tds_amount - settled_at_posting - sum(posted
  returns: total - their TDS share) + sum(refunds paid on those returns) - sum(allocations of non-cancelled
  receipts)`, where `settled_at_posting` is `settlement_due` for cash/bank/partial and `0` for credit. Only
  `status = 'posted'` returns count (C6). Document the formula in the docblock; T05 and T10 rely on it.
- [x] 5. Stock-out safety: inside the transaction call `Item::lockForStockOut()` (C10) before the
  negative-stock check; compare with `Quantity` exactly using `Item::currentStock()`.
- [x] 6. `Sale::cancel()` per C5: lock, re-check blockers (live returns incl. pending, live receipt
  allocations), `JournalVoucher::reverse()`, flag movements, fill cancel columns. Remove the old mirroring
  code. Cancel route admin-only.
- [x] 7. Controller: request validation `decimal:0,4` for quantity/rate (rate required: a blank rate is a
  validation error, never silently 0), `decimal:0,2` for money and percent fields, `distinct` where relevant,
  `expected_total` (C8), `cancel_reason` max 500. Bank account and TDS account inputs accept only accounts from
  the bank and TDS groups (validate server-side, filter the pickers). Redirect with the C11
  `created` flash; implement the `flash.created` share in `HandleInertiaRequests`. `print()` uses the stored
  `invoice_number`, buyer snapshot and the C9 variables (`PrintLog::record`, `dateBs`, `amountInWords`,
  `fiscalYearName`). Send `sale_rate` to Create so the rate prefills.
- [x] 8. `Sales/Create.vue`: all preview math via `calculateDocument()` (C8); show the server-matching totals;
  per-line Total column; stock hint in the entered unit; switching back to the base unit restores the base
  rate; Enter in a line field moves to the next field or adds a line and never submits the bill; submit
  `expected_total`; after success open `flash.created.print_url`; VAT rate shown read-only; invoice-type
  options only for enabled types; date defaults to `todayInKathmandu()`.
- [x] 9. `Pos.vue`: same calculator; quantities keep 4dp (remove `round2` on quantities); quick-pay fills the
  exact `settlement_due`; cash above the due is allowed and shows change (posting the due as `cash`, never
  `partial`); the "Sale complete" receipt shows the stored sale returned by the server, not the client
  snapshot; clear the cart and its localStorage entry **before** navigating; print via `flash.created`;
  submit `expected_total`.
- [x] 10. `Sales/Index.vue`: show `invoice_number` from the server (no hardcoded `SL`/`SLA`), BS date, money
  via `formatMoney`.
- [x] 11. PDFs (`sale.blade.php`, `sale-receipt.blade.php`): print stored values only; order Subtotal,
  Discount, Taxable, Non-taxable, VAT, Grand Total; TDS shown below with "Net receivable" = grand total - TDS;
  qty with `formatQuantity`, rate with `formatRate` so qty x rate visibly equals the line; the line's unit
  (alternate unit name when used); HS code column; amount in words; thermal full tax invoices keep the VAT
  breakdown, buyer PAN and the "Tax Invoice" title.
- [x] 12. `routes/tenant-sales.php` also holds the capital sale routes (T07 owns the controller): add
  `role:admin` to the capital sale cancel route and add `GET capital-sales/{capitalSale}/print` named
  `tenant.capital-sales.print` to `CapitalSaleController::print` (T07 implements it). Match the existing
  route group's prefix/name style.
- [x] 13. Tests (write, do not run): golden-vector sale posts store exactly the vector values; PAN sale posts
  VAT 0 in the `SalePan` series; disabled invoice type rejected; abbreviated cap; header discount split on a
  mixed bill; exempt-only bill with a header discount posts; `expected_total` mismatch returns 422;
  outstanding with TDS, returns and refunds; cancel uses the Reversal series and later sales have no number
  gap; double cancel rejected; closed-year cancel rejected; staff cancel is 403; `flash.created` present after
  store; invoice number stays the same after the prefix setting changes. Update `SalePrintTest` so a PAN sale
  asserts VAT 0.

## Acceptance

- No float math left in owned PHP or Vue files.
- The voucher account structure for each payment mode is unchanged apart from exact amounts.
