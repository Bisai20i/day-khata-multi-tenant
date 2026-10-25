# Implementation Plan 10: Restore the Capital & Service Option in Purchase

## 1. Requirement Summary
Restore the **Capital & Service Option** in the Purchase module:
- In the Purchase section, provide clear and unambiguous access to **Capital & Service Purchases** (covering both fixed asset capital investments and service/expense purchases).
- In the sidebar navigation, update the module link to read **"Capital & Service"** (or **"Capital & Service Purchases"**).
- On the index list page, display page headers and totals representing both Capital and Service purchases, and provide a Type filter (`All`, `Capital`, `Service`) to easily segment records.
- In the creation form, allow users to choose between `Capital` (Fixed Assets, with optional asset register tracking) and `Service` (Direct/Indirect Expense ledger accounts).
- Correct the Purchase VAT Book report calculation so that `Service` purchases are classified as ordinary taxable purchases and NOT misreported as Capital expenditures.

## 2. Current Implementation
- **Data Model**: [`app/Models/CapitalPurchase.php`](file:///D:/Projects/day-khata/day-khata-multi-tenant/app/Models/CapitalPurchase.php) already supports `type` with values `'capital'` and `'service'`. Lines carry direct ledger account references without inventory items.
- **Routes**: [`routes/tenant-purchase.php:36–50`](file:///D:/Projects/day-khata/day-khata-multi-tenant/routes/tenant-purchase.php#L36-L50) exposes `/capital-purchases`.
- **Navigation**: In [`resources/js/lib/nav-items.js:102`](file:///D:/Projects/day-khata/day-khata-multi-tenant/resources/js/lib/nav-items.js#L102), the sidebar link is labeled solely as `"Capital Purchases"`.
- **Frontend Pages**:
  - [`resources/js/pages/Tenant/Purchases/CapitalPurchases/Index.vue`](file:///D:/Projects/day-khata/day-khata-multi-tenant/resources/js/pages/Tenant/Purchases/CapitalPurchases/Index.vue): Title is `"Capital purchases"`, button is `"New capital purchase"`. There is no filter by Type (`All` / `Capital` / `Service`).
  - [`resources/js/pages/Tenant/Purchases/CapitalPurchases/Create.vue`](file:///D:/Projects/day-khata/day-khata-multi-tenant/resources/js/pages/Tenant/Purchases/CapitalPurchases/Create.vue): Dropdown line 37 already allows selecting `capital` or `service`, but the page header and submit button are labeled `"New capital purchase"` / `"Create capital purchase"`.
- **VAT Book Bug**: In [`SalesPurchaseReportController::capitalPurchaseVatRow()`](file:///D:/Projects/day-khata/day-khata-multi-tenant/app/Http/Controllers/Tenant/Reports/SalesPurchaseReportController.php#L557-L580), lines 573 and 579 unconditionally set:
  ```php
  'capital_amount' => $this->signed($purchase->taxable_amount, $sign),
  'capital' => true,
  ```
  This incorrectly classifies service purchases (such as office rent, audit fees, software subscriptions) into the Capital Asset column (पूँजीगत खर्च) in the Purchase VAT Book!

## 3. Research Findings
- The backend engine was fully designed during phase T07 to handle both capital and service bills under `CapitalPurchase`.
- However, the UI design omitted "Service" from the navigation label, page titles, and empty states.
- Users looking for the legacy "Capital & Service" menu concluded the feature had been dropped.
- In tax accounting, service purchases are regular operational expenses that claim Input VAT under ordinary taxable purchases, whereas capital purchases represent capitalized fixed assets. Tagging all rows with `capital = true` distorts the VAT return.

## 4. Legacy Application Reference
- Legacy code in `D:\Projects\day-khata\day_khata`:
  - Sidebar: In `resources/views/index.blade.php:60–61`, Purchase menu had:
    - `"Capital & Service"` (`route('capital-service')`)
    - `"Capital Services List"` (`route('listCapitalServices')`)
  - Form: [`resources/views/financial/capital-service.blade.php`](file:///D:/Projects/day-khata/day_khata/resources/views/financial/capital-service.blade.php):
    - Titled "Capital and Services".
    - Toggle buttons: `[Capital]` and `[Service]`.
    - When `Capital` was picked: ledger search was restricted to `Fixed Assets`.
    - When `Service` was picked: ledger search was restricted to `Direct Expenses` or `Indirect Expenses`.
  - List: [`resources/views/instock/listCapitalServices.blade.php`](file:///D:/Projects/day-khata/day_khata/resources/views/instock/listCapitalServices.blade.php) provided a filter for `All Types`, `Capital`, and `Service`.

## 5. Verified Root Cause
1. **Naming Oversight in UI**: The navigation label in `nav-items.js:102` was shortened to "Capital Purchases", hiding the service purchase capability.
2. **Missing Type Filter**: The index page had no toggle or dropdown to filter between Capital and Service purchases.
3. **VAT Book Reporting Defect**: `SalesPurchaseReportController` assigned `capital = true` and `capital_amount` to every bill, confusing service expenses with capital acquisitions.

## 6. Proposed Implementation
1. **Navigation & Labels (Safe, Non-Breaking)**:
   - In [`resources/js/lib/nav-items.js:102`](file:///D:/Projects/day-khata/day-khata-multi-tenant/resources/js/lib/nav-items.js#L102):
     - Update label to: `{ label: 'Capital & Service', href: '/capital-purchases', icon: Landmark, permission: 'capital_purchases.view' }`.
     - *Retain the existing URL `/capital-purchases` and permission key `capital_purchases.view` to prevent broken bookmarks and permission regressions.*
   - In [`config/permissions.php`](file:///D:/Projects/day-khata/day-khata-multi-tenant/config/permissions.php):
     - Update permission display labels from `"View capital purchases"` to `"View capital & service purchases"`, etc.
2. **Index View (`CapitalPurchases/Index.vue`)**:
   - Update `useLayoutChrome('Capital & Service Purchases')`.
   - Update PageHeader title to `"Capital & Service Purchases"`, and description to: *"Long-term assets and service expenses you bought. Cancel one that was posted in error."*
   - Update primary action button to `"New capital / service purchase"`.
   - Add segmented tabs or a Type filter above the DataTable:
     - `All Types`
     - `Capital`
     - `Service`
     Filtering table rows dynamically by `row.original.type`.
   - Update totals card label to `"Total of all capital & service purchases (Rs.)"`.
3. **Create View (`CapitalPurchases/Create.vue`)**:
   - Update PageHeader title to `"New capital / service purchase"`.
   - Ensure the "Purchase type" dropdown (`Capital` / `Service`) is prominently placed at the top of the form.
   - Dynamic submit button: `form.type === 'service' ? 'Create service purchase' : 'Create capital purchase'`.
4. **Fix Purchase VAT Book Calculation (`SalesPurchaseReportController.php`)**:
   - In [`app/Http/Controllers/Tenant/Reports/SalesPurchaseReportController.php:557–580`](file:///D:/Projects/day-khata/day-khata-multi-tenant/app/Http/Controllers/Tenant/Reports/SalesPurchaseReportController.php#L557-L580):
     - Update `capitalPurchaseVatRow()`:
       ```php
       $isCapital = $purchase->type === 'capital';

       return [
           'kind' => 'capital_purchase',
           'entry' => $entry,
           'id' => $purchase->id,
           'date' => $date,
           'issued_on' => $purchase->date->toDateString(),
           'bill_number' => $purchase->bill_number,
           'supplier' => $purchase->supplier?->name,
           'supplier_pan' => $purchase->supplier_pan ?: $purchase->supplier?->tpin,
           'taxable_amount' => $this->signed($purchase->taxable_amount, $sign),
           'nontaxable_amount' => $this->signed($purchase->nontaxable_amount, $sign),
           'vat_amount' => $this->signed($purchase->vat_amount, $sign),
           'capital_amount' => $isCapital ? $this->signed($purchase->taxable_amount, $sign) : Money::zero()->toString(),
           'fixed_asset_vat_amount' => Money::zero()->toString(),
           'total' => $this->signed($purchase->total, $sign),
           'capital' => $isCapital,
       ];
       ```

## 7. Affected Components
- `resources/js/lib/nav-items.js`
- `config/permissions.php`
- `resources/js/pages/Tenant/Purchases/CapitalPurchases/Index.vue`
- `resources/js/pages/Tenant/Purchases/CapitalPurchases/Create.vue`
- `app/Http/Controllers/Tenant/Reports/SalesPurchaseReportController.php`

## 8. Dependencies and Risks
- **Dependency**: `CapitalPurchase` model, migrations, settlements, and journal posting rules already fully support `service` type.
- **Risk**: None. Preserving existing route names and permission slugs prevents any breaking changes.

## 9. Testing and Verification
- **Automated Tests**:
  - Run existing test suites `tests/Feature/Tenant/Purchases/CapitalPurchaseTest.php` and `CapitalPurchasePostingRulesTest.php`.
  - Add test in `SalesPurchaseReportTest.php`:
    - Assert that a `CapitalPurchase` with `type = 'service'` reports `capital = false` and `capital_amount = '0.00'` in the Purchase VAT Book.
- **Manual Verification**:
  1. Open sidebar under Buying -> Verify "Capital & Service" is displayed.
  2. Click "Capital & Service" -> Verify title is "Capital & Service Purchases".
  3. Filter by "Service" tab; verify only service purchases appear.
  4. Create a Service Purchase for "Office Rent" with 13% VAT.
  5. Check Purchase VAT Book; verify it reports in the ordinary taxable purchase column and not in the Capital column.

## 10. Completion Criteria
- [ ] Sidebar and headers clearly state "Capital & Service Purchases".
- [ ] Type filter (`All`, `Capital`, `Service`) operational on list view.
- [ ] Service purchases debited to expense ledgers correctly.
- [ ] Purchase VAT Book correctly classifies service purchases as operational rather than capital expenditures.

## 11. Open Questions
- *None*: The fix resolves the naming gap and fixes the report classification bug cleanly.
