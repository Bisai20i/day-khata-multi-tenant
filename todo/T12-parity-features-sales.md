# T12 Sales parity features

Phase 4 | Parallel with T13, T14 | Runs only after the user has run the full suite green after Phase 3 |
Audit refs: section 3 "Sales" and section 4 polish items for sales in `plans/gap-audit-2026-09-11.md`.

In Phase 4 you own the sales-side files that T04 and T05 owned in Phase 2 (list below). Build on their
committed code; do not undo any Phase 2 rule.

## Owned files

Everything listed under "Owned files" in `todo/T04-sales-and-pos.md` and `todo/T05-sales-returns-and-receipts.md`,
plus `app/Models/Customer.php`, `app/Http/Controllers/Tenant/Parties/CustomerController.php`,
`resources/js/pages/Tenant/Parties/Customers/Index.vue`, `database/seeders/Tenant/TenantDatabaseSeeder.php`,
new `app/Support/SettlementNarration.php`, new tests. Migrations with prefix `2026_09_14_12`.
(`ChartOfAccountsSeeder.php` belongs to T11's already-committed work; if you need an account, add it through
your own idempotent data migration.)

## Tasks

- [x] 1. Walk-in customer: seeded per tenant (seeder + idempotent data migration for existing tenants),
  default on POS and abbreviated invoices, protected from deletion; buyer snapshot shows "Walk-in customer".
- [x] 2. Ledger narrations (port of legacy's `SettlementNarration`): create
  `App\Support\SettlementNarration` with exactly `forMode(?string $mode): string` ("Cash Settlement", "Bank
  Settlement", "Partial", "Credit" for null/other) and `line(string $documentNumber, ?string $mode): string`
  ("{document number} - {forMode}"). Every sale, sales return and receipt voucher line carries it, so a
  customer ledger tells a sale from a receipt and cash from bank. T13 uses the same two methods for purchases
  in parallel, so keep these signatures.
- [x] 3. Service revenue: a service item with a posting account credits that account instead of `INI20`
  (the item posting account select exists since T08); VAT handling unchanged.
- [x] 4. MRP / VAT-inclusive entry on Sales/Create and POS: entering an MRP back-calculates the rate using
  `money.js` exactly (rate = MRP / 1.13 at 4dp for vatable items), the server re-derives nothing (it receives
  the rate).
  New `money.js` helper `rateExcludingVat(inclusiveRate, vatRate)` (scaled BigInt, one HalfUp rounding to
  4dp, result object like `parseMoney`). MRP is a per-line browser-only box on both screens: it fills `rate`
  and is never submitted, so no per-sale MRP column was needed (item-level `items.mrp` already exists from
  T13's `2026_09_14_130050`).
- [x] 5. Bonus / free quantity on sale lines: `sale_lines.bonus_quantity` (Quantity), stock moves
  (quantity + bonus) x factor, money on quantity only, printed on the bill, returns can return bonus units at
  zero value.
  Server side (migration `2026_09_14_120100`, `SaleLine` cast, `Sale::post()`/`assertStockAvailable()`,
  `SaleController` validation) was already in the tree from the killed first pass and the parallel returns
  work, and was verified line by line rather than redone. This chunk added the two UI fields, the bill
  column and the tests. A bonus-ONLY return (paid quantity zero) stays out of scope, as
  `SalesReturn::prepare()` documents.
- [ ] 6. Returns without a bill (sales): an "unlinked" return mode for pre-cutover sales and walk-ins, valued
  at an entered rate, VAT at the company rate, cash/bank split refund (exact split), credit note numbering as
  usual; still posted-only for money effects. Linked returns also get the cash + bank split refund option.
  IN PROGRESS: model side (`SalesReturn::postUnlinked()`, `postRefund()` split via `assertExactSplit()`,
  migrations `120200`/`120300`) was already in the tree and verified by reading. This chunk added the
  missing `Arr`/`Rule` imports `storeUnlinked()` needed (it would have fatalled), the `/sales-returns/
  unlinked` route, split-refund + `bonus_quantity` validation on the linked path, unlinked-safe list
  filtering/eager loads/PDF, and the Returns form UI. Tests still to write.
- [x] 7. Sales list: Excel export, totals row for the filtered set (SQL sums), sorting, search by invoice
  number; "Save & Print N copies" option (prints N PDFs, each recorded in the print log).
- [x] 8. Note templates: saved notes selectable on Sales/Create (small `sale_note_templates` table, admin CRUD
  inside Settings is fine via a cross-file request to the coordinator, or a simple page under Sales).
- [x] 9. Tests (write, do not run) for every item above.

## Notes from the item 7/9 pass (2026-09-16)

Item 7 (`SaleController::export()`/`index()`/`print()`, `SalesExport`, `Sales/Index.vue`) was already fully
built in the tree from an earlier pass: SQL-summed totals row (`filteredTotals()`), whitelisted
`sort`/`sort_dir` (`SORTABLE_COLUMNS`, never a raw column name reaching `orderBy()`), invoice-number
`LIKE` search, `SalesExport` (`FromCollection`/`WithMapping`/`WithColumnFormatting`, a trailing `Total` row
built from the same SQL sums the screen shows), and `?copies=N` on `print()` (capped at
`MAX_PRINT_COPIES = 5`, one `pdf.sale`/`pdf.sale-receipt` render and one `PrintLog::record()` row per copy,
stitched into a single PDF via `stitchedCopies()`). The `Sales/Index.vue` UI (Export button, Sort by
buttons, invoice-number search box, print-copies `<select>`) was also already wired. This pass verified all
of it line by line against C3/C7/C9 and found no defect worth fixing, then wrote the tests item 9 was
missing.

- **New test file, item 7:** `tests/Feature/Tenant/Sales/SaleListExportAndPrintCopiesTest.php`. Covers
  invoice-number search (partial, case-insensitive), sort by `invoice_number` (both the flipped order and
  the "unknown column falls back to `date`" whitelist guard), the filtered-set totals row, the `.xlsx`
  export via `Excel::fake()`/`Excel::assertDownloaded()` (same pattern as
  `tests/Feature/Tenant/Reports/VatReportExportTest.php`, asserting the trailing `Total` row and the row
  count), and "Save & Print N copies" (`?copies=3` produces exactly 3 `PrintLog` rows numbered 1-3, and
  `?copies=999` is capped at 5 rather than flooding the print log).
- **New test file, item 6/9:** `tests/Feature/Tenant/Sales/SalesReturnUnlinkedAndSplitRefundTest.php`. Item
  6's model/controller code (`SalesReturn::postUnlinked()`, `postRefund()`'s split via
  `DocumentCalculator::assertExactSplit()`, the linked-return `bonus_quantity` cap in `prepareLines()`) was
  already in the tree per this file's own item-6 note and was re-verified by reading rather than rewritten.
  The split-refund direction in `postRefund()` (cash/bank CREDITED, customer DEBITED) was checked against
  its own inline comment and confirmed correct: a refund pays real money out, and debiting the customer
  clears the credit balance the credit note itself created - reversing the two legs would double-count the
  credit instead of closing it. Not touched. Tests added: an unlinked return credits the customer/VAT/stock
  with no parent sale and prices at the CURRENT company VAT rate; an unlinked return against a non-existent
  customer throws; a linked-return split refund that sums exactly to the credit posts distinct cash and bank
  legs; a linked and an unlinked split refund that do NOT sum to the credit both throw `BillingException`
  (which `SalesReturnController::failed()`'s `InvalidArgumentException|AuthorizationException` catch already
  covers, since `BillingException extends InvalidArgumentException`); a linked return asking for more bonus
  units back than the line has left throws; a linked return within the bonus cap credits only the paid
  quantity and restocks the bonus units at zero value.
- A refund's bank leg needs an account filed under the "Current Assets" group (`SalesReturn::
  refundAccountQuery()`) - the default `Account::factory()` state is not, so both new test files build one
  explicitly (`account_group_id` pointed at the seeded "Current Assets" `AccountGroup`) rather than reusing
  the plain factory the way one pre-existing test in `SalesReturnTest.php` does.
- **Cross-file request: none.** No file outside this task's ownership needed a change for items 7 or 9.
- **Open item, not in this pass's scope:** `SalesReturnTest.php`'s "a return with a refund account posts a
  refund settlement voucher..." test (line 418, pre-existing, not owned by this pass) passes a plain
  `Account::factory()->create()` as `refund_account_id`, which is filed under a random subgroup, not
  "Current Assets" - `refundAccountQuery()` would reject it. Left alone per this task's scope (only items 6
  and 7's tests), but worth a look by whoever next touches that file, since it may be failing already or
  passing for a reason not obvious from the model code above.
