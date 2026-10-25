# Implementation Plan 05: Add Ledger Viewing to Account Reports

## 1. Requirement Summary
Provide an Account Ledger viewing option directly within the **Account Reports** section:
- Users must be able to view an interactive Ledger report from the Reports navigation menu (`/reports/ledger`).
- The Ledger report must provide an account combobox / autocomplete picker to select any active General Ledger account (including asset, liability, equity, income, expense, customer, and supplier accounts).
- The user must be able to select date ranges in both Bikram Sambat (BS) and Gregorian (AD).
- The report must display Opening Balance, sequential debit/credit transaction entries, transaction narration / voucher reference, running balance, and closing totals.
- The report must support Print and Excel export.

## 2. Current Implementation
- **Existing Ledger View**: [`resources/js/pages/Tenant/Accounting/Accounts/Ledger.vue`](file:///D:/Projects/day-khata/day-khata-multi-tenant/resources/js/pages/Tenant/Accounting/Accounts/Ledger.vue).
- **Existing Routes**: In [`routes/tenant-ledger.php:68–70`](file:///D:/Projects/day-khata/day-khata-multi-tenant/routes/tenant-ledger.php#L68-L70):
  ```php
  Route::get('/accounts/{account}/ledger', [AccountController::class, 'ledger'])->name('accounts.ledger');
  Route::get('/accounts/{account}/ledger/print', [AccountController::class, 'ledgerPrint'])->name('accounts.ledger.print');
  Route::get('/accounts/{account}/ledger/export', [AccountController::class, 'ledgerExport'])->name('accounts.ledger.export');
  ```
- **Existing Controller Logic**: [`AccountController::ledger()`](file:///D:/Projects/day-khata/day-khata-multi-tenant/app/Http/Controllers/Tenant/Accounting/AccountController.php) computes opening balance, Dr/Cr rows, and running balances using `AccountingReportController::accountBook()`.
- **Navigation**: In [`resources/js/lib/nav-items.js`](file:///D:/Projects/day-khata/day-khata-multi-tenant/resources/js/lib/nav-items.js), under `REPORTS -> Accounting Reports`, there is NO "Ledger" link.

## 3. Research Findings
- The existing Ledger is strictly accessible as an account-level drill-down (`/accounts/{account}/ledger`) from the Chart of Accounts list.
- A user navigating to `Account Reports` in the sidebar sees Trial Balance, Income Statement, Balance Sheet, Day Book, Cash Book, and Bank Book, but **cannot view a general Ledger**.
- There is no report page in `resources/js/pages/Tenant/Reports/` dedicated to Ledger viewing with an account selector.

## 4. Legacy Application Reference
- Legacy code in `D:\Projects\day-khata\day_khata`:
  - Route: `GET /viewLedger` -> `reportsController::viewLedger`.
  - Navigation: In `resources/views/index.blade.php:84`, "Ledger" was the **first** menu entry under `Account Reports`:
    ```php
    ['priv' => 'Ledger', 'route' => 'viewLedger', 'label' => 'Ledger']
    ```
  - View: [`resources/views/reports/account/ledger.blade.php`](file:///D:/Projects/day-khata/day_khata/resources/views/reports/account/ledger.blade.php).
  - Features:
    - Account selector dropdown (`name="ledgerName"`).
    - Nepali date range inputs (`from_date`, `to_date`).
    - Opening balance row with Dr/Cr sign.
    - Transaction table with Date, Particulars, Voucher Type, Voucher No., Debit, Credit, Balance (Dr/Cr).
    - Print button opening printable view; Export to Excel button.

## 5. Verified Root Cause
The multi-tenant application implemented the ledger page as an account-scoped child route (`/accounts/{account}/ledger`) tied to the Chart of Accounts administration screen. The general reporting route (`/reports/ledger`) and the sidebar navigation link under `Account Reports` were omitted during the report migration.

## 6. Proposed Implementation
1. **Routes**:
   - In [`routes/tenant-reports-accounting.php`](file:///D:/Projects/day-khata/day-khata-multi-tenant/routes/tenant-reports-accounting.php):
     ```php
     Route::get('/ledger', [AccountingReportController::class, 'ledger'])
         ->middleware('can:account_ledger.view')
         ->name('ledger');
     Route::get('/ledger/print', [AccountingReportController::class, 'ledgerPrint'])
         ->middleware('can:account_ledger.print')
         ->name('ledger.print');
     Route::get('/ledger/export', [AccountingReportController::class, 'ledgerExport'])
         ->middleware('can:account_ledger.export')
         ->name('ledger.export');
     ```
2. **Controller (`AccountingReportController.php`)**:
   - Add `ledger(Request $request)`:
     - Accepts `account_id` (nullable), `from`, `to`.
     - Loads active accounts list for the account selector dropdown.
     - If `account_id` is provided, resolves `Account` and invokes existing `accountBook()` logic to return transactions, opening balance, running balances, and totals.
     - If no `account_id` is selected yet, renders the page with the account combobox and empty table.
   - Add `ledgerPrint(Request $request)` and `ledgerExport(Request $request)`.
3. **Frontend Page**:
   - Create `resources/js/pages/Tenant/Reports/Ledger.vue` (or adapt `Accounts/Ledger.vue` to support standalone account selection):
     - Card with Account Combobox (searchable by account name and code), From Date (BS), To Date (BS), and "Generate Report" button.
     - Upon account selection, loads ledger transactions.
     - DataTable displaying Date (BS / AD), Voucher Type, Voucher #, Narration / Particulars, Debit, Credit, Running Balance (Dr/Cr).
     - Print and Excel export action buttons.
4. **Navigation**:
   - In [`resources/js/lib/nav-items.js`](file:///D:/Projects/day-khata/day-khata-multi-tenant/resources/js/lib/nav-items.js):
     - Add `{ label: 'Ledger', href: '/reports/ledger', icon: BookOpen, permission: 'account_ledger.view' }` under `REPORTS -> Accounting Reports` as the first item.

## 7. Affected Components
- `routes/tenant-reports-accounting.php`
- `app/Http/Controllers/Tenant/Reports/AccountingReportController.php`
- `resources/js/pages/Tenant/Reports/Ledger.vue` (new file)
- `resources/js/lib/nav-items.js`

## 8. Dependencies and Risks
- **Dependency**: Uses existing `AccountingReportController::accountBook()` calculation engine; zero duplication of ledger arithmetic.
- **Risk**: Party accounts (Customer / Supplier subledgers).
  - *Mitigation*: Ensure permission check respects both `account_ledger.view` and `party_ledger.view`, consistent with `AccountController::authorizeLedgerAccess()`.

## 9. Testing and Verification
- **Automated Tests**:
  - In `tests/Feature/Tenant/Reports/AccountingReportTest.php`:
    - Test `GET /reports/ledger` returns 200 with accounts list.
    - Test `GET /reports/ledger?account_id={id}&from=...&to=...` returns correct opening balance, Dr/Cr rows, and running balance.
- **Manual Verification**:
  1. Click `Reports` -> `Accounting Reports` -> `Ledger` in sidebar.
  2. Select an account (e.g., Cash or a Customer account).
  3. Change date range and click "Generate report".
  4. Verify running balances and debit/credit totals match.
  5. Test Print and Excel export.

## 10. Completion Criteria
- [ ] "Ledger" link present in sidebar under Accounting Reports.
- [ ] Route `/reports/ledger` accessible with account selector.
- [ ] Accurate calculation of Opening Balance, transactions, and Closing Balance.
- [ ] Print and Excel export functioning properly.

## 11. Open Questions
- *None*: The requirement directly mirrors legacy functionality and leverages existing ledger posting calculations.
