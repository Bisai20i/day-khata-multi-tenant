# Browser QA Verification Guide (`verify.md`)

This guide provides end-to-end browser verification steps for the 10 client requirements. Follow these steps to verify functionality in a web browser.

---

## Task 10: Restore Capital & Service Option in Purchase

### Overview
Restores clear UI access to **Capital & Service Purchases**, introduces tab filtering by Type (`All Types`, `Capital`, `Service`), updates bill creation with dynamic buttons, and ensures service bills are classified as operational taxable expenses rather than capital fixed asset investments in the Purchase VAT Book.

- **URL / Navigation Path**: `/capital-purchases` via sidebar navigation **TRANSACTIONS** -> **Buying** -> **Capital & Service**.
- **Required Role / User Account**: Tenant Admin or user with `capital_purchases.view` / `capital_purchases.create` permissions.

### Step-by-Step QA Verification Actions

1. **Verify Sidebar Label**:
   - Log in to the tenant application.
   - Look at the sidebar under the **Buying** section.
   - **Expected Result**: The link displays **"Capital & Service"** (formerly "Capital Purchases") with the landmark icon, linking to `/capital-purchases`.

2. **Verify Index Page & Type Segmentation**:
   - Click **"Capital & Service"** in the sidebar.
   - **Expected Result**:
     - The page header displays **"Capital & Service Purchases"**.
     - The description reads: *"Long-term assets and service expenses you bought. Cancel one that was posted in error."*
     - The primary action button displays **"New capital / service purchase"**.
     - The totals card at the bottom displays: **"Total of all capital & service purchases (Rs.)"**.
     - Above the table, verify three segmented tabs:
       - **All Types (X)**
       - **Capital (Y)**
       - **Service (Z)**
   - Click the **"Capital"** tab -> table shows only rows where `Type` is `Capital`.
   - Click the **"Service"** tab -> table shows only rows where `Type` is `Service`.
   - Click **"All Types"** -> table displays all rows.

3. **Verify Bill Creation with Dynamic Action Button**:
   - Click **"New capital / service purchase"**.
   - **Expected Result**: Page header displays **"New capital / service purchase"**.
   - Change the **Purchase type** dropdown from `Capital` to `Service`.
   - **Expected Result**:
     - The "Register as a fixed asset" option is hidden from line items.
     - The submit button at the bottom dynamically changes its label to **"Create service purchase"**.
   - Change **Purchase type** back to `Capital`.
   - **Expected Result**:
     - The "Register as a fixed asset" option appears on line items.
     - The submit button changes back to **"Create capital purchase"**.

4. **Verify Purchase VAT Book Classification (Critical Tax Edge Case)**:
   - Create a service purchase:
     - Type: `Service`
     - Account: an expense account (e.g., Office Rent or Legal Expenses)
     - Amount: `Rs. 5,000.00`
     - Taxable: Ticked (13% VAT)
     - Payment Mode: `Cash`
     - Click **"Create service purchase"**.
   - Navigate to `/reports/purchase-vat-book` via sidebar **REPORTS** -> **VAT Reports** -> **Purchase VAT Book**.
   - Filter by date range containing today's date.
   - Locate the service bill row in the Purchase VAT Book.
   - **Expected Result**:
     - The service bill appears with Taxable Amount `Rs. 5,000.00` and VAT `Rs. 650.00`.
     - The **Capital purchase (Rs.)** column (पूँजीगत खर्च) displays **0.00** (or `-`). It is **NOT** classified as a Capital purchase!
     - The taxable amount correctly sums into operational taxable purchases.


---

## Task 08: Restore Company Setup Workflow

### Overview
Restores full tenant autonomy for configuring company details, logo, invoicing settings, VAT rates, and starting document sequences without requiring platform admin intervention.

- **URL / Navigation Path**: `/settings` via sidebar **ADMIN** -> **Company** -> **Company Setup**, or via the top-right user avatar dropdown -> **Company Setup**.
- **Required Role / User Account**: Tenant Admin or user with `settings.manage` permission (or tenant owner).

### Step-by-Step QA Verification Actions

1. **Verify Navigation Menu & Dropdown Access**:
   - Log in as tenant administrator.
   - Look at the sidebar under **ADMIN**: Verify **"Company Setup"** appears under the "Company" section with the Settings gear icon.
   - Click the user avatar in the top-right header: Verify **"Company Setup"** menu item appears below "My Profile".
   - Click either link to navigate to `/settings`.
   - **Expected Result**: Page loads with title **"Company Setup"** and four tabs:
     - **Company Profile**
     - **Company Logo**
     - **Invoicing & Billing**
     - **Invoice Numbering**

2. **Verify Company Profile Updates (Tab 1)**:
   - In the **Company Profile** tab, update:
     - Company Name: e.g. `Himalayan Traders Pvt. Ltd.`
     - PAN/VAT Number: e.g. `600123456`
     - Address: e.g. `New Road, Kathmandu`
     - Phone: e.g. `01-4220000`
     - Email: e.g. `info@himalayantraders.com`
     - Invoice footer note: e.g. `Goods once sold cannot be returned without original invoice.`
   - Click **"Save changes"**.
   - **Expected Result**: Success toast: *"Company settings updated."* Refresh the page and confirm all values persist.

3. **Verify Company Logo Upload & Removal (Tab 2)**:
   - Switch to the **Company Logo** tab.
   - Choose a JPEG or PNG logo (< 2MB).
   - Verify an instant image preview appears in the UI.
   - Click **"Upload logo"**.
   - **Expected Result**: Success toast: *"Company logo updated."* The uploaded logo remains displayed in the preview card.
   - Open a sales invoice print preview (e.g. at `/sales`) -> verify the new logo appears in the document header.
   - Return to `/settings` -> **Company Logo** tab. Click **"Delete logo"** and confirm.
   - **Expected Result**: Success toast: *"Company logo removed."* Preview resets to empty placeholder.

4. **Verify Invoicing & Billing Configuration (Tab 3)**:
   - Switch to the **Invoicing & Billing** tab.
   - Change Print paper size (e.g. select `Thermal 80mm`).
   - Change Default VAT rate (e.g. `13.00`).
   - Configure invoice prefixes (Full tax: `INV`, Abbreviated: `ABIT`, PAN: `PAN`, Purchase: `PU`, Sales return: `SR`, Purchase return: `PR`).
   - Select default store and toggle negative stock policy.
   - Click **"Save changes"**.
   - **Expected Result**: Settings save successfully with toast notification.
   - **Edge Case - Duplicate Prefix Validation**:
     - Change `purchase_prefix` to `INV` (same as full tax prefix).
     - Click **"Save changes"**.
     - **Expected Result**: Form rejects with field error: *"Each document series needs its own prefix; this one is already used by another series."*

5. **Verify Invoice Numbering Starting Sequence (Tab 4)**:
   - Switch to the **Invoice Numbering** tab.
   - The table displays each series with its current Next Number in the open fiscal year.
   - In an open fiscal year where sales have not yet occurred, click **"Set starting number"** next to Full tax invoice.
   - Enter starting number `1001` and submit.
   - **Expected Result**: Success toast: *"Starting number updated."* Next number updates to `1001`.

6. **Verify Permission Gating (Security Check)**:
   - Log out and log in as a standard cashier/staff account without `settings.manage`.
   - Verify "Company Setup" is **hidden** from both the sidebar and user avatar dropdown.
   - Manually type URL `/settings` in the browser address bar.
   - **Expected Result**: Browser receives HTTP `403 Forbidden` and does not display sensitive settings.

---

## Task 01: Item Active/Inactive Management

### Overview
Replaces the destructive SQL delete button on items with an Active/Inactive status toggle workflow. Inactive items are segmented into an "Inactive Items" tab with clear explanatory guidance, hidden from picker dropdowns (sales, purchase, and stock entry forms), and strictly guarded against deactivation when inventory stock remains on hand.

- **URL / Navigation Path**: `/items` via sidebar navigation **INVENTORY** -> **Items** -> **Items**.
- **Required Role / User Account**: Tenant Admin or user with `items.view` / `items.edit` permissions.

### Step-by-Step QA Verification Actions

1. **Verify Segmented Tabs & Counts**:
   - Log in to the tenant application.
   - Navigate to `/items` via sidebar **INVENTORY** -> **Items**.
   - **Expected Result**:
     - Two segmented tabs appear above the table:
       - **Active Items (X)**
       - **Inactive Items (Y)**
     - Active items tab is selected by default and displays all items where `is_active = true`.
     - The row action menu for active items contains **"Edit"** and **"Mark Inactive"** (power icon button). The destructive SQL "Delete" button is **removed**.

2. **Verify Stock Guard on Deactivation (Stock > 0)**:
   - Identify or create an active item that currently has stock on hand (e.g. In Stock = `10 pcs`).
   - Click the **"Mark Inactive"** button in its row action.
   - **Expected Result**:
     - An alert toast immediately appears: `"{Item Name}" still has 10 pcs in stock. Clear the stock first, or leave the item active.`
     - The item remains active and is NOT marked inactive. No status change occurs.

3. **Verify Deactivation of Zero-Stock Item (Stock == 0)**:
   - Identify or create an active item with `0` stock on hand.
   - Click the **"Mark Inactive"** button in its row action.
   - **Expected Result**:
     - A confirmation dialog appears:
       - Title: *"Mark Item Inactive?"*
       - Message: *"Mark \"{Item Name}\" as inactive? Inactive items are hidden from sales, purchase, and stock entry forms. Historical reports and ledger data remain preserved."*
       - Tone: Danger / confirm button: *"Mark Inactive"*.
   - Click **"Mark Inactive"** in the dialog.
   - **Expected Result**:
     - Success toast appears: `"{Item Name}" marked as inactive.`
     - The item disappears from the "Active Items" tab.
     - The count for **Active Items** decrements by 1; the count for **Inactive Items** increments by 1.

4. **Verify Inactive Items Tab & Banner**:
   - Click the **"Inactive Items"** tab.
   - **Expected Result**:
     - The tab displays an informational banner at the top:
       *"Inactive items are hidden from sales, purchase, and stock entry forms. Historical reports and ledger data remain preserved."*
     - The deactivated item appears in the inactive items table.
     - Its row actions display **"Edit"** and a green **"Reactivate"** button.

5. **Verify Reactivation Workflow**:
   - In the **"Inactive Items"** tab, click **"Reactivate"** on the inactive item.
   - **Expected Result**:
     - A confirmation dialog appears:
       - Title: *"Reactivate Item?"*
       - Message: *"Reactivate \"{Item Name}\"? This item will be restored to sales, purchase, and stock entry forms."*
       - Confirm button: *"Reactivate"*.
   - Confirm reactivation.
   - **Expected Result**:
     - Success toast appears: `"{Item Name}" reactivated successfully.`
     - The item disappears from the "Inactive Items" tab and returns to the "Active Items" tab.
     - Tab counts update dynamically.

6. **Verify Transaction Picker Isolation**:
   - While an item is marked inactive:
     - Navigate to `/sales/create` (New Sale Invoice) or `/pos` (Point of Sale).
     - Search for the item in the product picker / barcode lookup.
     - **Expected Result**: The inactive item does NOT appear in the dropdown or search results.
   - After reactivating the item:
     - Refresh `/sales/create` or `/pos`.
     - **Expected Result**: The item is immediately available again for selection and sale.

7. **Verify Permission Gating (Security Check)**:
   - Log out and log in as a user with only `items.view` permission (without `items.edit`).
   - Navigate to `/items`.
   - **Expected Result**:
     - The "Mark Inactive" and "Reactivate" buttons are hidden.
     - An attempt to send `PATCH /items/{item}/toggle-active` directly returns HTTP `403 Forbidden`.


---

## Task 02: Restore ABIT Bill Option (Abbreviated Tax Invoice)

### Overview
Restores the ability to issue **Abbreviated Tax Invoices (ABIT Bill / संक्षिप्त कर बिजक)** from both the standard Sale Create form and the Point of Sale (POS) interface. Implements statutory IRD Rule 18 compliance with an absolute NPR 10,000.00 bill ceiling, voucher type `SaleAbbreviated`, dedicated numbering prefix (e.g. `AB-`), and tailored IRD print receipts.

- **URL / Navigation Path**: `/sales/create` (New Sale) or `/pos` (Point of Sale).
- **Required Role / User Account**: User with `sales.create` permission (or `pos.view` for POS).

### Step-by-Step QA Verification Actions

1. **Verify ABIT Option in Standard Sales (`/sales/create`)**:
   - Navigate to `/sales/create`.
   - In the **Invoice Type** selector at the top of the form, verify three options:
     - `VAT Invoice (Full Tax)`
     - `ABIT Bill (Abbreviated)`
     - `PAN Invoice`
   - Click **"ABIT Bill (Abbreviated)"**.
   - **Expected Result**:
     - The ABIT button highlights in primary style.
     - Information note reads: *"IRD Abbreviated Bill (Max NPR 10,000.00 ceiling)"*.
     - Standard VAT (13%) is calculated on taxable items up to the NPR 10,000 limit.

2. **Verify ABIT Statutory NPR 10,000 Ceiling Guard on `/sales/create`**:
   - With `ABIT Bill` selected, add an item with total below NPR 10,000 (e.g. 5 pcs @ Rs. 500 = Rs. 2,825.00 with VAT).
   - **Expected Result**: Save buttons ("Save sale", "Save & Print") are enabled.
   - Now increase quantity or add an expensive item so the Grand Total exceeds NPR 10,000.00 (e.g. 10 pcs @ Rs. 1,500 = Rs. 16,950.00).
   - **Expected Result**:
     - A prominent red danger alert appears:
       *"ABIT Bill Exceeds Statutory Limit: Abbreviated tax invoices cannot exceed NPR 10,000.00 under IRD Rule 18. Current total is NPR 16,950.00. Please switch to VAT Invoice or reduce quantities before posting."*
     - The **"Save sale"**, **"Save & Print"**, and **"Estimate"** buttons are disabled (`disabled="true"`).
     - Switching back to **"VAT Invoice (Full Tax)"** immediately removes the warning banner and re-enables the Save buttons.

3. **Verify ABIT Option in Point of Sale (`/pos`)**:
   - Navigate to `/pos`.
   - Look at the top bar right after the date picker:
     - Verify three toggle buttons: `[TAX]`, `[ABIT]`, `[PAN]`.
   - Click the **`[ABIT]`** button.
   - **Expected Result**: `[ABIT]` highlights in active primary blue/purple.
   - Add items exceeding NPR 10,000 to the cart:
     - Look at the bottom payment panel.
     - **Expected Result**: The "Complete sale" checkout button is disabled with status message:
       *"ABIT bills cannot exceed NPR 10,000.00 per IRD regulations. Please switch to TAX invoice."*
   - Reduce the cart total under NPR 10,000 (e.g. NPR 2,500.00).
   - **Expected Result**: The checkout button is active and enabled.
   - Complete checkout with Cash payment.
   - **Expected Result**: Sale is successfully created. In the sales register (`/sales`), the invoice number begins with the abbreviated prefix (default `AB-`), and voucher type is `SaleAbbreviated`.

4. **Verify ABIT Print Layout**:
   - Open the completed ABIT sale's print view (`/sales/{id}/print`).
   - **Expected Result**:
     - Document heading displays **"Abbreviated Tax Invoice"** (संक्षिप्त कर बिजक).
     - Shows buyer details as walk-in / unregistered without requiring buyer PAN.


---

## Task 03: Restore VAT Invoice Option (Full Tax Invoice)

### Overview
Restores the default **VAT Invoice (Full Tax Invoice / कर बिजक)** option for registered tax transactions. Enforces standard 13% Output VAT calculation on vatable items, supports 9-digit buyer PAN recording, assigns voucher type `Sale`, and uses standard invoice prefix sequence (default `SL-` or `INV-`).

- **URL / Navigation Path**: `/sales/create` or `/pos`.
- **Required Role / User Account**: User with `sales.create` permission.

### Step-by-Step QA Verification Actions

1. **Verify Default Selection**:
   - Navigate to `/sales/create`.
   - **Expected Result**: **"VAT Invoice (Full Tax)"** is selected by default (unless company settings specify another active type).
   - Info caption reads: *"Standard VAT invoice (13.00% Tax on vatable items)"*.

2. **Verify 13% VAT Calculation on Vatable vs Non-Vatable Items**:
   - Add an item marked as vatable (e.g., 2 pcs @ Rs. 1,000 = Rs. 2,000).
   - Add an item marked as non-vatable / exempt (e.g., 1 pcs @ Rs. 500 = Rs. 500).
   - Inspect the live totals bar at the bottom:
     - Subtotal: `Rs. 2,500.00`
     - Taxable amount: `Rs. 2,000.00`
     - Non-taxable amount: `Rs. 500.00`
     - VAT (13%): `Rs. 260.00`
     - Total: `Rs. 2,760.00`
   - **Expected Result**: Only the vatable item incurs 13% VAT; non-taxable amount is accurately segregated.

3. **Verify Buyer PAN Recording**:
   - Select a customer with a 9-digit PAN/TPIN (e.g. `123456789`).
   - Complete the sale.
   - Open print view (`/sales/{id}/print`).
   - **Expected Result**:
     - Header displays **"TAX INVOICE"** (कर बिजक).
     - Buyer PAN: `123456789`.
     - 13% Taxable, Non-taxable, and VAT rows clearly broken down.
   - In `/reports/sales-vat-book`:
     - The bill appears with its buyer PAN, taxable base, and VAT amount correctly reflected.

4. **Verify No Limit on Full Tax Invoices**:
   - On `/sales/create` with `VAT Invoice (Full Tax)` selected, enter an amount exceeding NPR 10,000 (e.g. Rs. 500,000).
   - **Expected Result**: No ceiling warning is shown; posting completes normally.


---

## Task 04: Restore PAN Invoice Option (Non-VAT Invoice)

### Overview
Restores the **PAN Invoice (Non-VAT Invoice / पान बिजक)** option for businesses operating under PAN registration without VAT. Enforces 0.00% VAT across all lines (regardless of item default vatable status), prevents any posting to Output VAT liability (`LIA20`), assigns voucher type `SalePan`, and uses dedicated prefix (default `PAN-`).

- **URL / Navigation Path**: `/sales/create` or `/pos`.
- **Required Role / User Account**: User with `sales.create` permission.

### Step-by-Step QA Verification Actions

1. **Verify Selection & Zero-VAT Enforcement on `/sales/create`**:
   - Navigate to `/sales/create`.
   - Click **"PAN Invoice"** in the Invoice Type selector.
   - **Expected Result**:
     - Information note reads: *"Non-VAT PAN invoice (0.00% Tax enforced)"*.
     - In the live totals table at the bottom, the VAT column header displays: **"VAT (0% PAN)"**.
   - Add an item that is normally configured as vatable (e.g. 2 pcs @ Rs. 1,000).
   - Observe the totals bar:
     - Subtotal: `Rs. 2,000.00`
     - Taxable amount: `Rs. 0.00`
     - Non-taxable amount: `Rs. 2,000.00`
     - VAT: `Rs. 0.00`
     - Total to pay: `Rs. 2,000.00`
   - **Expected Result**: All lines are treated as non-taxable; exactly 0.00 VAT is charged.

2. **Verify PAN Toggle in POS (`/pos`)**:
   - Navigate to `/pos`.
   - Click the **`[PAN]`** toggle in the top navigation bar.
   - Add items to the cart.
   - Inspect the cart summary in the payment panel:
     - VAT row is hidden or displays `0.00`.
     - Subtotal equals Grand total.
   - Complete checkout.
   - **Expected Result**:
     - The sale is posted with `invoice_type = pan`.
     - Invoice number uses the PAN prefix (default `PAN-`).

3. **Verify Ledger & Accounting Posting (Integrity Check)**:
   - Navigate to `/sales` and click on the newly posted PAN invoice to view details or journal voucher.
   - **Expected Result**:
     - Journal Voucher type is `SalePan`.
     - Voucher lines:
       - Debit: Customer / Cash / Bank for the total amount.
       - Credit: Sales Revenue account for the total amount.
       - **Output VAT account (LIA20) has ZERO entries.** No tax liability is accrued!

4. **Verify PAN Invoice Print Layout**:
   - Open print view (`/sales/{id}/print`).
   - **Expected Result**:
     - Document title reads **"INVOICE"** or **"PAN INVOICE"** (पान बिजक).
     - The VAT 13% tax summary table is omitted or shows `0.00%`.


---

## Task 05: Add Ledger Viewing to Account Reports

### Overview
Restores direct access to a dedicated General Ledger report from the **Account Reports** navigation section (`/reports/ledger`). Provides an account search combobox to select any active General Ledger account (assets, liabilities, equity, revenues, expenses, debtors, and creditors), fiscal year and Nepali date range filters, opening balance calculation, running balances, and Print/Excel export options.

- **URL / Navigation Path**: `/reports/ledger` via sidebar navigation **REPORTS** -> **Account Reports** -> **Ledger** (first item).
- **Required Role / User Account**: User with `account_ledger.view` permission (or `account_ledger.print` / `account_ledger.export` for export actions).

### Step-by-Step QA Verification Actions

1. **Verify Navigation Menu Access**:
   - Log in to the tenant application.
   - Look at the sidebar under **REPORTS** -> **Account Reports**.
   - **Expected Result**: **"Ledger"** appears with the BookOpen icon as the **very first item** in the Account Reports category, above "Trial Balance".
   - Click **"Ledger"**.
   - **Expected Result**: Page navigates to `/reports/ledger` with PageHeader title **"General Ledger"**.

2. **Verify Account Selector & Initial State**:
   - On initial page load with no account selected:
     - **Expected Result**: Displays an empty state card: *"No account selected. Pick an account from the dropdown above to view its general ledger entries."*
   - Click the **"Select Account"** combobox:
     - Type an account code or name (e.g. `Cash` or `REC` or `Sales`).
     - **Expected Result**: Dropdown filters and highlights matching accounts with their code and name.

3. **Verify Ledger Calculations (Opening Balance, Debits, Credits, Running Balance)**:
   - Select an account that has transaction history (e.g., `Main Cash` or a customer debtor account).
   - Click **"Filter Ledger"** (or change date range).
   - **Expected Result**:
     - The account summary bar displays 4 cards:
       - **Account Name & Code**: e.g., `Cash in Hand (AS-101)`
       - **Opening Balance**: Calculated up to the start of the selected date range, clearly badged with `Dr` or `Cr`.
       - **Period Debits / Credits**: Exact total of debits and credits within the period.
       - **Closing Balance**: Running ending balance at the close of the period with `Dr` or `Cr`.
     - The transaction table displays:
       - Date (BS) and Date (AD)
       - Voucher Type and Voucher Number (e.g. `Sale Invoice #SL-101`, `Receipt #12`)
       - Narration / Particulars
       - Debit (Rs.)
       - Credit (Rs.)
       - Running Balance (Rs.) dynamically updated row by row with Dr/Cr sign.

4. **Verify Print and Excel Exports**:
   - With an account selected, click **"Print ledger"**.
     - **Expected Result**: Opens a new browser tab streaming the printable PDF ledger (`/reports/ledger/print?...`) with company letterhead, opening balance, and transaction history.
   - Click **"Export to Excel"**.
     - **Expected Result**: Downloads `.xlsx` file formatted with 2-decimal numbers and summary totals.


---

## Task 09: Correct VAT, Sales, and Purchase Report Formats

### Overview
Standardizes all VAT, Sales, and Purchase registers to adhere to statutory Nepal IRD Schedule formats and business accounting standards:
1. Adds the missing **Sub Total** column (कुल रकम / Total Before Tax = Taxable + Non-Taxable).
2. Enforces the exact 5-column sequence: **`Sub Total` -> `Non-Taxable` -> `Taxable` -> `VAT` -> `Total`**.
3. Fixes the mislabeled `"Capital purchase amount"` column on `SalesVatBook.vue` to `"Capital sales amount"`.
4. Aligns Excel exports to match the identical column structure and mathematical formulas.

- **URL / Navigation Path**:
  - `/reports/sales-register` (Sales Register)
  - `/reports/purchase-register` (Purchase Register)
  - `/reports/sales-vat-book` (Sales VAT Book)
  - `/reports/purchase-vat-book` (Purchase VAT Book)
  - `/reports/sales-return-register` (Sales Return Register / Credit Notes)
  - `/reports/purchase-return-register` (Purchase Return Register / Debit Notes)
- **Required Role / User Account**: User with `tax_reports.view`, `sales_reports.view`, or `purchase_reports.view`.

### Step-by-Step QA Verification Actions

1. **Verify Sales Register (`/reports/sales-register`)**:
   - Navigate to `/reports/sales-register`.
   - Inspect table headers from left to right after the customer column:
     - **Expected Order**: **`Sub Total`** -> **`Non-Taxable`** -> **`Taxable`** -> **`VAT`** -> **`Total amount`**.
   - Check the figures on any row:
     - Verify: $\text{Sub Total} = \text{Non-Taxable} + \text{Taxable}$.
     - Verify: $\text{Total amount} = \text{Sub Total} + \text{VAT}$.
   - Inspect the summary totals bar at the bottom:
     - **Expected Result**: Displays `Sub Total` -> `Non-Taxable` -> `Taxable` -> `VAT` -> `Total amount`.

2. **Verify Purchase Register (`/reports/purchase-register`)**:
   - Navigate to `/reports/purchase-register`.
   - Inspect table headers after PAN column:
     - **Expected Order**: **`Sub Total`** -> **`Non-Taxable`** -> **`Taxable`** -> **`VAT`** -> **`Total amount`**.
   - Inspect footer totals:
     - **Expected Result**: Displays exact matching sums for Sub Total, Non-Taxable, Taxable, VAT, and Total.

3. **Verify Sales VAT Book (`/reports/sales-vat-book`)**:
   - Navigate to `/reports/sales-vat-book`.
   - Inspect column order:
     - **Expected Order**: `Sub Total` -> `Non-Taxable` -> `Taxable amount` -> `VAT` -> **`Capital sales amount`** -> `Total amount`.
   - **Crucial Text Verification**: Verify column header reads **"Capital sales amount"** (NOT "Capital purchase amount").
   - In footer totals: Verify label reads **"Capital sales:"**.

4. **Verify Purchase VAT Book (`/reports/purchase-vat-book`)**:
   - Navigate to `/reports/purchase-vat-book`.
   - Inspect column order:
     - **Expected Order**: `Sub Total` -> `Non-Taxable` -> `Taxable amount` -> `VAT` -> `Capital purchase amount` -> `Fixed-asset VAT (of which)` -> `Total amount`.
   - Footer totals: Sub Total, Non-Taxable, Taxable, VAT, Capital purchases, Fixed-asset VAT, and Total amount.

5. **Verify Sales Return & Purchase Return Registers**:
   - Navigate to `/reports/sales-return-register` and `/reports/purchase-return-register`.
   - **Expected Order**: `Sub Total` -> `Non-Taxable` -> `Taxable amount` -> `VAT` -> `Total amount`.

6. **Verify Excel Exports**:
   - On `/reports/sales-vat-book`, click **"Export to Excel"**.
   - Open downloaded `.xlsx` file:
     - **Expected Result**: Column headings contain `Sub Total`, `Non-Taxable`, `Taxable`, `VAT`, `Capital`, `Total`.


---

## Task 07: Fix Report Search Functionality Redirecting to Dashboard

### Overview
Eliminates the defect where searching on a report page inadvertently redirected users to the dashboard (`/dashboard`):
1. Hardens `AppLayout.vue`'s command palette: Pressing Enter on an unmatched query or empty results no longer executes `selectActiveCommand()` or redirects to the dashboard.
2. Changes top search placeholder to `"Navigate pages and actions (Ctrl K)"` to clearly signal navigation purposes.
3. Adds dedicated in-report search inputs directly on report pages (Sales Register, Purchase Register, etc.) for searching by bill #, voucher #, customer, or supplier.

- **URL / Navigation Path**: `/reports/sales-register`, `/reports/purchase-register`, or any report page.
- **Required Role / User Account**: Any authenticated user.

### Step-by-Step QA Verification Actions

1. **Verify Global Header Search No Longer Redirects**:
   - Navigate to `/reports/sales-register`.
   - Click into the top navigation search bar in the header (placeholder: `"Navigate pages and actions (Ctrl K)"`).
   - Type a random document number that does not match any page name (e.g. `INV-999999` or `XYZ123`).
   - Press `Enter`.
   - **Expected Result**:
     - The page **STAYS** on `/reports/sales-register`.
     - The browser **DOES NOT REDIRECT** to `/dashboard`!
     - The command popup displays `"No results for \"INV-999999\""`.

2. **Verify In-Report Search Input on Sales Register**:
   - On `/reports/sales-register`, look at the filter panel:
     - Notice the dedicated **Search** input (`placeholder="Invoice # or customer..."`).
   - Type an existing invoice number (e.g., `SL-` or customer name) and press `Enter` or click "Generate report".
   - **Expected Result**:
     - The report table updates in place, showing only sales matching the query.
     - URL updates to `/reports/sales-register?...&search=...` while remaining strictly on the report page.
   - Type a non-existent search term (e.g., `NonExistentInvoice123`) and press `Enter`.
   - **Expected Result**:
     - Table shows: `"No records for this period or filter. Try widening the date range."`
     - You remain on the Sales Register page.
   - Click **"Reset"**: Search input clears, and all period records reappear.

3. **Verify In-Report Search Input on Purchase Register**:
   - Navigate to `/reports/purchase-register`.
   - In the filter card, find **Search** (`placeholder="Bill # or supplier..."`).
   - Type a supplier name or bill number and press `Enter`.
   - **Expected Result**: Filters in place without leaving the page.


---

## Task 06: Excel Sample Formats for All Import Features

### Overview
Restores downloadable Excel sample formats (`.xlsx`) and dual CSV/Excel template support for all bulk import features across the system:
1. **Customer Import** (`/customers`)
2. **Supplier Import** (`/suppliers`)
3. **Item / Inventory Import** (`/items`)
4. **Opening Stock Import** (`/stock-adjustments`)
5. **Opening Balance Import** (`/accounts`)

Each sample Excel file includes proper column headers matching backend importer specifications, realistic sample rows, and clear data types. The upload handlers now accept both `.xlsx` (via PhpSpreadsheet / Maatwebsite) and `.csv` formats without requiring manual file conversions.

- **URL / Navigation Path**:
  - Customers: `/customers` -> Click **"Import"** button
  - Suppliers: `/suppliers` -> Click **"Import"** button
  - Items: `/items` -> Click **"Import"** button
  - Opening Stock: `/stock-adjustments` -> Click **"Opening stock"** -> **"Import"**
  - Opening Balance: `/accounts` -> Click **"Opening balances"** -> **"Import"**
- **Required Role / User Account**: User with corresponding import permission (`customers.import`, `suppliers.import`, `items.import`, `opening_stock.import`, or `opening_balances.import`).

### Step-by-Step QA Verification Actions

1. **Verify Customer Import Sample Excel**:
   - Navigate to `/customers` and click **"Import"**.
   - **Expected Result**: Modal opens showing two buttons:
     - **"Download Sample Excel (.xlsx)"** (primary styled button)
     - **"Download CSV template"**
   - Click **"Download Sample Excel (.xlsx)"**.
   - **Expected Result**: Browser downloads `customer-import-sample.xlsx`.
   - Open in Excel: Contains columns `name`, `address`, `mobile_no`, `email`, `tpin`, `citizenship` and 2 sample rows.
   - File input accepts `.xlsx`, `.xls`, and `.csv`.

2. **Verify Supplier Import Sample Excel**:
   - Navigate to `/suppliers` and click **"Import"**.
   - **Expected Result**: Modal displays **"Download Sample Excel (.xlsx)"** and **"Download CSV template"**.
   - Click **"Download Sample Excel (.xlsx)"**.
   - **Expected Result**: Downloads `supplier-import-sample.xlsx`. Contains columns `name`, `address`, `mobile_no`, `email`, `tpin`.

3. **Verify Item Import Sample Excel**:
   - Navigate to `/items` and click **"Import"**.
   - **Expected Result**: Modal displays **"Download Sample Excel (.xlsx)"**.
   - Click **"Download Sample Excel (.xlsx)"**.
   - **Expected Result**: Downloads `item-import-sample.xlsx`.
   - Open in Excel: Contains 12 standard columns: `name`, `category`, `subcategory`, `unit`, `hs_code`, `barcode`, `min_stock`, `purchase_rate`, `sale_rate`, `is_vatable`, `is_stockable`, `posting_account`.

4. **Verify Opening Stock Import Sample Excel**:
   - Navigate to `/stock-adjustments` -> click **"Import opening stock"**.
   - **Expected Result**: Modal displays **"Download Sample Excel (.xlsx)"** and **"Download CSV template"**.
   - Click **"Download Sample Excel (.xlsx)"**.
   - **Expected Result**: Downloads `opening-stock-import-sample.xlsx` with columns `item`, `quantity`, `unit_cost_rate`, `note`.

5. **Verify Opening Balance Import Sample Excel**:
   - Navigate to `/accounts` -> click **"Import opening balances"**.
   - **Expected Result**: Modal displays **"Download Sample Excel (.xlsx)"** and **"Download CSV template"**.
   - Click **"Download Sample Excel (.xlsx)"**.
   - **Expected Result**: Downloads `opening-balance-import-sample.xlsx` with columns `code`, `name`, `debit`, `credit` with balanced example lines.

6. **Verify End-to-End Excel Upload**:
   - In any of the import modals (e.g. Customers), select the downloaded `.xlsx` sample file directly into the file picker.
   - Click **"Upload & import"**.
   - **Expected Result**: Importer successfully parses the `.xlsx` file and imports rows without format or MIME-type errors.
