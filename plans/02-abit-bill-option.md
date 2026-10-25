# Implementation Plan 02: Restore the ABIT Bill Option

## 1. Requirement Summary
Restore the **ABIT Bill (Abbreviated Tax Invoice / संक्षिप्त कर बिजक)** option in the sales and billing modules:
- Users must be able to select and issue an ABIT Bill for retail counter sales.
- Under Nepal IRD regulations, an Abbreviated Tax Invoice is issued for retail sales up to NPR 10,000 without requiring buyer name, address, or buyer PAN.
- The invoice must use the tenant's designated abbreviated prefix (e.g., `SLA-` or `ABIT-`) and maintain its own independent sequential number series.
- The invoice must enforce the statutory ceiling (`ABBREVIATED_INVOICE_CEILING = 10,000.00`).
- The bill must print using the approved IRD Abbreviated Tax Invoice layout (`resources/views/pdf/ird/abbreviated.blade.php`).

## 2. Current Implementation
- **VoucherType**: [`app/Enums/VoucherType.php`](file:///D:/Projects/day-khata/day-khata-multi-tenant/app/Enums/VoucherType.php#L48) defines `case SaleAbbreviated = 'sale_abbreviated'`.
- **Company Settings**: Table `company_settings` has `sale_abbreviated_prefix` (default `'ABIT'`).
- **Domain Model**: [`app/Models/Sale.php`](file:///D:/Projects/day-khata/day-khata-multi-tenant/app/Models/Sale.php#L583-L588) contains `assertAbbreviatedCeiling($invoiceType, $totals->total)` checking `self::ABBREVIATED_INVOICE_CEILING = '10000.00'`.
- **Print View**: Approved template exists at [`resources/views/pdf/ird/abbreviated.blade.php`](file:///D:/Projects/day-khata/day-khata-multi-tenant/resources/views/pdf/ird/abbreviated.blade.php).
- **Frontend Pages**:
  - [`resources/js/pages/Tenant/Sales/Create.vue`](file:///D:/Projects/day-khata/day-khata-multi-tenant/resources/js/pages/Tenant/Sales/Create.vue) has no invoice type selector; form relies on backend defaulting.
  - [`resources/js/pages/Tenant/Sales/Pos.vue`](file:///D:/Projects/day-khata/day-khata-multi-tenant/resources/js/pages/Tenant/Sales/Pos.vue) line 59: `// the invoice-type badge is read-only (legacy's live ABIT/TAX/PAN switch needs backend work this screen-level rebuild doesn't attempt)`.

## 3. Research Findings
- In the multi-tenant rewrite, migration `2026_09_17_000000_add_active_invoice_type_to_company_settings_table.php` dropped independent flags `sale_full_enabled`, `sale_abbreviated_enabled`, and `sale_pan_enabled`, collapsing them into a single string `active_invoice_type`.
- In `SaleController::storeRules()`, `invoice_type` was omitted from request validation, and `Sale::post()` was hardcoded to read `$settings->active_invoice_type ?? 'full'`, ignoring user input.
- Although all backend math, voucher sequencing, and IRD templates for ABIT exist, the cashier has no UI control to choose ABIT.

## 4. Legacy Application Reference
- Legacy code in `D:\Projects\day-khata\day_khata`:
  - `invoiceSetting.php`: `TYPE_ABIT_FULL_TAX = 3`, `TYPE_ABIT_SERVICE = 4`, `TYPE_ALL = 7`.
  - In `resources/views/index.blade.php:54`: Menu displayed `"Add Abbreviated Sale"` (`route('abbreviatedBilling')`) under Sales.
  - In `resources/views/outStock/pos-modules/layout.blade.php:47–59`: POS rendered interactive buttons `[ABIT] [TAX]`. Clicking `[ABIT]` set `billType = 'abbreviated'`.
  - In `taxBilling.blade.php:2013–2018`: Automatic thresholding: if total $\le$ 10,000, bill defaulted to `abbreviated`.
  - Sequence: Maintained dedicated sequence `abbreviated_tax_invoice_no` in `sales_records`.
  - Print layout: `dvContentsAbit` in `abit-invoice-print-preview.blade.php`.

## 5. Verified Root Cause
The previous engineering pass presumed that invoice type was a static tenant-wide configuration decided only by a platform admin. The developer removed the UI selector in both Sales Create and POS, omitted `invoice_type` from `SaleController` validation rules, and left a comment in `Pos.vue` indicating that restoring the live switch required backend integration work.

## 6. Proposed Implementation
1. **Tenant Invoicing Configuration (see Plan 08)**:
   - Allow tenant administrators to enable Abbreviated Tax Invoicing for their business under Company Settings.
2. **Sales Form Integration (`Sales/Create.vue`)**:
   - In [`resources/js/pages/Tenant/Sales/Create.vue`](file:///D:/Projects/day-khata/day-khata-multi-tenant/resources/js/pages/Tenant/Sales/Create.vue):
     - Add an `invoice_type` selector (Segmented control or Select dropdown: `VAT Invoice (Full Tax)`, `ABIT Bill (Abbreviated)`, `PAN Invoice`).
     - When `invoice_type === 'abbreviated'`:
       - Buyer PAN and full customer address become optional.
       - Display helper badge: *"Abbreviated Tax Invoice (max NPR 10,000.00)"*.
       - Live validation alert if grand total exceeds NPR 10,000.00: *"ABIT Bills cannot exceed NPR 10,000.00 per IRD regulations. Switch to VAT Invoice."*
3. **POS Screen Integration (`Pos.vue` & `PosTopBar.vue`)**:
   - In [`resources/js/components/pos/PosTopBar.vue`](file:///D:/Projects/day-khata/day-khata-multi-tenant/resources/js/components/pos/PosTopBar.vue):
     - Replace the read-only span with interactive toggle buttons `[ABIT]` and `[TAX]`.
     - Automatically update `activeInvoiceType` in `Pos.vue`.
     - If the cart total exceeds NPR 10,000 while in ABIT mode, highlight the TAX button or auto-prompt the cashier to switch to Full Tax Invoice.
4. **Backend Controller & Validation**:
   - In [`app/Http/Controllers/Tenant/Sales/SaleController.php`](file:///D:/Projects/day-khata/day-khata-multi-tenant/app/Http/Controllers/Tenant/Sales/SaleController.php):
     - Update `storeRules()` to validate: `'invoice_type' => ['nullable', 'string', 'in:full,abbreviated,pan']`.
   - In [`app/Models/Sale.php`](file:///D:/Projects/day-khata/day-khata-multi-tenant/app/Models/Sale.php):
     - In `Sale::post()`: Use `$data['invoice_type'] ?? $settings->active_invoice_type ?? 'full'`.
     - When `invoice_type === 'abbreviated'`, assign `VoucherType::SaleAbbreviated` and sequence prefix `$settings->sale_abbreviated_prefix`.
     - Enforce `assertAbbreviatedCeiling($invoiceType, $totals->total)`.
5. **Print View Verification**:
   - Ensure `printUrl` opens [`resources/views/pdf/ird/abbreviated.blade.php`](file:///D:/Projects/day-khata/day-khata-multi-tenant/resources/views/pdf/ird/abbreviated.blade.php).

## 7. Affected Components
- `app/Http/Controllers/Tenant/Sales/SaleController.php`
- `app/Models/Sale.php`
- `resources/js/pages/Tenant/Sales/Create.vue`
- `resources/js/pages/Tenant/Sales/Pos.vue`
- `resources/js/components/pos/PosTopBar.vue`
- `resources/js/composables/useSaleCreateItems.js`

## 8. Dependencies and Risks
- **Dependency**: Must work harmoniously with Plan 03 (VAT Invoice) and Plan 04 (PAN Invoice).
- **Risk**: Cashier creating an ABIT invoice exceeding NPR 10,000.
  - *Mitigation*: Both frontend (instant UI error/disable submit) and backend (`assertAbbreviatedCeiling()`) reject any ABIT bill > NPR 10,000.
- **Risk**: Sequence collision.
  - *Mitigation*: `VoucherType::SaleAbbreviated` uses its own separate sequence table in `voucher_sequences`, preventing overlap with `Sale` (Full Tax).

## 9. Testing and Verification
- **Automated Tests**:
  - Test submitting a sale with `invoice_type = 'abbreviated'` under NPR 10,000 posts successfully with `VoucherType::SaleAbbreviated` and prefix `ABIT-`.
  - Test submitting an ABIT sale with total NPR 10,001 fails with ceiling validation error.
  - Test print view returns the `abbreviated.blade.php` template.
- **Manual Verification**:
  1. Open Sales -> New Sale. Select "ABIT Bill".
  2. Add items totaling NPR 5,000 without entering buyer PAN. Submit sale.
  3. Verify bill number starts with abbreviated prefix and print view shows Abbreviated Tax Invoice layout.
  4. In POS, click `[ABIT]`, ring up items, submit, verify print output.

## 10. Completion Criteria
- [ ] ABIT Bill option selectable in Sales Create and POS screens.
- [ ] Statutory ceiling of NPR 10,000 strictly enforced.
- [ ] Independent sequential numbering applied.
- [ ] IRD Abbreviated Tax Invoice layout printed.

## 11. Open Questions
- Should POS auto-switch from ABIT to TAX when the cart exceeds NPR 10,000 (as legacy did in mode 3/7), or show a prompt asking the cashier to confirm?
  - *Recommendation*: Show a clear prompt / switch to TAX with a warning banner, preventing cashier confusion.
