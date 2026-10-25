# Implementation Plan 09: Correct VAT, Sales, and Purchase Report Formats

## 1. Requirement Summary
Align the monetary columns and calculation sequence across all VAT, Sales, and Purchase registers with Nepal Inland Revenue Department (IRD) statutory schedules and client requirements:
- The standard, mandatory sequence of monetary columns across all sales, purchase, and VAT registers must be:
  1. **Sub Total** (Combined Taxable + Non-Taxable amount / Total Before Tax)
  2. **Non-Taxable** (Exempt / गैर-करयोग्य amount)
  3. **Taxable** (करयोग्य amount)
  4. **VAT** (Applicable VAT amount at 13%)
  5. **Total** (Final invoice/bill total amount = Sub Total + VAT)
- Currently, the **Sub Total** column is completely missing, and `Taxable` is erroneously positioned before `Non-Taxable`.
- Report footers, Excel exports, and print views must maintain this exact 5-column sequence and arithmetic consistency.

## 2. Current Implementation
- **Frontend Pages**:
  - [`resources/js/pages/Tenant/Reports/SalesRegister.vue:117–120`](file:///D:/Projects/day-khata/day-khata-multi-tenant/resources/js/pages/Tenant/Reports/SalesRegister.vue): Columns are `taxable_amount`, `nontaxable_amount`, `vat_amount`, `total`. Sub Total is missing. Taxable precedes Non-Taxable. Summary footer (lines 177–183) omits Sub Total.
  - [`resources/js/pages/Tenant/Reports/PurchaseRegister.vue:109–112`](file:///D:/Projects/day-khata/day-khata-multi-tenant/resources/js/pages/Tenant/Reports/PurchaseRegister.vue): Same missing Sub Total and inverted order.
  - [`resources/js/pages/Tenant/Reports/SalesVatBook.vue:113–117`](file:///D:/Projects/day-khata/day-khata-multi-tenant/resources/js/pages/Tenant/Reports/SalesVatBook.vue): Missing Sub Total. Line 116 mislabels capital column as `"Capital purchase amount"` on the Sales VAT book.
  - [`resources/js/pages/Tenant/Reports/PurchaseVatBook.vue:106–116`](file:///D:/Projects/day-khata/day-khata-multi-tenant/resources/js/pages/Tenant/Reports/PurchaseVatBook.vue): Missing Sub Total.
  - [`resources/js/pages/Tenant/Reports/SalesReturnRegister.vue`](file:///D:/Projects/day-khata/day-khata-multi-tenant/resources/js/pages/Tenant/Reports/SalesReturnRegister.vue) & [`PurchaseReturnRegister.vue`](file:///D:/Projects/day-khata/day-khata-multi-tenant/resources/js/pages/Tenant/Reports/PurchaseReturnRegister.vue): Missing Sub Total.
- **Backend Controller**:
  - [`app/Http/Controllers/Tenant/Reports/SalesPurchaseReportController.php`](file:///D:/Projects/day-khata/day-khata-multi-tenant/app/Http/Controllers/Tenant/Reports/SalesPurchaseReportController.php): Does not calculate or return a `subtotal` key in row payloads (`salesRegister`, `purchaseRegister`, `salesVatBookRows`, `purchaseVatBookRows`, `salesReturnRegisterRows`, `purchaseReturnRegisterRows`) nor in `totalsFor()`.
- **Excel Exports**:
  - `SalesVatBookExport.php`, `PurchaseVatBookExport.php`, `SalesReturnRegisterExport.php`, `PurchaseReturnRegisterExport.php`, `SalesExport.php` omit Sub Total and place Taxable before Exempt.

## 3. Research Findings
- **Nepal IRD Statutory Schedules (Schedule 5 Sales Book & Schedule 6 Purchase Book)**:
  - Sequence:
    - कुल बिक्री / खरिद (Total Amount Before Tax / Sub Total)
    - कर छुट हुने बिक्री / खरिद (Tax Exempt / Non-Taxable Value)
    - करयोग्य बिक्री / खरिद (Taxable Value)
    - कर रकम (VAT Amount)
    - जम्मा रकम (Grand Total)
- **Mathematical Integrity**:
  - In `DocumentCalculator.php`, `taxableAmount` and `nontaxableAmount` are computed after allocating any document discounts.
  - Therefore, $\text{Sub Total} = \text{Taxable Amount} + \text{Non-Taxable Amount}$ is always exact, and $\text{Total} = \text{Sub Total} + \text{VAT Amount}$.
  - Storing and displaying Sub Total provides immediate visual verification of transaction gross base before VAT.

## 4. Legacy Application Reference
- Legacy code in `D:\Projects\day-khata\day_khata`:
  - [`resources/views/reports/account/vat-report-all.blade.php:258–263`](file:///D:/Projects/day-khata/day_khata/resources/views/reports/account/vat-report-all.blade.php#L258-L263):
    ```php
    $amtBeforeTax = ($item->taxable ?: 0) + ($item->nontaxable ?: 0);
    ```
    Columns rendered in exact order: Total Amount (Before Tax) -> Local Tax Exempted Value -> Taxable Transaction Value -> Taxable Transaction Tax -> Total.
  - [`resources/views/outStock/vat-report-sales.blade.php:352`](file:///D:/Projects/day-khata/day_khata/resources/views/outStock/vat-report-sales.blade.php#L352):
    Rendered `$rowTotal` (Sub Total) -> `$item->nontaxable` -> `$item->taxable` -> `$item->vat`.
  - [`resources/views/instock/vat-report-purchase.blade.php:310`](file:///D:/Projects/day-khata/day_khata/resources/views/instock/vat-report-purchase.blade.php#L310):
    Rendered `$rowTotal` (Sub Total) -> `$item->nontaxable` -> `$item->taxable` -> `$item->vat`.

## 5. Verified Root Cause
During the Inertia/Vue table redesign, the frontend developer defined table columns as `taxable_amount`, `nontaxable_amount`, `vat_amount`, `total`, omitting the aggregate "Total Before Tax / Sub Total" column required by Nepal IRD and placing the Taxable column ahead of Non-Taxable. The backend controller followed this shortened shape and did not include `subtotal`.

## 6. Proposed Implementation
1. **Backend Controller Updates (`SalesPurchaseReportController.php`)**:
   - In [`SalesPurchaseReportController.php`](file:///D:/Projects/day-khata/day-khata-multi-tenant/app/Http/Controllers/Tenant/Reports/SalesPurchaseReportController.php):
     - In all row mappers (`salesRegister`, `purchaseRegister`, `salesVatBookRows`, `purchaseVatBookRows`, `salesReturnRegisterRows`, `purchaseReturnRegisterRows`):
       - Calculate: `'subtotal' => Money::of($row['taxable_amount'])->plus(Money::of($row['nontaxable_amount']))->toString()`
     - In `totalsFor()` helper:
       - Include `'subtotal' => Money::of($totals['taxable_amount'])->plus(Money::of($totals['nontaxable_amount']))->toString()`.
2. **Frontend Vue Table Definitions**:
   - Update column definitions in:
     - `SalesRegister.vue`
     - `PurchaseRegister.vue`
     - `SalesVatBook.vue`
     - `PurchaseVatBook.vue`
     - `SalesReturnRegister.vue`
     - `PurchaseReturnRegister.vue`
   - Arrange columns in exact order:
     ```javascript
     { id: 'subtotal', header: 'Sub Total', numeric: true, cell: ({ row }) => formatMoney(row.original.subtotal) },
     { id: 'nontaxable_amount', header: 'Non-Taxable', numeric: true, cell: ({ row }) => formatMoney(row.original.nontaxable_amount) },
     { id: 'taxable_amount', header: 'Taxable', numeric: true, cell: ({ row }) => formatMoney(row.original.taxable_amount) },
     { id: 'vat_amount', header: 'VAT (13%)', numeric: true, cell: ({ row }) => formatMoney(row.original.vat_amount) },
     { id: 'total', header: 'Total', numeric: true, cell: ({ row }) => formatMoney(row.original.total) },
     ```
   - In `SalesVatBook.vue` line 116, fix label to `"Capital sales amount"`.
   - Update summary footer cards to display:
     `Sub Total` -> `Non-Taxable` -> `Taxable` -> `VAT` -> `Total`.
3. **Excel Export Classes**:
   - In `app/Exports/`:
     - `SalesVatBookExport.php`
     - `PurchaseVatBookExport.php`
     - `SalesReturnRegisterExport.php`
     - `PurchaseReturnRegisterExport.php`
     - `SalesExport.php`
   - Update `headings()` and `map()` rows to match the 5-column sequence:
     `Sub Total`, `Non-Taxable`, `Taxable`, `VAT`, `Total`.

## 7. Affected Components
- `app/Http/Controllers/Tenant/Reports/SalesPurchaseReportController.php`
- `app/Http/Controllers/Tenant/Reports/VatSummaryReportController.php`
- `resources/js/pages/Tenant/Reports/SalesRegister.vue`
- `resources/js/pages/Tenant/Reports/PurchaseRegister.vue`
- `resources/js/pages/Tenant/Reports/SalesVatBook.vue`
- `resources/js/pages/Tenant/Reports/PurchaseVatBook.vue`
- `resources/js/pages/Tenant/Reports/SalesReturnRegister.vue`
- `resources/js/pages/Tenant/Reports/PurchaseReturnRegister.vue`
- `app/Exports/SalesVatBookExport.php`
- `app/Exports/PurchaseVatBookExport.php`
- `app/Exports/SalesReturnRegisterExport.php`
- `app/Exports/PurchaseReturnRegisterExport.php`
- `app/Exports/SalesExport.php`

## 8. Dependencies and Risks
- **Dependency**: Exact decimal arithmetic using `Brick\Math\BigDecimal` / `Money` value object ensures zero rounding discrepancies.
- **Risk**: Table width on smaller displays.
  - *Mitigation*: Enable horizontal scrolling on the `DataTable` container with pinned column styling if necessary.

## 9. Testing and Verification
- **Automated Tests**:
  - Update `tests/Feature/Tenant/Reports/SalesPurchaseReportTest.php` and `VatReportExportTest.php`:
    - Assert that response JSON contains `subtotal` for rows and in `totals`.
    - Assert that `totals.subtotal == totals.taxable_amount + totals.nontaxable_amount`.
    - Assert that exported Excel headings match the 5-column sequence.
- **Manual Verification**:
  1. Open `/reports/sales-register` and `/reports/purchase-register`.
  2. Verify column order: Sub Total -> Non-Taxable -> Taxable -> VAT -> Total.
  3. Verify that Sub Total equals Taxable + Non-Taxable on every row and in the footer.
  4. Download Excel export and verify columns match the on-screen table.

## 10. Completion Criteria
- [ ] All 6 sales/purchase/VAT registers display Sub Total column.
- [ ] Non-Taxable appears before Taxable in all tables and exports.
- [ ] Table summary footers include Sub Total.
- [ ] Excel exports align with the exact column order.
- [ ] IRD Schedule compliance achieved.

## 11. Open Questions
- *None*: The requirement directly enforces standard statutory IRD formats.
