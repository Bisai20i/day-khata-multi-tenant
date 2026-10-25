# Implementation Plan 07: Fix Report Search Functionality Redirecting to Dashboard

## 1. Requirement Summary
Fix the critical bug where entering a search term or submitting a search filter on report pages redirects the user to the dashboard (`/dashboard`) instead of filtering the report results:
- Search and filter functionality across all report pages must remain on the respective report page.
- Table results must update dynamically according to the entered search criteria.
- Users must never be inadvertently redirected away to the dashboard.
- Reports must provide intuitive search controls for searching by bill/invoice number, voucher number, customer name, supplier name, or reference text.

## 2. Current Implementation
- **Root Route Behavior**: In [`routes/tenant.php:31–40`](file:///D:/Projects/day-khata/day-khata-multi-tenant/routes/tenant.php#L31-L40):
  ```php
  Route::get('/', function (Request $request) {
      return $request->user('web')
          ? redirect()->route('tenant.dashboard')
          : redirect()->route('tenant.login');
  });
  ```
  Any GET request sent to the root path `/` unconditionally redirects to `/dashboard`.
- **Global Header Search**: In [`resources/js/layouts/AppLayout.vue:244–292, 450–520`](file:///D:/Projects/day-khata/day-khata-multi-tenant/resources/js/layouts/AppLayout.vue):
  - Renders a global input in the top header with placeholder `"Search pages and actions (Ctrl K)"`.
  - Computes `allCommands = [...quickActions, ...navCommands]`.
  - When a user on a report page types into this search bar and hits `Enter`:
    ```javascript
    function selectActiveCommand() {
        const command = filteredCommands.value[activeIndex.value];
        if (command) selectCommand(command);
    }
    ```
    If the search query does not match any action or command, or if `activeIndex` is `0`, it selects the first navigation command: **`Dashboard` (`/dashboard`)**, redirecting the user away from their report.
- **Report Pages**:
  - Most report pages (e.g. `SalesRegister.vue`, `PurchaseRegister.vue`, `DayBook.vue`, `CashBook.vue`, `BankBook.vue`, `VatSummary.vue`) only have date range pickers and static dropdowns.
  - They lack in-page text search inputs for searching by invoice number, bill number, voucher reference, or party name.

## 3. Research Findings
- Users navigating reports expect to search for specific records (such as invoice "SL-0042", supplier "Himalayan Traders", or voucher "JV-10").
- Because there is no search input inside the report panel, users naturally type their search terms into the top header search box (`Search pages and actions (Ctrl K)`).
- When they type a non-navigation string (e.g. "INV-102") and press Enter, the header palette executes `selectActiveCommand()` which redirects to `/dashboard`.
- Furthermore, if any form on a page submits without `@submit.prevent`, default browser submission to `""` or `"/"` triggers `GET /`, which also routes directly to `/dashboard`.

## 4. Legacy Application Reference
- Legacy code in `D:\Projects\day-khata\day_khata`:
  - Every report had an explicit search filter form directly above the report table (e.g., [`resources/views/reports/account/vat-report-all.blade.php:120`](file:///D:/Projects/day-khata/day_khata/resources/views/reports/account/vat-report-all.blade.php#L120), [`salesRegularReport.blade.php`](file:///D:/Projects/day-khata/day_khata/resources/views/outStock/salesRegularReport.blade.php)).
  - Forms had dedicated inputs:
    - `bill_no` / `invoice_no` (text input)
    - `party_name` / `customer` (text/select input)
    - Date range (`from_date`, `to_date`)
  - Form action was explicitly pointed to the report's own URL (e.g. `action="{{ url('/sales-regular-report') }}" method="GET"`).
  - Submitting the search submitted GET parameters to the same report route, filtering the table in place.

## 5. Verified Root Cause
1. **Lack of In-Report Search**: The multi-tenant report pages only implemented date pickers and omitted text search inputs for bill numbers and party names, causing users to use the top header bar.
2. **Defective Command Palette Handling in `AppLayout.vue`**: In `AppLayout.vue`, `selectActiveCommand()` executed whenever `Enter` was pressed in the header input. If no exact match was found, or if the list was empty, or if an item was default-highlighted, pressing Enter navigated the user to `/dashboard`.
3. **Empty Form Action Navigation**: In components where an input was wrapped in a form without `@submit.prevent`, standard form submission submitted to `/` and redirected to `/dashboard`.

## 6. Proposed Implementation
1. **Fix `AppLayout.vue` Global Search**:
   - In [`resources/js/layouts/AppLayout.vue`](file:///D:/Projects/day-khata/day-khata-multi-tenant/resources/js/layouts/AppLayout.vue):
     - Update `selectActiveCommand()`:
       ```javascript
       function selectActiveCommand() {
           if (!filteredCommands.value.length || activeIndex.value < 0 || activeIndex.value >= filteredCommands.value.length) {
               return; // Do nothing; NEVER redirect to dashboard on non-matching query!
           }
           const command = filteredCommands.value[activeIndex.value];
           if (command) selectCommand(command);
       }
       ```
     - Clarify the placeholder to `"Navigate pages and actions (Ctrl K)"` to prevent user confusion with table data search.
2. **Add In-Report Search Input across Report Pages**:
   - In all key report pages:
     - `resources/js/pages/Tenant/Reports/SalesRegister.vue`
     - `resources/js/pages/Tenant/Reports/PurchaseRegister.vue`
     - `resources/js/pages/Tenant/Reports/SalesVatBook.vue`
     - `resources/js/pages/Tenant/Reports/PurchaseVatBook.vue`
     - `resources/js/pages/Tenant/Reports/DayBook.vue`
     - `resources/js/pages/Tenant/Reports/CashBook.vue`
     - `resources/js/pages/Tenant/Reports/BankBook.vue`
   - Add a quick search input in the filter bar:
     - `<Input v-model="search" placeholder="Search bill #, voucher #, customer/supplier..." class="w-64" @keydown.enter.prevent="applyFilter" />`
   - In `applyFilter()`: Pass `search: search.value || undefined` in `router.get()` using `preserveState: true`.
3. **Backend Controller Support**:
   - In [`SalesPurchaseReportController.php`](file:///D:/Projects/day-khata/day-khata-multi-tenant/app/Http/Controllers/Tenant/Reports/SalesPurchaseReportController.php) and [`AccountingReportController.php`](file:///D:/Projects/day-khata/day-khata-multi-tenant/app/Http/Controllers/Tenant/Reports/AccountingReportController.php):
     - Accept `$request->string('search')->trim()`.
     - Filter queries by invoice/bill number (`where('invoice_number', 'like', "%{$search}%")`), supplier/customer name, or narration.

## 7. Affected Components
- `resources/js/layouts/AppLayout.vue`
- `resources/js/pages/Tenant/Reports/*.vue` (all report pages)
- `app/Http/Controllers/Tenant/Reports/SalesPurchaseReportController.php`
- `app/Http/Controllers/Tenant/Reports/AccountingReportController.php`

## 8. Dependencies and Risks
- **Dependency**: Client-side TanStack `DataTable` can also support instant instant client-side string filtering for records already loaded in the active page.
- **Risk**: Performance on large datasets.
  - *Mitigation*: Combine server-side date bounds with query parameter filtering so queries remain fast and indexed.

## 9. Testing and Verification
- **Automated Tests**:
  - Test `GET /reports/sales-register?search=INV-001` returns 200 without redirecting to `/dashboard`.
  - Test pressing Enter in search with empty matches does not trigger a redirect.
- **Manual Verification**:
  1. Open `/reports/sales-register`.
  2. Type a bill number or customer name in the search input and press Enter.
  3. Verify the page stays on `/reports/sales-register` and displays the filtered result.
  4. Type in the top navigation search box and press Enter on an unrecognized term; verify you are NOT redirected to the dashboard.

## 10. Completion Criteria
- [ ] No search or filter submission on report pages redirects to `/dashboard`.
- [ ] In-page search input available on report pages for searching by document number and party.
- [ ] Header command palette never triggers a false redirect on non-matching queries.
- [ ] Report filtering remains reactive, maintaining date range and filters.

## 11. Open Questions
- *None*: The bug is fully reproduced and the fix addresses both UI ambiguity and routing safety.
