# Implementation Plan 08: Restore Company Setup Workflow

## 1. Requirement Summary
Restore the **Company Setup Workflow** for tenant administrators within the multi-tenant application:
- Tenant administrators must be able to view and manage their own company profile and business configuration directly from the tenant application interface.
- Required settings include:
  - **Company Profile**: Business Name, Address, Contact Phone, Email, PAN/VAT Number, Currency Symbol.
  - **Company Logo**: Upload, preview, and update company logo used on invoice and bill printouts.
  - **Invoicing Settings**: Default VAT rate, Invoice number prefixes (`sale_full_prefix`, `sale_abbreviated_prefix`, `sale_pan_prefix`), and enabled billing options (VAT Invoice, ABIT Bill, PAN Invoice).
  - **Bill Customization**: Print paper size (A4 / Thermal / Letter), Invoice footer notes, Terms and conditions.
- The company setup option must be accessible from the navigation menu under `ADMIN` and from the user profile dropdown in the top header.

## 2. Current Implementation
- **Current State in Tenant App**: Completely missing!
  - In commit `aa5e89d` ("migrated tenant settings to the super adming"), the developer deleted:
    - `routes/tenant-settings.php`
    - `app/Http/Controllers/Tenant/Admin/SettingsController.php`
    - `resources/js/pages/Tenant/Admin/Settings/Edit.vue`
  - And removed `{ label: 'Settings', href: '/settings' }` from `resources/js/lib/nav-items.js`.
- **Current Settings Administration**:
  - Exists ONLY in the platform admin central panel: [`app/Http/Controllers/Central/Tenants/TenantCompanySettingController.php`](file:///D:/Projects/day-khata/day-khata-multi-tenant/app/Http/Controllers/Central/Tenants/TenantCompanySettingController.php) and [`resources/js/pages/Central/Tenants/Settings/Edit.vue`](file:///D:/Projects/day-khata/day-khata-multi-tenant/resources/js/pages/Central/Tenants/Settings/Edit.vue).
  - The docblock in `TenantCompanySettingController.php` explicitly notes:
    > *"tenants have no access to their own settings any more (see the now-deleted App\Http\Controllers\Tenant\Admin\SettingsController and routes/tenant-settings.php)"*.
- **Database Model**:
  - [`app/Models/CompanySetting.php`](file:///D:/Projects/day-khata/day-khata-multi-tenant/app/Models/CompanySetting.php) is already a tenant-scoped singleton (`CompanySetting::current()`) storing: `company_name`, `pan_number`, `phone`, `email`, `address`, `logo_path`, `invoice_footer_note`, `default_vat_rate`, `print_paper_size`, and prefixes.

## 3. Research Findings
- The decision in commit `aa5e89d` to strip tenant admins of the ability to configure their own company details contradicts normal SaaS and accounting expectations.
- A business owner cannot update their own phone number, address, bill footer, logo, or invoicing prefix without contacting the system super-administrator.
- The underlying database schema and singleton model `CompanySetting` are completely intact and tenant-aware.

## 4. Legacy Application Reference
- Legacy code in `D:\Projects\day-khata\day_khata`:
  - Controller: `SettingsController.php` / `CompanyInfoController.php`.
  - Routes: `GET /settings`, `POST /update-company-info`.
  - View: `resources/views/settings/company-info.blade.php`.
  - Accessible to business owners and admins via `Settings` -> `Company Info`.
  - Managed company name, PAN/VAT, address, phone, logo upload, and bill invoice footer message.

## 5. Verified Root Cause
An aggressive refactoring pass mistakenly assumed that because the platform administrator manages tenant subscriptions and provisioning, tenant administrators should be locked out of editing their own company profile and billing settings. The routes and Vue screens were deleted from the tenant workspace.

## 6. Proposed Implementation
1. **Routes**:
   - Create `routes/tenant-settings.php` and require it inside `routes/tenant.php` (under `auth:web` and tenant domain middleware):
     ```php
     Route::prefix('settings')->name('tenant.settings.')->group(function () {
         Route::get('/', [CompanySettingController::class, 'edit'])
             ->middleware('can:settings.manage')
             ->name('edit');
         Route::put('/', [CompanySettingController::class, 'update'])
             ->middleware('can:settings.manage')
             ->name('update');
         Route::post('/logo', [CompanySettingController::class, 'uploadLogo'])
             ->middleware('can:settings.manage')
             ->name('logo.upload');
         Route::delete('/logo', [CompanySettingController::class, 'deleteLogo'])
             ->middleware('can:settings.manage')
             ->name('logo.delete');
     });
     ```
2. **Permissions**:
   - In [`config/permissions.php`](file:///D:/Projects/day-khata/day-khata-multi-tenant/config/permissions.php):
     - Ensure `'settings.manage'` is defined under the `core` module and granted to tenant `admin` / owner roles.
3. **Controller**:
   - Create `app/Http/Controllers/Tenant/Admin/CompanySettingController.php`:
     - `edit()`: Returns `Inertia::render('Tenant/Admin/Settings/Edit', ['settings' => CompanySetting::current()])`.
     - `update(UpdateCompanySettingRequest $request)`: Validates and updates `CompanySetting::current()`.
     - `uploadLogo()` & `deleteLogo()`: Stores logo on public disk under `tenant-logos/{tenant_id}/` (mirroring `ItemController`).
4. **Frontend View**:
   - Create `resources/js/pages/Tenant/Admin/Settings/Edit.vue` matching the modern UI design system:
     - **Tab 1: General Profile**: Company Name, Trade Name, PAN/VAT Number, Email, Phone, Address, Currency.
     - **Tab 2: Company Logo**: File uploader with instant image preview and delete logo option.
     - **Tab 3: Invoicing & Billing**: Invoice Number Prefixes (Full Tax, ABIT, PAN, Returns), Default VAT Rate (13%), Paper Size (A4, 80mm Thermal, 58mm Thermal, Letter), Invoice Footer Note.
5. **Navigation**:
   - In [`resources/js/lib/nav-items.js`](file:///D:/Projects/day-khata/day-khata-multi-tenant/resources/js/lib/nav-items.js):
     - Add `{ label: 'Company Setup', href: '/settings', icon: Settings, permission: 'settings.manage' }` under `ADMIN`.
   - In [`resources/js/layouts/AppLayout.vue`](file:///D:/Projects/day-khata/day-khata-multi-tenant/resources/js/layouts/AppLayout.vue):
     - In the user avatar dropdown menu (lines 550–564), add a `"Company Setup"` menu item leading to `/settings`.

## 7. Affected Components
- `routes/tenant.php`
- `routes/tenant-settings.php` (new file)
- `config/permissions.php`
- `app/Http/Controllers/Tenant/Admin/CompanySettingController.php` (new file)
- `app/Http/Requests/Tenant/UpdateCompanySettingRequest.php` (new file)
- `resources/js/pages/Tenant/Admin/Settings/Edit.vue` (new file)
- `resources/js/lib/nav-items.js`
- `resources/js/layouts/AppLayout.vue`

## 8. Dependencies and Risks
- **Dependency**: `CompanySetting` singleton model and database schema already exist with all necessary columns.
- **Risk**: Tenant isolation for logo uploads.
  - *Mitigation*: Strictly namespace logo storage by tenant identifier (`tenant-logos/{tenant_id}/`) to ensure complete asset isolation.

## 9. Testing and Verification
- **Automated Tests**:
  - Create `tests/Feature/Tenant/Admin/CompanySettingTest.php`:
    - Test tenant admin can view `/settings`.
    - Test updating company name, PAN, and address persists in tenant DB.
    - Test uploading logo updates `logo_path` and deletes old file.
    - Test non-admin user without `settings.manage` receives 403 Forbidden.
- **Manual Verification**:
  1. Login as tenant admin, click "Company Setup" from sidebar or profile dropdown.
  2. Change company phone and address; click Save. Verify toast notification.
  3. Upload a new company logo; open an invoice print preview and confirm the new logo and footer appear.

## 10. Completion Criteria
- [ ] "Company Setup" accessible from sidebar and user dropdown.
- [ ] Tenant admins can update company profile, address, and PAN/VAT.
- [ ] Logo upload and removal works seamlessly.
- [ ] Invoicing prefixes and default VAT rates configurable by tenant admin.
- [ ] Changes reflect instantly in document headers and invoice prints.

## 11. Open Questions
- *None*: Restores core SaaS tenant autonomy that was inadvertently deleted.
