# Implementation Plan 04: Restore the PAN Invoice Option

## 1. Requirement Summary
Restore the **PAN Invoice (PAN Bill / पान बिजक)** option in sales and billing modules:
- Users must be able to select and issue a PAN Invoice.
- Under Nepal tax regulations, entities not registered under VAT (or selling exclusively non-taxable goods/services) must issue PAN Invoices.
- A PAN Invoice carries **ZERO VAT** across all items, regardless of whether items are marked `is_vatable` in the master catalog.
- Must use the tenant's configured PAN bill prefix (e.g., `PAN-` or `SLP-`) and increment the dedicated PAN sequential series.
- Must generate and print using the IRD-approved PAN Bill format (`resources/views/pdf/ird/pan.blade.php`), omitting tax rate, taxable amount, and VAT breakdown columns.

## 2. Current Implementation
- **VoucherType**: [`app/Enums/VoucherType.php`](file:///D:/Projects/day-khata/day-khata-multi-tenant/app/Enums/VoucherType.php#L49) defines `case SalePan = 'sale_pan'`.
- **Company Settings**: Table `company_settings` has `sale_pan_prefix` (default `'PAN'`).
- **Domain Model**: [`app/Models/Sale.php`](file:///D:/Projects/day-khata/day-khata-multi-tenant/app/Models/Sale.php#L292) only activates PAN logic when the tenant's global setting `active_invoice_type` equals `'pan'`.
- **Tax Suppression**: [`DocumentCalculator.php`](file:///D:/Projects/day-khata/day-khata-multi-tenant/app/Support/Billing/DocumentCalculator.php) and [`useSaleCreateItems.js`](file:///D:/Projects/day-khata/day-khata-multi-tenant/resources/js/composables/useSaleCreateItems.js#L19) already contain logic to force VAT to `0.00` when `isPanInvoice` is true.
- **Print View**: Approved IRD template exists at [`resources/views/pdf/ird/pan.blade.php`](file:///D:/Projects/day-khata/day-khata-multi-tenant/resources/views/pdf/ird/pan.blade.php).
- **Frontend Pages**:
  - In `Sales/Create.vue`, there is no option to choose PAN Invoice.
  - In `Pos.vue` and `PosTopBar.vue`, the badge is static and does not allow switching to PAN Bill.

## 3. Research Findings
- Businesses operating under PAN registration or businesses operating a mixed counter cannot select PAN Bill because the form has no option for it.
- When `invoice_type === 'pan'`, the backend already correctly uses `VoucherType::SalePan`, assigns the `sale_pan_prefix`, routes to the PAN sequence, and prints the clean PAN layout.
- The only barrier is the lack of frontend selection and the fact that `SaleController` does not allow passing `invoice_type: 'pan'`.

## 4. Legacy Application Reference
- Legacy code in `D:\Projects\day-khata\day_khata`:
  - `invoiceSetting.php`: `TYPE_PAN = 0`, `TYPE_PAN_SERVICE = 1`.
  - In `resources/views/index.blade.php:54`: Navigation menu showed `"Add Sale [PAN]"` (`route('panSalesBilling')`) gated to privilege `AddSell`.
  - Also had `"Return Sale [PAN]"` (`route('panSalesReturnBilling')`) and `"Service Sale [PAN]"` (`route('panServiceSalesBilling')`).
  - Dedicated screen: [`resources/views/outStock/panSales.blade.php`](file:///D:/Projects/day-khata/day_khata/resources/views/outStock/panSales.blade.php):
    - Submits `vat: 0`, `taxable: 0`, `nontaxable: gtotal`.
    - No VAT or tax breakdown columns displayed.
  - POS: Displayed `PAN` badge; `npDefaultBillingType()` returned `'pan'`.
  - Print layout: `pan-invoice-print-preview.blade.php` (`dvContents`), displaying "Tax Registration No. (PAN)" without VAT columns.

## 5. Verified Root Cause
The new multi-tenant application eliminated the entry-level invoice type selection in `Sales/Create.vue` and `Pos.vue`. A tenant whose default setting is `full` or `abbreviated` has no UI option to generate a PAN Bill, even though the backend model, sequencing, and print templates for PAN Bills are already implemented.

## 6. Proposed Implementation
1. **Sales Form Integration (`Sales/Create.vue`)**:
   - Add `PAN Invoice` to the `invoice_type` selection:
     - `VAT Invoice (Full Tax)`
     - `ABIT Bill (Abbreviated)`
     - `PAN Invoice`
   - When `PAN Invoice` is selected:
     - Recompute all line totals with effective VAT rate = `0.00%`.
     - In the Totals Bar, hide Taxable Amount and VAT Amount rows, displaying only:
       - Sub Total (Gross)
       - Discount
       - Grand Total
2. **POS Integration (`Pos.vue` & `PosTopBar.vue`)**:
   - For tenants that have PAN invoicing enabled, allow selecting `[PAN]` in `PosTopBar.vue`.
   - In POS cart totals, immediately zero out VAT and update grand total.
3. **Backend Controller & Validation**:
   - In [`SaleController.php`](file:///D:/Projects/day-khata/day-khata-multi-tenant/app/Http/Controllers/Tenant/Sales/SaleController.php):
     - Validate `'invoice_type' => ['nullable', 'in:full,abbreviated,pan']`.
   - In [`Sale.php`](file:///D:/Projects/day-khata/day-khata-multi-tenant/app/Models/Sale.php):
     - When `invoice_type === 'pan'`, allocate `VoucherType::SalePan` and prefix `$settings->sale_pan_prefix`.
     - Set `vat_rate = 0.00`, `vat_amount = 0.00`, and treat entire amount as non-taxable turnover.
4. **Print Template**:
   - Verify that bills generated with `invoice_type = 'pan'` automatically render [`resources/views/pdf/ird/pan.blade.php`](file:///D:/Projects/day-khata/day-khata-multi-tenant/resources/views/pdf/ird/pan.blade.php).

## 7. Affected Components
- `app/Http/Controllers/Tenant/Sales/SaleController.php`
- `app/Models/Sale.php`
- `resources/js/pages/Tenant/Sales/Create.vue`
- `resources/js/pages/Tenant/Sales/Pos.vue`
- `resources/js/components/pos/PosTopBar.vue`
- `resources/js/composables/useSaleCreateItems.js`
- `resources/js/composables/useSaleCreatePreview.js`

## 8. Dependencies and Risks
- **Dependency**: Harmonized with Plan 02 (ABIT) and Plan 03 (VAT Invoice).
- **Risk**: Accounting entry.
  - *Mitigation*: Ensure no credit entry is made to `LIA20` (Output VAT) on a PAN Invoice; the entire credit leg goes to `REV10` (Sales Revenue). Multi-tenant `DocumentCalculator` already guarantees this when VAT amount is zero.

## 9. Testing and Verification
- **Automated Tests**:
  - Test submitting a sale with `invoice_type = 'pan'` posts with `VoucherType::SalePan`, prefix `PAN-`, zero VAT, and correct journal entries.
  - Test print view renders `pan.blade.php`.
- **Manual Verification**:
  1. Open Sales -> New Sale, select "PAN Invoice".
  2. Add vatable items, verify VAT is 0.00 and Grand Total equals item rate $\times$ qty.
  3. Submit and verify invoice prints PAN Bill layout without VAT breakdown.

## 10. Completion Criteria
- [ ] PAN Invoice selectable in Sales Create and POS.
- [ ] Zero VAT computed and displayed.
- [ ] Dedicated sequential PAN numbering applied.
- [ ] IRD PAN Bill print layout rendered.

## 11. Open Questions
- *None*: Clear statutory rules and complete existing backend support.
