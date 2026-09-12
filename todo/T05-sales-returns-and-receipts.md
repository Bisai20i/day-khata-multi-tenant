# T05 Sales returns and receipts

Phase 2 | Parallel with T03, T04, T06-T09 | Consumes C1-C9, implements C6 (sales side) | Audit refs: P0-3,
P0-4 (allocations), P0-12, P0-13, P0-14, P0-16, P1 credit note number, return store default, return date,
`sale_return_lines.rate` precision.

## Owned files

`app/Models/SalesReturn.php`, `app/Models/SaleReturnLine.php`, `app/Models/Receipt.php`,
`app/Models/ReceiptAllocation.php`, `app/Http/Controllers/Tenant/Sales/SalesReturnController.php`,
`app/Http/Controllers/Tenant/Sales/ReceiptController.php`, `routes/tenant-sales-returns.php`,
`routes/tenant-receipts.php`, `resources/js/pages/Tenant/Sales/Returns/*.vue`,
`resources/js/pages/Tenant/Sales/Receipts/*.vue`, `resources/views/pdf/sales-return.blade.php`,
`tests/Feature/Tenant/Sales/SalesReturnTest.php`, `SalesReturnWorkflowTest.php`, `SalesReturnPrintTest.php`,
`SalesReturnStoreScopingTest.php`, `ReceiptTest.php`, new tests under `tests/Feature/Tenant/Sales/`.
Migrations with prefix `2026_09_12_05`.

## Tasks

- [x] 1. Amounts per C6: per-component `multipliedByFraction` with the last-remaining-quantity remainder rule
  (line value after line and header discount, VAT, TDS share). A return that completes the whole sale
  reverses VAT and TDS exactly. No division through floats anywhere. Casts to `Decimal`.
- [x] 2. Stock per C6: movement quantity = return qty x `saleLine.unit_conversion_factor`, in both `post()`
  and `approve()`. Stockability comes from whether the original sale line created a stock movement, not the
  item's current `is_stockable`.
- [x] 3. Caps: `distinct` on `lines.*.sale_line_id`, aggregate per sale line, lock the sale lines (ascending
  id) and re-read already-returned quantities inside the transaction; `pending` and `posted` reserve quantity;
  exact `Quantity` comparisons (no `0.0001` tolerance; the 0.3 - 0.1 case must pass).
- [x] 4. Workflow transitions `approve()`, `reject()`, `cancel()`: lock + re-check status inside the
  transaction (C5). Approval re-validates the caps against current state. Cancel via
  `JournalVoucher::reverse()`, cancel columns, admin-only route.
- [x] 5. Migration: `sales_returns.fiscal_year_id` + `credit_note_number` (unique together, backfilled),
  cancel columns (C5), `sale_return_lines.rate` widened to `decimal(15,4)` (SQLite-safe column change).
- [x] 6. Defaults and validation: store defaults to the sale's store; return date `>=` sale date and inside
  the open fiscal year; the refund account must be a cash or bank account (validate server-side, filter the
  picker); walk-in/refund split stays as today (split cash+bank refunds are Phase 4).
- [x] 7. Receipts: allocations use `Sale::outstandingAmount()` (T04, returns `Money`); `distinct` on
  `allocations.*.sale_id`; aggregate per sale; lock the sale rows; exact comparisons (a 0.01 receipt is
  valid; over-allocation by 0.01 is rejected); allocating to a sale whose outstanding is not positive is
  rejected. `Receipt::cancel()` per C5. Casts to `Decimal`.
- [x] 8. Pages: `Returns/Create.vue` shows the sale's `invoice_number` in the picker (server-side search with
  pagination instead of loading every sale), each line's unit name and 4dp quantities, preview via `money.js`
  using the same component rule for display; `Receipts/Create.vue` allocation totals via `money.js`, exact
  checks, default date `todayInKathmandu()`; list pages show stored numbers, BS dates, `formatMoney`.
- [x] 9. `sales-return.blade.php`: title "Credit Note" with `credit_note_number` only for posted returns,
  "Return request #{id}" otherwise; cites the original `invoice_number`; C9 variables; amount in words.
- [x] 10. Tests (write, do not run): return 1 Box of 12 restocks 12; three returns of 1/3 each credit exactly
  the original line value in total; full return reverses VAT exactly; duplicate line payload rejected;
  over-return with a pending request rejected; 0.3 - 0.1 - 0.2 case accepted; approve after reject is
  rejected; rejected return has no effect on outstanding or VAT; cancel uses the Reversal series and never
  consumes an invoice number; duplicate allocation rows rejected; 0.01 receipt accepted; allocation to a cash
  sale rejected.
