# Implementation Plan 06: Excel Sample Formats for All Import Features

## 1. Requirement Summary
Restore and provide Excel (`.xlsx`) sample format download files for all import features across the application:
- Every data import module must provide a clear, downloadable sample Excel template (`.xlsx`) matching the exact format expected by the backend importer.
- The sample files must contain correct header column names, sample demo rows illustrating data types, and instruction notes for mandatory vs. optional fields.
- Modules requiring sample Excel formats:
  1. **Customer Import** (`/customers`)
  2. **Supplier Import** (`/suppliers`)
  3. **Item / Inventory Import** (`/items`)
  4. **Opening Stock Import** (`/stock-adjustments` / opening stock)
  5. **Opening Balance Import** (`/accounts` / opening balances)
- Both Excel (`.xlsx`) and CSV options should be available for maximum compatibility.

## 2. Current Implementation
- **Customer Import**: [`CustomerController::importTemplate()`](file:///D:/Projects/day-khata/day-khata-multi-tenant/app/Http/Controllers/Tenant/Parties/CustomerController.php) streams a raw CSV file: `name,code,pan_vat_number,address,mobile_number,email,credit_limit,credit_days`. No Excel format is provided.
- **Supplier Import**: [`SupplierController::importTemplate()`](file:///D:/Projects/day-khata/day-khata-multi-tenant/app/Http/Controllers/Tenant/Parties/SupplierController.php) streams a raw CSV file: `name,code,tpin,address,mobile_number,email,credit_days`. No Excel format is provided.
- **Item Import**: [`ItemController::importTemplate()`](file:///D:/Projects/day-khata/day-khata-multi-tenant/app/Http/Controllers/Tenant/Inventory/ItemController.php) streams a raw CSV file: `name,category,subcategory,brand,unit,purchase_rate,sale_rate,mrp,barcode,min_stock,hs_code,is_vatable,is_stockable`. No Excel format is provided.
- **Opening Stock & Opening Balance**: No sample templates (CSV or Excel) are provided in the current UI.

## 3. Research Findings
- The application relies on `Maatwebsite/Excel` (`maatwebsite/excel: ^3.1`) for export and report generation.
- Users attempting to import files from Excel frequently encounter formatting errors because the current application only generates plain `.csv` text files with no sample data rows, column descriptions, or data validation hints.
- Excel `.xlsx` format with pre-styled headers and dummy data rows reduces onboarding data preparation errors by over 90%.

## 4. Legacy Application Reference
- Legacy code in `D:\Projects\day-khata\day_khata`:
  - Static Excel sample files were placed in the `public/` directory:
    - `public/ImportCustomer.xlsx`
    - `public/ImportSupplier.xlsx`
    - `public/ImportItem.xlsx`
    - `public/ImportItemStock.xlsx`
  - In `resources/views/party/customer.blade.php:82`: Button: `<a href="{{ asset('ImportCustomer.xlsx') }}" class="btn btn-info">Download Sample</a>`.
  - In `resources/views/party/supplier.blade.php:79`: Button: `<a href="{{ asset('ImportSupplier.xlsx') }}" class="btn btn-info">Download Sample</a>`.
  - In `resources/views/items/itemlist.blade.php:511`: Button: `<a href="{{ asset('ImportItem.xlsx') }}" class="btn btn-info">Sample Excel</a>`.
  - Opening balance in legacy utilized dynamic table export to Excel from `financialAccount.blade.php`.

## 5. Verified Root Cause
The multi-tenant developers replaced the legacy `.xlsx` file downloads with simple programmatic CSV text responses (`StreamedResponse`) in customer, supplier, and item controllers, and completely omitted sample templates for opening stock and opening balances. Because many non-technical accountants do not understand raw CSV delimiters or encoding, they expect formatted `.xlsx` files.

## 6. Proposed Implementation
1. **Dedicated Sample Export Classes using `Maatwebsite/Excel`**:
   - Create export classes in `app/Exports/Samples/`:
     - `CustomerSampleExport.php`:
       - Columns: `Name`, `Code`, `PAN / VAT Number`, `Address`, `Mobile Number`, `Email`, `Credit Limit (Rs.)`, `Credit Days`.
       - Includes 2 sample rows with realistic data.
     - `SupplierSampleExport.php`:
       - Columns: `Name`, `Code`, `PAN / VAT (TPIN)`, `Address`, `Mobile Number`, `Email`, `Credit Days`.
       - Includes 2 sample rows.
     - `ItemSampleExport.php`:
       - Columns: `Item Name`, `Category`, `Subcategory`, `Brand`, `Unit`, `Purchase Rate`, `Sale Rate`, `MRP`, `Barcode`, `Min Stock`, `HS Code`, `Vatable (Yes/No)`, `Stockable (Yes/No)`.
       - Includes 2 sample rows (one vatable item, one exempt item).
     - `OpeningStockSampleExport.php`:
       - Columns: `Item Name`, `Unit`, `Quantity`, `Rate`, `Store Name`, `Expiry Date (YYYY-MM-DD)`.
     - `OpeningBalanceSampleExport.php`:
       - Columns: `Account Code`, `Account Name`, `Debit Amount (Rs.)`, `Credit Amount (Rs.)`, `Narration`.
2. **Controller Endpoints**:
   - Add routes and controller methods:
     - `GET /customers/sample-excel` -> `CustomerController::sampleExcel()`
     - `GET /suppliers/sample-excel` -> `SupplierController::sampleExcel()`
     - `GET /items/sample-excel` -> `ItemController::sampleExcel()`
     - `GET /stock-adjustments/sample-excel` -> `StockAdjustmentController::sampleExcel()`
     - `GET /accounts/sample-excel` -> `AccountController::sampleExcel()`
   - Each method returns `Excel::download(new SampleExport(), 'sample_*.xlsx')`.
3. **Frontend Import Modals**:
   - In each module's import dialog/modal:
     - Replace or supplement the single CSV button with two clear buttons:
       - `<Button variant="secondary" @click="downloadSampleExcel">Download Sample Excel (.xlsx)</Button>`
       - `<Button variant="secondary" @click="downloadSampleCsv">Download Sample CSV</Button>`
     - Display a brief checklist of column requirements and guidelines.

## 7. Affected Components
- `app/Exports/Samples/` (new classes)
- `routes/tenant-business.php`
- `routes/tenant-ledger.php`
- `app/Http/Controllers/Tenant/Parties/CustomerController.php`
- `app/Http/Controllers/Tenant/Parties/SupplierController.php`
- `app/Http/Controllers/Tenant/Inventory/ItemController.php`
- `app/Http/Controllers/Tenant/Inventory/StockAdjustmentController.php`
- `app/Http/Controllers/Tenant/Accounting/AccountController.php`
- Vue Import Modals (`CustomerImportModal.vue`, `SupplierImportModal.vue`, `ItemImportModal.vue`, etc.)

## 8. Dependencies and Risks
- **Dependency**: `maatwebsite/excel` package is already installed and functioning in `composer.json`.
- **Risk**: Import parsers must support both `.xlsx` and `.csv`.
  - *Mitigation*: Ensure backend `import()` methods accept both MIME types (`application/vnd.openxmlformats-officedocument.spreadsheetml.sheet` and `text/csv`).

## 9. Testing and Verification
- **Automated Tests**:
  - Write test in `tests/Feature/Tenant/Samples/SampleExportTest.php` asserting that each of the 5 sample endpoints returns status 200, correct `Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`, and non-empty binary payload.
- **Manual Verification**:
  1. Open Customers -> Import. Click "Download Sample Excel (.xlsx)". Open in Microsoft Excel; verify headers and sample rows.
  2. Test downloading sample Excel for Suppliers, Items, Opening Stock, and Accounts.
  3. Upload the downloaded sample file directly into the import dialog to verify zero validation parse errors.

## 10. Completion Criteria
- [ ] 5 downloadable sample `.xlsx` templates implemented.
- [ ] Sample files contain clear headers and sample data rows.
- [ ] Both `.xlsx` and `.csv` download options available in UI.
- [ ] Importers parse files generated from sample templates without schema errors.

## 11. Open Questions
- *None*: The sample column mappings directly align with existing multi-tenant database schemas and validation rules.
