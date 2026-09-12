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

- [ ] 1. Walk-in customer: seeded per tenant (seeder + idempotent data migration for existing tenants),
  default on POS and abbreviated invoices, protected from deletion; buyer snapshot shows "Walk-in customer".
- [ ] 2. Ledger narrations (port of legacy's `SettlementNarration`): create
  `App\Support\SettlementNarration` with exactly `forMode(?string $mode): string` ("Cash Settlement", "Bank
  Settlement", "Partial", "Credit" for null/other) and `line(string $documentNumber, ?string $mode): string`
  ("{document number} - {forMode}"). Every sale, sales return and receipt voucher line carries it, so a
  customer ledger tells a sale from a receipt and cash from bank. T13 uses the same two methods for purchases
  in parallel, so keep these signatures.
- [ ] 3. Service revenue: a service item with a posting account credits that account instead of `INI20`
  (the item posting account select exists since T08); VAT handling unchanged.
- [ ] 4. MRP / VAT-inclusive entry on Sales/Create and POS: entering an MRP back-calculates the rate using
  `money.js` exactly (rate = MRP / 1.13 at 4dp for vatable items), the server re-derives nothing (it receives
  the rate).
- [ ] 5. Bonus / free quantity on sale lines: `sale_lines.bonus_quantity` (Quantity), stock moves
  (quantity + bonus) x factor, money on quantity only, printed on the bill, returns can return bonus units at
  zero value.
- [ ] 6. Returns without a bill (sales): an "unlinked" return mode for pre-cutover sales and walk-ins, valued
  at an entered rate, VAT at the company rate, cash/bank split refund (exact split), credit note numbering as
  usual; still posted-only for money effects. Linked returns also get the cash + bank split refund option.
- [ ] 7. Sales list: Excel export, totals row for the filtered set (SQL sums), sorting, search by invoice
  number; "Save & Print N copies" option (prints N PDFs, each recorded in the print log).
- [ ] 8. Note templates: saved notes selectable on Sales/Create (small `sale_note_templates` table, admin CRUD
  inside Settings is fine via a cross-file request to the coordinator, or a simple page under Sales).
- [ ] 9. Tests (write, do not run) for every item above.
