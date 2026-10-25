# Implementation Plan 03: Restore the VAT Invoice Option

## 1. Requirement Summary
Restore the **VAT Invoice (Full Tax Invoice / कर बिजक)** option in sales and billing modules:
- Users must be able to explicitly select and issue a VAT Invoice (Full Tax).
- Under Nepal tax law, a Full Tax Invoice is mandatory for B2B transactions, sales to registered taxpayers, and retail sales exceeding NPR 10,000.
- Must display customer PAN/VAT number, customer name and address, breakdown of Taxable and Non-Taxable amounts, and 13% VAT.
- Must use the tenant's configured Full Tax Invoice prefix (e.g. `SL-` or `INV-`) and increment the dedicated Full Tax sequential series.
- Must generate and print using the IRD-approved Full Tax Invoice format (`resources/views/pdf/ird/tax.blade.php`).

## 2. Current Implementation
- **VoucherType**: [`app/Enums/VoucherType.php`](file:///D:/Projects/day-khata/day-khata-multi-tenant/app/Enums/VoucherType.php#L47) defines `case Sale = 'sale'`.
- **Company Settings**: Table `company_settings` has `sale_full_prefix` (default `'INV'`) and `active_invoice_type` (`'full'`).
- **Domain Model**: [`app/Models/Sale.php`](file:///D:/Projects/day-khata/day-khata-multi-tenant/app/Models/Sale.php#L292) defaults to `$invoiceType = $settings->active_invoice_type ?? 'full'`.
- **Print View**: Approved IRD template exists at [`resources/views/pdf/ird/tax.blade.php`](file:///D:/Projects/day-khata/day-khata-multi-tenant/resources/views/pdf/ird/tax.blade.php).
- **Frontend Pages**:
  - In [`resources/js/pages/Tenant/Sales/Create.vue`](file:///D:/Projects/day-khata/day-khata-multi-tenant/resources/js/pages/Tenant/Sales/Create.vue), there is no invoice type selector.
  - In [`resources/js/composables/useSaleCreateItems.js`](file:///D:/Projects/day-khata/day-khata-multi-tenant/resources/js/composables/useSaleCreateItems.js#L11-L14), `isPanInvoice` is hardcoded as `props.invoiceSettings.active_invoice_type === 'pan'`. The comment explicitly states: *"The invoice type is never a form field - it's fixed per tenant by the platform admin..."*
  - In [`resources/js/components/pos/PosTopBar.vue`](file:///D:/Projects/day-khata/day-khata-multi-tenant/resources/js/components/pos/PosTopBar.vue), the badge is static and read-only.

## 3. Research Findings
- Although "Full Tax" was the backend default when `active_invoice_type = 'full'`, businesses that have both cash retail (ABIT) and credit/corporate sales (VAT) cannot select "VAT Invoice" at transaction time.
- If a tenant's setting is changed to `abbreviated` or `pan`, they completely lose the ability to issue a standard VAT Invoice.
- In `SaleController::storeRules()`, the backend ignores any incoming `invoice_type` parameter, enforcing only the static database value.

## 4. Legacy Application Reference
- Legacy code in `D:\Projects\day-khata\day_khata`:
  - `invoiceSetting.php`: `TYPE_FULL_TAX = 2`, `TYPE_ABIT_FULL_TAX = 3`, `TYPE_FULL_TAX_SERVICE = 6`, `TYPE_ALL = 7`.
  - In `resources/views/index.blade.php:54`: Navigation menu showed `"Add Tax Sale"` (`route('taxBilling')`) gated to privilege `AddSell`.
  - Dedicated screen: [`resources/views/outStock/taxBilling.blade.php`](file:///D:/Projects/day-khata/day_khata/resources/views/outStock/taxBilling.blade.php).
  - POS: Interactive `[TAX]` button in `pos-modules/layout.blade.php`.
  - Sequential series: `tax_invoice_no` column in `sales_records`.
  - Print layout: `full-tax-invoice-print-preview.blade.php` (`dvContentsFullTax`), subtitled "VAT Registered Persons Only".

## 5. Verified Root Cause
The new multi-tenant application removed the entry-level invoice type selection in favor of an admin-only singleton setting, making it impossible for users to choose "VAT Invoice" when multiple billing options are needed or when switching between retail and tax invoices.

## 6. Proposed Implementation
1. **Sales Form (`Sales/Create.vue`)**:
   - Provide an `invoice_type` selector in the header of the sales creation screen:
     - `VAT Invoice (Full Tax)` (default for VAT-registered tenants)
     - `ABIT Bill (Abbreviated)`
     - `PAN Invoice`
   - When `VAT Invoice` is selected:
     - Enable full VAT calculations (13% VAT on vatable items).
     - Display customer PAN input with Nepal 9-digit PAN validation.
     - Enforce customer name and address for bills above NPR 10,000.
2. **POS Screen (`Pos.vue` & `PosTopBar.vue`)**:
   - Activate the `[TAX]` button in `PosTopBar.vue`.
   - When active, all vatable items apply the company VAT rate.
   - Opening the customer details drawer displays customer PAN and name fields.
3. **Backend Controller & Model**:
   - In [`SaleController.php`](file:///D:/Projects/day-khata/day-khata-multi-tenant/app/Http/Controllers/Tenant/Sales/SaleController.php):
     - Accept and validate `invoice_type: 'full'`.
   - In [`Sale.php`](file:///D:/Projects/day-khata/day-khata-multi-tenant/app/Models/Sale.php):
     - When `invoice_type === 'full'`, post with `VoucherType::Sale` and prefix `$settings->sale_full_prefix`.
     - Output VAT is credited to `LIA20` (Output VAT) via `DocumentCalculator`.
4. **Print & PDF Output**:
   - Ensure print view resolves to [`resources/views/pdf/ird/tax.blade.php`](file:///D:/Projects/day-khata/day-khata-multi-tenant/resources/views/pdf/ird/tax.blade.php) with full IRD 9-box PAN display, taxable base, exempt base, VAT amount, and grand total.

## 7. Affected Components
- `app/Http/Controllers/Tenant/Sales/SaleController.php`
- `app/Models/Sale.php`
- `resources/js/pages/Tenant/Sales/Create.vue`
- `resources/js/pages/Tenant/Sales/Pos.vue`
- `resources/js/components/pos/PosTopBar.vue`
- `resources/js/composables/useSaleCreateItems.js`
- `resources/js/composables/useSaleCreatePreview.js`

## 8. Dependencies and Risks
- **Dependency**: Multi-tenant `DocumentCalculator` already performs standard VAT arithmetic and journal generation.
- **Risk**: Customer PAN validation.
  - *Mitigation*: Ensure PAN validation permits cash sales under NPR 10,000 to be recorded as Tax Invoices if requested, while requiring 9 digits when entered.

## 9. Testing and Verification
- **Automated Tests**:
  - Test posting a sale with `invoice_type = 'full'` creates a `Sale` record with `VoucherType::Sale`, allocates `INV-` prefix sequence, and computes correct 13% Output VAT.
  - Test print view generates `tax.blade.php` with buyer details and VAT breakdown.
- **Manual Verification**:
  1. Open Sales -> New Sale, select "VAT Invoice".
  2. Enter a customer with PAN, add vatable items, verify Taxable, VAT, and Total amounts.
  3. Submit and verify invoice prints formatted Full Tax Invoice.

## 10. Completion Criteria
- [ ] VAT Invoice explicitly selectable in Sales Create and POS.
- [ ] Full Tax numbering sequence and prefix applied.
- [ ] Proper GL posting to Output VAT (`LIA20`).
- [ ] IRD Full Tax Invoice print template delivered.

## 11. Open Questions
- *None*: The VAT Invoice workflow is standard across Nepal accounting systems and aligns with the existing backend engine.
