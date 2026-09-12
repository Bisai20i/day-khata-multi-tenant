# T07 Capital documents and quotations

Phase 2 | Parallel with T03-T06, T08, T09 | Consumes C1-C11 | Audit refs: P0-4 (capital partial split), P0-9,
P0-16 (capital cancel, quotation convert), P0-20 (fields needed by VAT reports), P1 capital VAT typed freely,
no capital sale invoice, no activity log.

## Owned files

`app/Models/CapitalSale.php`, `app/Models/CapitalSaleLine.php`, `app/Models/CapitalPurchase.php`,
`app/Models/CapitalPurchaseLine.php`, `app/Models/Quotation.php`, `app/Models/QuotationLine.php`,
`app/Http/Controllers/Tenant/Sales/CapitalSaleController.php`,
`app/Http/Controllers/Tenant/Purchases/CapitalPurchaseController.php`,
`app/Http/Controllers/Tenant/Sales/QuotationController.php`, `routes/tenant-quotations.php`. Capital routes
live in `routes/tenant-sales.php` (owned by T04) and `routes/tenant-purchase.php` (owned by T06); those tasks
add `role:admin` to the capital cancel routes and T04 adds `GET capital-sales/{capitalSale}/print` named
`tenant.capital-sales.print` pointing at `CapitalSaleController::print`, which you implement. Do not edit those
two route files.
`resources/js/pages/Tenant/Sales/CapitalSales/*.vue`, `resources/js/pages/Tenant/Purchases/CapitalPurchases/*.vue`,
`resources/js/pages/Tenant/Quotations/*.vue`, `resources/views/pdf/quotation.blade.php`, new
`resources/views/pdf/capital-sale.blade.php`, `tests/Feature/Tenant/Sales/CapitalSaleTest.php`,
`QuotationTest.php`, `QuotationPrintTest.php`, `tests/Feature/Tenant/Purchases/CapitalPurchaseTest.php`, new
tests. Migrations with prefix `2026_09_12_07`.

## Tasks

- [x] 1. Quotations: one calculation everywhere via `DocumentCalculator` (create preview through `money.js`,
  list, print, and `convertToSale()` via `Sale::post()`), so the quote always equals the resulting bill.
  Store computed totals on the quotation (migration: `taxable_amount`, `nontaxable_amount`, `vat_amount`,
  `total`, backfilled) and display stored values. `convertToSale()`: lock the quotation, re-check status
  inside the transaction; the Convert button gets a processing guard.
- [x] 2. Capital sale and purchase: VAT computed, never typed: migration adds `taxable_amount`,
  `nontaxable_amount`, `vat_rate` (backfilled from existing `vat_amount`); lines carry a `vatable` flag; totals
  via `DocumentCalculator` (no stock lines, so conversion factor 1). Exact partial split via
  `assertExactSplit()`. Casts to `Decimal`.
- [x] 3. Capital purchase fields needed by the Purchase VAT book (T10 consumes): `bill_number`, `supplier_pan`
  snapshot (defaulted from `supplier.tpin`), duplicate bill protection like T06.
- [x] 4. Capital sale tax invoice: `fiscal_year_id` + `invoice_number` (stored, own prefix setting read from
  `CompanySetting` if one exists, else `CS`; send a cross-file request to T03's settings if a new prefix
  setting is needed), buyer snapshot, and a printable PDF `capital-sale.blade.php` with C9 variables.
- [x] 5. `cancel()` on both capital models per C5 (lock, `JournalVoucher::reverse()`, cancel columns,
  admin-only route). Quotation cancel/expire transitions also lock.
- [x] 6. Activity log: attach the existing `ActivityLogObserver` to the capital and quotation models the same
  way other models do (check how Sale registers it). Quotation is already registered; the two capital models
  need two lines in `AppServiceProvider::boot()` plus `ActivityLogController::subjectTypeOptions()`, neither
  of which T07 owns - raised as a cross-file request.
- [x] 7. Pages: create forms via `money.js`, `expected_total`, C11 flash, default date `todayInKathmandu()`;
  lists show stored values, stored numbers, BS dates, `formatMoney`.
- [x] 8. Tests (write, do not run): quote total equals the converted sale total for a mixed VAT/exempt quote
  with a header discount; double convert rejected; capital VAT computed from taxable; capital partial split
  exact; capital purchase duplicate bill rejected; capital cancel uses the Reversal series; capital sale print
  shows the invoice number and amount in words.
