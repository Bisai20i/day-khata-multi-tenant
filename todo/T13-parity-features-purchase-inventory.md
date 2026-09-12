# T13 Purchase and inventory parity features

Phase 4 | Parallel with T12, T14 | Runs only after the user has run the full suite green after Phase 3 |
Audit refs: section 3 "Purchase" and section 4 polish items for purchase/inventory.

In Phase 4 you own the files that T06, T07 (capital purchase side only) and T08 owned in Phase 2. Build on
their committed code; do not undo any Phase 2 rule.

## Owned files

Everything under "Owned files" in `todo/T06-purchases-returns-payments.md` and
`todo/T08-inventory-and-costing.md`, plus `app/Models/CapitalPurchase*.php`,
`app/Http/Controllers/Tenant/Purchases/CapitalPurchaseController.php`,
`resources/js/pages/Tenant/Purchases/CapitalPurchases/*.vue`, `app/Models/Supplier.php`,
`app/Http/Controllers/Tenant/Parties/SupplierController.php`, `resources/js/pages/Tenant/Parties/Suppliers/Index.vue`,
`app/Http/Controllers/Tenant/Inventory/BarcodeLabelController.php`, new tests. Migrations with prefix
`2026_09_14_13`.

## Tasks

- [ ] 1. TDS on purchases: `tds_rate` column; the server computes `tds_amount = (taxable + nontaxable) x rate`
  exactly, capped at the base; defaults to the seeded TDS Payable account; the TDS report shows the rate
  (cross-file request to the coordinator for the report column if T10's file needs it).
- [ ] 2. PAN / non-VAT purchase mode: a bill-level toggle (defaulted from a new `suppliers.is_vat_registered`
  flag) that sets `force_non_taxable`, so every line lands in the exempt column of the Purchase VAT book.
- [ ] 3. Bonus / free quantity on purchase lines: stock only (quantity + bonus) x factor, value on quantity
  only (so the average cost per unit falls correctly), returns handle bonus units at zero value.
- [ ] 4. Unlinked purchase returns (goods from opening stock or before go-live): valued at the item's current
  average cost or an entered rate, VAT at the company rate, cash/bank split refund, debit note numbering.
- [ ] 5. Capital purchase asset register: optionally create a `FixedAsset` from a capital purchase line
  (cross-file request to the coordinator if T11's `FixedAsset` API needs a new entry point).
- [ ] 6. Items: per-unit barcode (`item_units.barcode`, unique; scanning a unit barcode selects that unit on
  Sales/Create, POS and Purchases/Create through a cross-file request list for T12's pages), base-unit MRP
  (`items.mrp`), unique item names (per tenant, case-insensitive), bulk "mark vatable" action.
- [ ] 7. Stock adjustments: alternate-unit entry (converted to base units exactly).
- [ ] 8. Purchase, return and capital purchase lists: Excel export, totals row, BS dates.
- [ ] 9. Purchase form polish: scan-to-add barcode field, per-line note, quick add-item link.
- [ ] 10. Purchase-side ledger narrations: every purchase, purchase return, payment and capital purchase
  voucher line uses `App\Support\SettlementNarration::line($documentNumber, $mode)` (T12 creates the class in
  parallel with exactly `forMode(?string $mode)` and `line(string $documentNumber, ?string $mode)`; code
  against those signatures, do not create the file yourself).
- [ ] 11. Tests (write, do not run) for every item above.
