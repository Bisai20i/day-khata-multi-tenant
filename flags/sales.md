# Sales flow audit (read only)

Scope: Sale, SalesReturn (credit notes, unlinked returns, request/approve), Receipt, POS, ledger posting,
cancellation. Compared against CONTRACTS C3-C9 and legacy day_khata where relevant. Nothing was run; every flag
below comes from reading the code. No P0 found: every voucher built in Sale::post(), SalesReturn::postCreditNote(),
postRefund() and Receipt::post() balances by construction, and JournalVoucher::validateLines() enforces exact balance.

## P1

### SAL-01 (P1) Sales list and export totals include cancelled sales
- **FIXED**: filteredTotals() now excludes cancelled sales (SaleController.php); test in SalesP1FixesTest.php.
- File: app/Http/Controllers/Tenant/Sales/SaleController.php:134-166 (filteredSalesQuery, filteredTotals)
- Wrong: filteredSalesQuery() has no `status` filter, so filteredTotals() sums taxable, non-taxable, VAT and total of
  cancelled invoices into the totals row on the index page and the export footer.
- Evidence: the query applies only date, customer and search filters; the SUM runs on that same builder.
- Fix: exclude `status = cancelled` in the totals query, and add a test that a cancelled sale does not move the totals.

### SAL-02 (P1) Receipt bank account is not restricted to money accounts
- **FIXED**: bank_account_id validated with Rule::in(SalesReturn::refundAccountQuery()) in ReceiptController.php, re-checked and customer own account refused in Receipt::post(); test in SalesP1FixesTest.php.
- File: app/Http/Controllers/Tenant/Sales/ReceiptController.php:77 (also app/Models/Receipt.php:148-152)
- Wrong: `bank_account_id` only needs `exists:accounts,id`. Sale uses settlementAccountQuery() and returns use
  refundAccountQuery(), but a receipt can debit any account (a customer ledger, Sales Revenue, VAT Payable) as the
  "bank". The model does not re-check either.
- Evidence: rule is `['nullable','integer','exists:accounts,id']`; Receipt::post assigns `(int) $data['bank_account_id']`
  straight into the debit line.
- Fix: validate with the same Rule::in(settlement accounts) as SaleController and enforce it again in Receipt::post().
  Also reject a bank account equal to the customer's own account.

### SAL-03 (P1) Any sale containing a negative-quantity line can never be returned
- **FIXED**: SalesReturn::saleComponents() skips negative lines (value kept inside the group so totals tie), picker omits them, a requested negative line is still refused; test in SalesP1FixesTest.php.
- File: app/Models/SalesReturn.php:1088-1090 (saleComponents)
- Wrong: SaleController allows negative quantities on purpose (legacy parity), but saleComponents() throws
  "has a negative value and cannot be returned" if ANY line of the sale is negative. No return (linked or pending)
  can be posted against the whole invoice, including its normal lines.
- Evidence: the loop over all $saleLines throws on the first negative line_total, before the requested lines are looked at.
- Fix: skip negative lines when building components (allocate only over non-negative lines) and reject only when the
  requested line itself is negative. Add a test.

### SAL-04 (P1) Posting and approving returns has no role gate; requester can approve own request
- **FIXED**: role:admin on store-unlinked, approve and reject (routes/tenant-sales-returns.php), self-approval blocked in SalesReturnController::approve(); SalesReturnWorkflowTest.php HTTP test updated; tests in SalesP1FixesTest.php. Direct store/request stay open to all users. Record in C5 pending.
- File: routes/tenant-sales-returns.php:22-26, app/Http/Controllers/Tenant/Sales/SalesReturnController.php:228-236
- Wrong: `store`, `store-unlinked`, `request`, `approve`, `reject` have no `role:` middleware. Any cashier can post an
  unlinked credit note (no original bill, free-typed rate, immediate cash refund out of AS1) and can approve their own
  pending request. Unlinked returns are a cash-out path with no cap and no second person.
- Evidence: only `cancel` carries `role:admin`; the controller comment says maker/checker was deliberately skipped.
- Fix: require admin (or a permission) for `store-unlinked`, `approve` and `reject`, and block approval by the creating
  user (`created_by === actor->id`). Record the decision in CONTRACTS C5.

## P2

### SAL-05 (P2) Stock availability is checked against today's stock, not stock as of the sale date
- File: app/Models/Sale.php:616 (`$item->currentStock($storeId)`, no `$asOf`)
- Wrong: a backdated sale inside the open year passes the check against current on-hand while stock on that date was
  lower, leaving negative historic stock in as-of reports and valuation (C10).
- Evidence: currentStock() has an `$asOf` parameter (Item.php:195) that is never passed here.
- Fix: also check the running balance from the sale date onward, or at least `currentStock($storeId, $date)`.

### SAL-06 (P2) commission_amount is stored even when no agent is chosen
- File: app/Models/Sale.php:309-311, 336, 792
- Wrong: validatedCommission() runs regardless of agent and the value is saved on the sale, but the voucher only posts
  commission when `$agent` is set. The sale shows a commission that exists nowhere in the ledger.
- Fix: reject (or force to 0) when `commission_amount > 0` and `agent_id` is null.

### SAL-07 (P2) Commission is not reversed by returns
- File: app/Models/SalesReturn.php:1303-1359 (postCreditNote)
- Wrong: a full or partial return posts no adjustment to EXE22 or the agent payable, so the agent keeps commission on
  returned goods. Cancelling the whole sale does reverse it (mirrored voucher). Not confirmed against legacy; decide
  whether to pro-rate or document it.

### SAL-08 (P2) Reversal is dated today, so cancelling can fail with an unhelpful error
- File: app/Models/JournalVoucher.php:397-429 (reverse), date guard at :167
- Wrong: reverse() requires the original year to be open but dates the reversal today. If today is past that open
  year's end_date (rollover pending), assertDateInsideFiscalYear() throws "date outside fiscal year" and the sale,
  receipt or return cannot be cancelled, with no hint of why.
- Fix: catch this in the three cancel() methods and explain, or record a clamp rule in C4.

### SAL-09 (P2) Inactive items and stores are accepted on posting
- File: app/Http/Controllers/Tenant/Sales/SaleController.php:410,422 (`exists:` only)
- Wrong: pickers show only active items and stores, but the server accepts any id, so a stale tab or crafted request
  can sell a deactivated item or use a deactivated store.
- Fix: `Rule::exists(...)->where('is_active', true)` for items and stores.

### SAL-10 (P2) Receipt allocations are not date-checked against the sale
- File: app/Models/Receipt.php:242-269 (prepareAllocations)
- Wrong: a receipt dated before an invoice can be allocated to it (returns get this check per C6, receipts do not),
  so ageing and statements can show a payment before its invoice.
- Fix: require `receipt.date >= sale.date` per allocation.

### SAL-11 (P2) Receipts have no print action and no C9 stamp
- File: routes/tenant-receipts.php (no print route), ReceiptController.php
- Wrong: C9 says every document print() logs a copy number and prints BS date and fiscal year. Sales, credit notes and
  capital sales comply; receipts cannot be printed at all.
- Fix: add a receipt print route and view using PrintLog::record().

### SAL-12 (P2) Missing tests for the risk paths above
- Not found under tests/Feature/Tenant/Sales: totals row excluding cancelled sales, receipt with a non-money
  `bank_account_id`, return against a sale with a negative line, commission without an agent, backdated stock-out,
  non-admin posting an unlinked return or approving their own request. Already covered: role 403 on cancel and
  closed-year cancel (SaleBillingTest.php:416,778, ReceiptTest.php:534, SalesReturnTest.php:549).

## Checked and fine
- Sale voucher balances in every mode (credit, cash, bank, partial, TDS, commission, PAN, per-item revenue accounts,
  header discount allocated exactly); partial split uses assertExactSplit with no 0.01 tolerance.
- Calculator use: VAT rate from settings never the request, PAN forces 0 VAT, `expected_total` mismatch gives a 422
  on that field, abbreviated invoice ceiling enforced server-side, all money via Money and the Decimal cast.
- Numbering: separate gapless series for full, abbreviated and PAN via voucher type; cancellations use the Reversal
  series; invoice_number stored and unique per fiscal year; credit note number stored at posting; pending and rejected
  returns are never numbered.
- Fiscal year: dates checked inside the resolved year in JournalVoucher::write(); receipts and returns call
  ClosedFiscalYearGuard; reverse() refuses closed years.
- Cancellation: transaction plus lockForUpdate, reason required and capped at 500, blocks on posted or pending returns
  and live receipt allocations, stock movements flagged cancelled, C5 columns filled, routes carry role:admin.
- Returns: C6 amount rule (fraction, remainder on last quantity, exact VAT and TDS reversal), sale lines locked,
  `distinct` on sale_line_id, only posted returns count in outstandingAmount(), store defaults to the original store,
  date >= sale date, refund account restricted to cash/bank in the model, refund reversed with the return.
- Receipts: per-sale aggregation, rows locked ascending, cancelled sale rejected, ownership check, over-allocation and
  nothing-outstanding guards, `distinct` on allocations.
- Cancelled sales print with a Cancelled marker on all templates; VAT book moves cancellations to the cancel period.
- Tenancy: database-per-tenant, no cross-tenant query paths found in these controllers; POS reuses POST /sales so it
  inherits the same rules.
