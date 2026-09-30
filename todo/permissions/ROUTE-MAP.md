# Tenant route map and permission catalog (P01 checkbox 1)

Derived 2026-09-30 by reading every `routes/tenant*.php` file directly (no `route:list`). Source of truth for
`config/permissions.php` (P01 checkbox 2) and for the route wiring in P05 to P08. Key names are permanent API
(stored in `roles.permissions` JSON): rename only with a data migration.

Conventions:

- Key format `<resource>.<action>`, actions only `view, create, edit, delete, cancel, print, export, import, manage`.
  The one exception is the mandated owner-only key `fiscal_year.close_archive` (name fixed by the plan).
- "Gated today" values: `guest` (`guest:web`), `public` (no auth), `signed` (signed URL, outside `auth:web`),
  `any auth` (inside `auth:web`, no role gate), `admin` (`role:admin` on the route or on an enclosing group).
- No `Route::resource` / `apiResource` exists in any tenant route file; every row below is an explicit
  `Route::get/post/put/delete`.
- Controller names are short class names (namespace `App\Http\Controllers\Tenant\...`).
- `config/privileges.php` (legacy) does not exist in this repo, so the `legacy` column is omitted.

## Require order and outer groups (`routes/tenant.php`)

```
Route::middleware([web, PreventAccessFromCentralDomains, AbortIfTenantSuspended, InitializeTenancyByDomain])
  GET /                                   (closure, unnamed, public redirect)
  guest:web group: GET /login, POST /login (throttle:5,1)
  require tenant-impersonation.php        (OUTSIDE auth:web, signed)
  auth:web group:
    POST /logout, GET /dashboard
    require, in this order: tenant-business, tenant-stores, tenant-ledger, tenant-sales, tenant-purchase,
      tenant-sales-returns, tenant-purchase-returns, tenant-stock-adjustments, tenant-stock-transfers,
      tenant-stock-conversions, tenant-reports-accounting, tenant-reports-sales-purchase,
      tenant-reports-inventory, tenant-reports-tds, tenant-reports-stock-valuation,
      tenant-reports-item-wise-sales, tenant-reports-item-wise-purchase, tenant-reports-category-wise,
      tenant-reports-vat-summary, tenant-reports-stock-movement-register, tenant-reports-sales-with-note,
      tenant-reports-damage-lost-stock, tenant-reports-brand-wise, tenant-reports-print-log,
      tenant-employees, tenant-profile, tenant-fixed-assets, tenant-quotations, tenant-receipts,
      tenant-payments, tenant-item-varieties, tenant-backups, tenant-notices, tenant-activity-log,
      tenant-pos, tenant-fiscal-year-archive, tenant-agents
```

Group-level `role:admin` (applies to every child): `tenant-fixed-assets`, `tenant-backups`, `tenant-notices`,
`tenant-activity-log`, `tenant-fiscal-year-archive`, `tenant-employees`, `tenant-reports-accounting`,
`tenant-reports-print-log`. Everything else is gated per route.

## Modules

| key | label | always_on | requires | covers |
|---|---|---|---|---|
| core | Core | yes | none | chart of accounts (read and write), opening balances, customers, suppliers, party ledgers, items and item masters, fiscal years, users, roles, ownership |
| sales | Sales | no | none | sales, capital sales, sales returns, receipts |
| pos | Point of sale | no | sales | POS screen (it posts through `POST /sales`) |
| agents | Sales agents | no | sales | agent master (agents earn commission on sales) |
| quotations | Quotations | no | sales | quotations and convert-to-sale |
| purchases | Purchases | no | none | purchases, capital purchases and settlements, purchase returns, payments |
| inventory | Inventory | no | none | stock adjustments, opening stock import, transfers, conversions, stores, item varieties |
| accounting | Accounting | no | none | journal and cash/bank vouchers, full account ledger, fixed assets |
| reports | Reports | no | none | accounting, sales, purchase, tax, receivables/payables and stock reports (incl. item ledger) |
| admin | Administration | no | none | activity log, print log, backups, notices |

Decisions and justifications:

- **quotations requires sales.** `POST /quotations/{quotation}/convert-to-sale` creates a sale, so a tenant with
  quotations but no sales would be able to create sales through the back door. A quotation is also a pre-sale
  document with no use of its own in a business that does not sell through this app.
- **Users, roles and ownership are in `core`, not `admin`.** Entitlements apply to the owner too (plan 3). If
  `users.manage` / `roles.manage` / `ownership.transfer` lived in an optional module, a tenant without it (or a
  fail-closed `null` tenant, which is core only) could never add a cashier or fix a role. `admin` keeps only the
  optional extras (activity log, print log, backups, notices).
- **Fiscal years (incl. close/archive and archive browsing) are in `core`.** Every posting module dates into a
  fiscal year and year-end close is basic hygiene for any tenant, so it must not disappear with `accounting`.
- **Party ledgers are `core`, the full account ledger is `accounting`.** Customer and supplier statements are
  needed by any selling or buying business even without the accounting module.
- **Stores and item varieties are `inventory`** (as in the plan draft). Sales/purchase forms still read the
  store list from their own controllers' props, so disabling `inventory` does not break them.
- **Print log is `admin`** (audit trail, same class as the activity log) although its route file is a report file.
- **Capital sales are `sales`, capital purchases and their settlements are `purchases`.**
- `reports` has no `requires`: a report over a disabled module simply shows no rows.

## Route table

227 rows, in file order.

### routes/tenant.php (5)

| route name | method + URI | controller@method | gated today | proposed key |
|---|---|---|---|---|
| (unnamed) | GET / | closure (redirect to dashboard or login) | public | allowlist |
| tenant.login | GET /login | AuthenticatedSessionController@create | guest | allowlist |
| tenant.login.store | POST /login | AuthenticatedSessionController@store | guest (+throttle:5,1) | allowlist |
| tenant.logout | POST /logout | AuthenticatedSessionController@destroy | any auth | allowlist |
| tenant.dashboard | GET /dashboard | DashboardController@index | any auth | allowlist |

### routes/tenant-impersonation.php (1)

| route name | method + URI | controller@method | gated today | proposed key |
|---|---|---|---|---|
| tenant.impersonate | GET /impersonate/{user} | ImpersonationController@login | signed (outside auth:web) | allowlist |

### routes/tenant-business.php (54)

| route name | method + URI | controller@method | gated today | proposed key |
|---|---|---|---|---|
| tenant.account-groups.index | GET /account-groups | AccountGroupController@index | any auth | accounts.view |
| tenant.account-groups.store | POST /account-groups | AccountGroupController@store | admin | accounts.create |
| tenant.account-groups.update | PUT /account-groups/{accountGroup} | AccountGroupController@update | admin | accounts.edit |
| tenant.account-groups.destroy | DELETE /account-groups/{accountGroup} | AccountGroupController@destroy | admin | accounts.delete |
| tenant.account-subgroups.index | GET /account-subgroups | AccountSubgroupController@index | any auth | accounts.view |
| tenant.account-subgroups.store | POST /account-subgroups | AccountSubgroupController@store | admin | accounts.create |
| tenant.account-subgroups.update | PUT /account-subgroups/{accountSubgroup} | AccountSubgroupController@update | admin | accounts.edit |
| tenant.account-subgroups.destroy | DELETE /account-subgroups/{accountSubgroup} | AccountSubgroupController@destroy | admin | accounts.delete |
| tenant.accounts.index | GET /accounts | AccountController@index | any auth | accounts.view |
| tenant.accounts.opening-balances.template | GET /accounts/opening-balances/template | AccountController@openingBalanceTemplate | any auth | accounts.view |
| tenant.accounts.store | POST /accounts | AccountController@store | admin | accounts.create |
| tenant.accounts.update | PUT /accounts/{account} | AccountController@update | admin | accounts.edit |
| tenant.accounts.destroy | DELETE /accounts/{account} | AccountController@destroy | admin | accounts.delete |
| tenant.accounts.opening-balances.import | POST /accounts/opening-balances/import | AccountController@importOpeningBalances | admin | opening_balances.import |
| tenant.accounts.opening-balances.reverse | POST /accounts/opening-balances/{journalVoucher}/reverse | AccountController@reverseOpeningBalanceImport | admin | opening_balances.cancel |
| tenant.customers.index | GET /customers | CustomerController@index | any auth | customers.view |
| tenant.customers.store | POST /customers | CustomerController@store | any auth | customers.create |
| tenant.customers.update | PUT /customers/{customer} | CustomerController@update | any auth | customers.edit |
| tenant.customers.destroy | DELETE /customers/{customer} | CustomerController@destroy | any auth | customers.delete |
| tenant.customers.import.template | GET /customers/import/template | CustomerController@importTemplate | any auth | customers.import |
| tenant.customers.import | POST /customers/import | CustomerController@import | any auth | customers.import |
| tenant.suppliers.index | GET /suppliers | SupplierController@index | any auth | suppliers.view |
| tenant.suppliers.store | POST /suppliers | SupplierController@store | any auth | suppliers.create |
| tenant.suppliers.update | PUT /suppliers/{supplier} | SupplierController@update | any auth | suppliers.edit |
| tenant.suppliers.destroy | DELETE /suppliers/{supplier} | SupplierController@destroy | any auth | suppliers.delete |
| tenant.suppliers.import.template | GET /suppliers/import/template | SupplierController@importTemplate | any auth | suppliers.import |
| tenant.suppliers.import | POST /suppliers/import | SupplierController@import | any auth | suppliers.import |
| tenant.brands.index | GET /brands | BrandController@index | any auth | brands.view |
| tenant.brands.store | POST /brands | BrandController@store | any auth | brands.manage |
| tenant.brands.update | PUT /brands/{brand} | BrandController@update | any auth | brands.manage |
| tenant.brands.destroy | DELETE /brands/{brand} | BrandController@destroy | any auth | brands.manage |
| tenant.item-categories.index | GET /item-categories | ItemCategoryController@index | any auth | item_categories.view |
| tenant.item-categories.store | POST /item-categories | ItemCategoryController@store | any auth | item_categories.manage |
| tenant.item-categories.update | PUT /item-categories/{itemCategory} | ItemCategoryController@update | any auth | item_categories.manage |
| tenant.item-categories.destroy | DELETE /item-categories/{itemCategory} | ItemCategoryController@destroy | any auth | item_categories.manage |
| tenant.item-subcategories.index | GET /item-subcategories | ItemSubcategoryController@index | any auth | item_categories.view |
| tenant.item-subcategories.store | POST /item-subcategories | ItemSubcategoryController@store | any auth | item_categories.manage |
| tenant.item-subcategories.update | PUT /item-subcategories/{itemSubcategory} | ItemSubcategoryController@update | any auth | item_categories.manage |
| tenant.item-subcategories.destroy | DELETE /item-subcategories/{itemSubcategory} | ItemSubcategoryController@destroy | any auth | item_categories.manage |
| tenant.items.index | GET /items | ItemController@index | any auth | items.view |
| tenant.items.store | POST /items | ItemController@store | any auth | items.create |
| tenant.items.update | PUT /items/{item} | ItemController@update | any auth | items.edit |
| tenant.items.destroy | DELETE /items/{item} | ItemController@destroy | any auth | items.delete |
| tenant.items.import.template | GET /items/import/template | ItemController@importTemplate | any auth | items.import |
| tenant.items.import | POST /items/import | ItemController@import | any auth | items.import |
| tenant.items.barcode-labels.print | GET /items/barcode-labels/print | BarcodeLabelController@print | any auth | items.print |
| tenant.items.mark-vatable | POST /items/mark-vatable | ItemController@markVatable | any auth | items.edit |
| tenant.items.lookup-barcode | GET /items/lookup-barcode | ItemController@lookupBarcode | any auth | items.view (shared lookup) |
| tenant.items.units.store | POST /items/{item}/units | ItemController@storeUnit | any auth | items.edit |
| tenant.items.units.update | PUT /items/{item}/units/{itemUnit} | ItemController@updateUnit | any auth | items.edit |
| tenant.items.units.destroy | DELETE /items/{item}/units/{itemUnit} | ItemController@destroyUnit | any auth | items.edit |
| tenant.items.ledger | GET /items/{item}/ledger | ItemLedgerController@show | any auth | stock_reports.view |
| tenant.items.ledger.print | GET /items/{item}/ledger/print | ItemLedgerController@print | any auth | stock_reports.print |
| tenant.items.ledger.export | GET /items/{item}/ledger/export | ItemLedgerController@export | any auth | stock_reports.export |

### routes/tenant-stores.php (4)

| route name | method + URI | controller@method | gated today | proposed key |
|---|---|---|---|---|
| tenant.stores.index | GET /stores | StoreController@index | any auth | stores.view |
| tenant.stores.store | POST /stores | StoreController@store | any auth | stores.manage |
| tenant.stores.update | PUT /stores/{store} | StoreController@update | any auth | stores.manage |
| tenant.stores.destroy | DELETE /stores/{store} | StoreController@destroy | any auth | stores.manage |

### routes/tenant-ledger.php (14)

| route name | method + URI | controller@method | gated today | proposed key |
|---|---|---|---|---|
| tenant.fiscal-years.index | GET /fiscal-years | FiscalYearController@index | any auth | fiscal_year.view |
| tenant.fiscal-years.store | POST /fiscal-years | FiscalYearController@store | any auth | fiscal_year.create |
| tenant.fiscal-years.close | POST /fiscal-years/{fiscalYear}/close | FiscalYearController@close | admin | fiscal_year.close_archive (owner only) |
| tenant.fiscal-years.reopen | POST /fiscal-years/{fiscalYear}/reopen | FiscalYearController@reopen | admin | fiscal_year.close_archive (owner only) |
| tenant.fiscal-years.lock | POST /fiscal-years/{fiscalYear}/lock | FiscalYearController@relock | admin | fiscal_year.close_archive (owner only) |
| tenant.journal-vouchers.index | GET /journal-vouchers | JournalVoucherController@index | any auth | journal_vouchers.view |
| tenant.journal-vouchers.export | GET /journal-vouchers/export | JournalVoucherController@export | any auth | journal_vouchers.export |
| tenant.journal-vouchers.store | POST /journal-vouchers | JournalVoucherController@store | admin | journal_vouchers.create |
| tenant.journal-vouchers.cash-bank.store | POST /journal-vouchers/cash-bank | JournalVoucherController@storeCashBank | admin | cash_bank_vouchers.create |
| tenant.journal-vouchers.cancel | POST /journal-vouchers/{journalVoucher}/cancel | JournalVoucherController@cancel | admin | journal_vouchers.cancel |
| tenant.journal-vouchers.print | GET /journal-vouchers/{journalVoucher}/print | JournalVoucherController@print | any auth | journal_vouchers.print |
| tenant.accounts.ledger | GET /accounts/{account}/ledger | AccountController@ledger | any auth | account_ledger.view, or party_ledger.view for customer/supplier accounts |
| tenant.accounts.ledger.print | GET /accounts/{account}/ledger/print | AccountController@ledgerPrint | any auth | account_ledger.print, or party_ledger.print for customer/supplier accounts |
| tenant.accounts.ledger.export | GET /accounts/{account}/ledger/export | AccountController@ledgerExport | any auth | account_ledger.export, or party_ledger.export for customer/supplier accounts |

### routes/tenant-sales.php (11)

| route name | method + URI | controller@method | gated today | proposed key |
|---|---|---|---|---|
| tenant.sales.index | GET /sales | SaleController@index | any auth | sales.view |
| tenant.sales.export | GET /sales/export | SaleController@export | any auth | sales.export |
| tenant.sales.store | POST /sales | SaleController@store | any auth | sales.create |
| tenant.sales.cancel | POST /sales/{sale}/cancel | SaleController@cancel | admin | sales.cancel |
| tenant.sales.print | GET /sales/{sale}/print | SaleController@print | any auth | sales.print |
| tenant.sales.note-templates.store | POST /sales/note-templates | SaleController@storeNoteTemplate | any auth | sales.create |
| tenant.sales.note-templates.destroy | DELETE /sales/note-templates/{saleNoteTemplate} | SaleController@destroyNoteTemplate | any auth | sales.create |
| tenant.capital-sales.index | GET /capital-sales | CapitalSaleController@index | any auth | capital_sales.view |
| tenant.capital-sales.store | POST /capital-sales | CapitalSaleController@store | any auth | capital_sales.create |
| tenant.capital-sales.cancel | POST /capital-sales/{capitalSale}/cancel | CapitalSaleController@cancel | admin | capital_sales.cancel |
| tenant.capital-sales.print | GET /capital-sales/{capitalSale}/print | CapitalSaleController@print | any auth | capital_sales.print |

### routes/tenant-purchase.php (12)

| route name | method + URI | controller@method | gated today | proposed key |
|---|---|---|---|---|
| tenant.purchases.index | GET /purchases | PurchaseController@index | any auth | purchases.view |
| tenant.purchases.store | POST /purchases | PurchaseController@store | any auth | purchases.create |
| tenant.purchases.export | GET /purchases/export | PurchaseController@export | any auth | purchases.export |
| tenant.purchases.cancel | POST /purchases/{purchase}/cancel | PurchaseController@cancel | admin | purchases.cancel |
| tenant.purchases.print | GET /purchases/{purchase}/print | PurchaseController@print | any auth | purchases.print |
| tenant.capital-purchases.index | GET /capital-purchases | CapitalPurchaseController@index | any auth | capital_purchases.view |
| tenant.capital-purchases.store | POST /capital-purchases | CapitalPurchaseController@store | any auth | capital_purchases.create |
| tenant.capital-purchases.export | GET /capital-purchases/export | CapitalPurchaseController@export | any auth | capital_purchases.export |
| tenant.capital-purchases.print | GET /capital-purchases/{capitalPurchase}/print | CapitalPurchaseController@print | any auth | capital_purchases.print |
| tenant.capital-purchases.cancel | POST /capital-purchases/{capitalPurchase}/cancel | CapitalPurchaseController@cancel | admin | capital_purchases.cancel |
| tenant.capital-purchases.settlements.store | POST /capital-purchases/{capitalPurchase}/settlements | CapitalPurchaseSettlementController@store | any auth | capital_purchase_settlements.create |
| tenant.capital-purchases.settlements.cancel | POST /capital-purchases/settlements/{settlement}/cancel | CapitalPurchaseSettlementController@cancel | admin | capital_purchase_settlements.cancel |

### routes/tenant-sales-returns.php (8)

| route name | method + URI | controller@method | gated today | proposed key |
|---|---|---|---|---|
| tenant.sales-returns.index | GET /sales-returns | SalesReturnController@index | any auth | sales_returns.view |
| tenant.sales-returns.store | POST /sales-returns | SalesReturnController@store | any auth | sales_returns.create |
| tenant.sales-returns.store-unlinked | POST /sales-returns/unlinked | SalesReturnController@storeUnlinked | admin | unlinked_sales_returns.create |
| tenant.sales-returns.request | POST /sales-returns/request | SalesReturnController@requestReturn | any auth | sales_return_requests.create |
| tenant.sales-returns.approve | POST /sales-returns/{salesReturn}/approve | SalesReturnController@approve | admin | sales_return_requests.manage |
| tenant.sales-returns.reject | POST /sales-returns/{salesReturn}/reject | SalesReturnController@reject | admin | sales_return_requests.manage |
| tenant.sales-returns.cancel | POST /sales-returns/{salesReturn}/cancel | SalesReturnController@cancel | admin | sales_returns.cancel |
| tenant.sales-returns.print | GET /sales-returns/{salesReturn}/print | SalesReturnController@print | any auth | sales_returns.print |

### routes/tenant-purchase-returns.php (7)

| route name | method + URI | controller@method | gated today | proposed key |
|---|---|---|---|---|
| tenant.purchase-returns.index | GET /purchase-returns | PurchaseReturnController@index | any auth | purchase_returns.view |
| tenant.purchase-returns.store | POST /purchase-returns | PurchaseReturnController@store | any auth | purchase_returns.create |
| tenant.purchase-returns.store-unlinked | POST /purchase-returns/unlinked | PurchaseReturnController@storeUnlinked | admin | unlinked_purchase_returns.create |
| tenant.purchase-returns.quote-unlinked | GET /purchase-returns/unlinked/quote | PurchaseReturnController@quoteUnlinked | admin | unlinked_purchase_returns.create |
| tenant.purchase-returns.export | GET /purchase-returns/export | PurchaseReturnController@export | any auth | purchase_returns.export |
| tenant.purchase-returns.cancel | POST /purchase-returns/{purchaseReturn}/cancel | PurchaseReturnController@cancel | admin | purchase_returns.cancel |
| tenant.purchase-returns.print | GET /purchase-returns/{purchaseReturn}/print | PurchaseReturnController@print | any auth | purchase_returns.print |

### routes/tenant-stock-adjustments.php (6)

| route name | method + URI | controller@method | gated today | proposed key |
|---|---|---|---|---|
| tenant.stock-adjustments.index | GET /stock-adjustments | StockAdjustmentController@index | any auth | stock_adjustments.view |
| tenant.stock-adjustments.store | POST /stock-adjustments | StockAdjustmentController@store | any auth | stock_adjustments.create |
| tenant.stock-adjustments.cancel | POST /stock-adjustments/{stock_adjustment}/cancel | StockAdjustmentController@cancel | admin | stock_adjustments.cancel |
| tenant.stock-adjustments.print | GET /stock-adjustments/{stock_adjustment}/print | StockAdjustmentController@print | any auth | stock_adjustments.print |
| tenant.stock-adjustments.opening-stock.template | GET /stock-adjustments/opening-stock/template | StockAdjustmentController@openingStockTemplate | any auth | opening_stock.import |
| tenant.stock-adjustments.opening-stock.import | POST /stock-adjustments/opening-stock/import | StockAdjustmentController@importOpeningStock | any auth | opening_stock.import |

### routes/tenant-stock-transfers.php (4)

| route name | method + URI | controller@method | gated today | proposed key |
|---|---|---|---|---|
| tenant.stock-transfers.index | GET /stock-transfers | StockTransferController@index | any auth | stock_transfers.view |
| tenant.stock-transfers.store | POST /stock-transfers | StockTransferController@store | any auth | stock_transfers.create |
| tenant.stock-transfers.cancel | POST /stock-transfers/{stock_transfer}/cancel | StockTransferController@cancel | admin | stock_transfers.cancel |
| tenant.stock-transfers.print | GET /stock-transfers/{stock_transfer}/print | StockTransferController@print | any auth | stock_transfers.print |

### routes/tenant-stock-conversions.php (4)

| route name | method + URI | controller@method | gated today | proposed key |
|---|---|---|---|---|
| tenant.stock-conversions.index | GET /stock-conversions | StockConversionController@index | any auth | stock_conversions.view |
| tenant.stock-conversions.store | POST /stock-conversions | StockConversionController@store | any auth | stock_conversions.create |
| tenant.stock-conversions.cancel | POST /stock-conversions/{stock_conversion}/cancel | StockConversionController@cancel | admin | stock_conversions.cancel |
| tenant.stock-conversions.print | GET /stock-conversions/{stock_conversion}/print | StockConversionController@print | any auth | stock_conversions.print |

### routes/tenant-reports-accounting.php (20, group `role:admin`)

| route name | method + URI | controller@method | gated today | proposed key |
|---|---|---|---|---|
| tenant.reports.trial-balance | GET /reports/trial-balance | AccountingReportController@trialBalance | admin | financial_statements.view |
| tenant.reports.trial-balance.print | GET /reports/trial-balance/print | AccountingReportController@trialBalancePdf | admin | financial_statements.print |
| tenant.reports.trial-balance.export | GET /reports/trial-balance/export | AccountingReportController@trialBalanceExport | admin | financial_statements.export |
| tenant.reports.income-statement | GET /reports/income-statement | AccountingReportController@incomeStatement | admin | financial_statements.view |
| tenant.reports.income-statement.print | GET /reports/income-statement/print | AccountingReportController@incomeStatementPdf | admin | financial_statements.print |
| tenant.reports.income-statement.export | GET /reports/income-statement/export | AccountingReportController@incomeStatementExport | admin | financial_statements.export |
| tenant.reports.balance-sheet | GET /reports/balance-sheet | AccountingReportController@balanceSheet | admin | financial_statements.view |
| tenant.reports.balance-sheet.print | GET /reports/balance-sheet/print | AccountingReportController@balanceSheetPdf | admin | financial_statements.print |
| tenant.reports.balance-sheet.export | GET /reports/balance-sheet/export | AccountingReportController@balanceSheetExport | admin | financial_statements.export |
| tenant.reports.day-book | GET /reports/day-book | AccountingReportController@dayBook | admin | day_book.view |
| tenant.reports.day-book.print | GET /reports/day-book/print | AccountingReportController@dayBookPdf | admin | day_book.print |
| tenant.reports.day-book.export | GET /reports/day-book/export | AccountingReportController@dayBookExport | admin | day_book.export |
| tenant.reports.cash-book | GET /reports/cash-book | AccountingReportController@cashBook | admin | cash_bank_book.view |
| tenant.reports.cash-book.print | GET /reports/cash-book/print | AccountingReportController@cashBookPdf | admin | cash_bank_book.print |
| tenant.reports.cash-book.export | GET /reports/cash-book/export | AccountingReportController@cashBookExport | admin | cash_bank_book.export |
| tenant.reports.bank-book | GET /reports/bank-book | AccountingReportController@bankBook | admin | cash_bank_book.view |
| tenant.reports.bank-book.print | GET /reports/bank-book/print | AccountingReportController@bankBookPdf | admin | cash_bank_book.print |
| tenant.reports.bank-book.export | GET /reports/bank-book/export | AccountingReportController@bankBookExport | admin | cash_bank_book.export |
| tenant.reports.cancelled-documents | GET /reports/cancelled-documents | AccountingReportController@cancelledDocuments | admin | cancelled_documents.view |
| tenant.reports.cancelled-documents.export | GET /reports/cancelled-documents/export | AccountingReportController@cancelledDocumentsExport | admin | cancelled_documents.export |

### routes/tenant-reports-sales-purchase.php (16)

| route name | method + URI | controller@method | gated today | proposed key |
|---|---|---|---|---|
| tenant.reports.sales-register | GET /reports/sales-register | SalesPurchaseReportController@salesRegister | any auth | sales_reports.view |
| tenant.reports.purchase-register | GET /reports/purchase-register | SalesPurchaseReportController@purchaseRegister | any auth | purchase_reports.view |
| tenant.reports.sales-vat-book | GET /reports/sales-vat-book | SalesPurchaseReportController@salesVatBook | any auth | tax_reports.view |
| tenant.reports.sales-vat-book.export | GET /reports/sales-vat-book/export | SalesPurchaseReportController@salesVatBookExport | any auth | tax_reports.export |
| tenant.reports.purchase-vat-book | GET /reports/purchase-vat-book | SalesPurchaseReportController@purchaseVatBook | any auth | tax_reports.view |
| tenant.reports.purchase-vat-book.export | GET /reports/purchase-vat-book/export | SalesPurchaseReportController@purchaseVatBookExport | any auth | tax_reports.export |
| tenant.reports.sales-return-register | GET /reports/sales-return-register | SalesPurchaseReportController@salesReturnRegister | any auth | sales_reports.view |
| tenant.reports.sales-return-register.export | GET /reports/sales-return-register/export | SalesPurchaseReportController@salesReturnRegisterExport | any auth | sales_reports.export |
| tenant.reports.purchase-return-register | GET /reports/purchase-return-register | SalesPurchaseReportController@purchaseReturnRegister | any auth | purchase_reports.view |
| tenant.reports.purchase-return-register.export | GET /reports/purchase-return-register/export | SalesPurchaseReportController@purchaseReturnRegisterExport | any auth | purchase_reports.export |
| tenant.reports.aged-receivables | GET /reports/aged-receivables | SalesPurchaseReportController@agedReceivables | any auth | receivables_reports.view |
| tenant.reports.aged-payables | GET /reports/aged-payables | SalesPurchaseReportController@agedPayables | any auth | payables_reports.view |
| tenant.reports.debtors | GET /reports/debtors | SalesPurchaseReportController@debtors | any auth | receivables_reports.view |
| tenant.reports.debtors.export | GET /reports/debtors/export | SalesPurchaseReportController@debtorsExport | any auth | receivables_reports.export |
| tenant.reports.creditors | GET /reports/creditors | SalesPurchaseReportController@creditors | any auth | payables_reports.view |
| tenant.reports.creditors.export | GET /reports/creditors/export | SalesPurchaseReportController@creditorsExport | any auth | payables_reports.export |

### Single-purpose report files (15)

| file | route name | method + URI | controller@method | gated today | proposed key |
|---|---|---|---|---|---|
| tenant-reports-inventory | tenant.reports.stock-summary | GET /reports/stock-summary | InventoryReportController@stockSummary | any auth | stock_reports.view |
| tenant-reports-tds | tenant.reports.tds | GET /reports/tds | TdsReportController@index | any auth | tax_reports.view |
| tenant-reports-stock-valuation | tenant.reports.stock-valuation | GET /reports/stock-valuation | StockValuationReportController@index | any auth | stock_valuation.view |
| tenant-reports-item-wise-sales | tenant.reports.item-wise-sales | GET /reports/item-wise-sales | ItemWiseSalesReportController@index | any auth | sales_reports.view |
| tenant-reports-item-wise-purchase | tenant.reports.item-wise-purchase | GET /reports/item-wise-purchase | ItemWisePurchaseReportController@index | any auth | purchase_reports.view |
| tenant-reports-category-wise | tenant.reports.sales-by-category | GET /reports/sales-by-category | CategoryWiseReportController@salesByCategory | any auth | sales_reports.view |
| tenant-reports-category-wise | tenant.reports.purchase-by-category | GET /reports/purchase-by-category | CategoryWiseReportController@purchaseByCategory | any auth | purchase_reports.view |
| tenant-reports-category-wise | tenant.reports.stock-by-category | GET /reports/stock-by-category | CategoryWiseReportController@stockByCategory | any auth | stock_reports.view |
| tenant-reports-vat-summary | tenant.reports.vat-summary | GET /reports/vat-summary | VatSummaryReportController@index | any auth | tax_reports.view |
| tenant-reports-vat-summary | tenant.reports.vat-summary.export | GET /reports/vat-summary/export | VatSummaryReportController@export | any auth | tax_reports.export |
| tenant-reports-stock-movement-register | tenant.reports.stock-movement-register | GET /reports/stock-movement-register | StockMovementRegisterController@index | any auth | stock_reports.view |
| tenant-reports-sales-with-note | tenant.reports.sales-with-note | GET /reports/sales-with-note | SalesWithNoteReportController@index | any auth | sales_reports.view |
| tenant-reports-damage-lost-stock | tenant.reports.damage-lost-stock | GET /reports/damage-lost-stock | DamageLostStockReportController@index | any auth | stock_reports.view |
| tenant-reports-brand-wise | tenant.reports.stock-by-brand | GET /reports/stock-by-brand | BrandWiseReportController@stockByBrand | any auth | stock_reports.view |
| tenant-reports-print-log | tenant.reports.print-log | GET /reports/print-log | PrintLogReportController@index | admin (group) | print_log.view |

(inventory 1, tds 1, stock-valuation 1, item-wise-sales 1, item-wise-purchase 1, category-wise 3,
vat-summary 2, stock-movement-register 1, sales-with-note 1, damage-lost-stock 1, brand-wise 1, print-log 1 = 15.)

### routes/tenant-employees.php (3, group `role:admin`)

| route name | method + URI | controller@method | gated today | proposed key |
|---|---|---|---|---|
| tenant.admin.users | GET /admin/users | UserController@index | admin | users.manage |
| tenant.admin.users.store | POST /admin/users | UserController@store | admin | users.manage |
| tenant.admin.users.update | PUT /admin/users/{user} | UserController@update | admin | users.manage |
| tenant.admin.users.transfer-ownership | POST /admin/users/{user}/transfer-ownership | UserController@transferOwnership | (new, P12) | ownership.transfer |

### routes/tenant-profile.php (3)

| route name | method + URI | controller@method | gated today | proposed key |
|---|---|---|---|---|
| tenant.profile.edit | GET /profile | ProfileController@edit | any auth | allowlist |
| tenant.profile.update | PUT /profile | ProfileController@update | any auth | allowlist |
| tenant.profile.password | PUT /profile/password | ProfileController@updatePassword | any auth | allowlist |

### routes/tenant-fixed-assets.php (5, group `role:admin`)

| route name | method + URI | controller@method | gated today | proposed key |
|---|---|---|---|---|
| tenant.fixed-assets.index | GET /fixed-assets | FixedAssetController@index | admin | fixed_assets.view |
| tenant.fixed-assets.store | POST /fixed-assets | FixedAssetController@store | admin | fixed_assets.create |
| tenant.fixed-assets.store-existing | POST /fixed-assets/existing | FixedAssetController@storeExisting | admin | fixed_assets.create |
| tenant.fixed-assets.dispose | POST /fixed-assets/{fixedAsset}/dispose | FixedAssetController@dispose | admin | fixed_assets.manage |
| tenant.fixed-assets.post-depreciation | POST /fixed-assets/post-depreciation | FixedAssetController@postDepreciation | admin | fixed_assets.manage |

### routes/tenant-quotations.php (7)

| route name | method + URI | controller@method | gated today | proposed key |
|---|---|---|---|---|
| tenant.quotations.index | GET /quotations | QuotationController@index | any auth | quotations.view |
| tenant.quotations.store | POST /quotations | QuotationController@store | any auth | quotations.create |
| tenant.quotations.update | PUT /quotations/{quotation} | QuotationController@update | any auth | quotations.edit |
| tenant.quotations.destroy | DELETE /quotations/{quotation} | QuotationController@destroy | any auth | quotations.delete |
| tenant.quotations.print | GET /quotations/{quotation}/print | QuotationController@print | any auth | quotations.print |
| tenant.quotations.cancel | POST /quotations/{quotation}/cancel | QuotationController@cancel | any auth | quotations.cancel |
| tenant.quotations.convert-to-sale | POST /quotations/{quotation}/convert-to-sale | QuotationController@convertToSale | any auth | quotations.edit (+ controller check `sales.create`) |

### routes/tenant-receipts.php (4)

| route name | method + URI | controller@method | gated today | proposed key |
|---|---|---|---|---|
| tenant.receipts.index | GET /receipts | ReceiptController@index | any auth | receipts.view |
| tenant.receipts.store | POST /receipts | ReceiptController@store | any auth | receipts.create |
| tenant.receipts.print | GET /receipts/{receipt}/print | ReceiptController@print | any auth | receipts.print |
| tenant.receipts.cancel | POST /receipts/{receipt}/cancel | ReceiptController@cancel | admin | receipts.cancel |

### routes/tenant-payments.php (3)

| route name | method + URI | controller@method | gated today | proposed key |
|---|---|---|---|---|
| tenant.payments.index | GET /payments | PaymentController@index | any auth | payments.view |
| tenant.payments.store | POST /payments | PaymentController@store | any auth | payments.create |
| tenant.payments.cancel | POST /payments/{payment}/cancel | PaymentController@cancel | admin | payments.cancel |

### routes/tenant-item-varieties.php (4)

| route name | method + URI | controller@method | gated today | proposed key |
|---|---|---|---|---|
| tenant.item-varieties.index | GET /item-varieties | ItemVarietyController@index | any auth | item_varieties.view |
| tenant.item-varieties.store | POST /item-varieties | ItemVarietyController@store | any auth | item_varieties.manage |
| tenant.item-varieties.update | PUT /item-varieties/{itemVariety} | ItemVarietyController@update | any auth | item_varieties.manage |
| tenant.item-varieties.destroy | DELETE /item-varieties/{itemVariety} | ItemVarietyController@destroy | any auth | item_varieties.manage |

### routes/tenant-backups.php (4, group `role:admin`)

| route name | method + URI | controller@method | gated today | proposed key |
|---|---|---|---|---|
| tenant.backups.index | GET /backups | BackupController@index | admin | backups.manage (owner only) |
| tenant.backups.store | POST /backups | BackupController@store | admin | backups.manage (owner only) |
| tenant.backups.download | GET /backups/{backup}/download | BackupController@download | admin | backups.manage (owner only) |
| tenant.backups.destroy | DELETE /backups/{backup} | BackupController@destroy | admin | backups.manage (owner only) |

### routes/tenant-notices.php (4, group `role:admin`)

| route name | method + URI | controller@method | gated today | proposed key |
|---|---|---|---|---|
| tenant.notices.index | GET /notices | NoticeController@index | admin | notices.manage |
| tenant.notices.store | POST /notices | NoticeController@store | admin | notices.manage |
| tenant.notices.update | PUT /notices/{notice} | NoticeController@update | admin | notices.manage |
| tenant.notices.destroy | DELETE /notices/{notice} | NoticeController@destroy | admin | notices.manage |

### routes/tenant-activity-log.php (1, group `role:admin`)

| route name | method + URI | controller@method | gated today | proposed key |
|---|---|---|---|---|
| tenant.activity-log.index | GET /activity-log | ActivityLogController@index | admin | activity_log.view |

### routes/tenant-pos.php (1)

| route name | method + URI | controller@method | gated today | proposed key |
|---|---|---|---|---|
| tenant.pos.index | GET /pos | PosController@index | any auth | pos.view |

### routes/tenant-fiscal-year-archive.php (3, group `role:admin`)

| route name | method + URI | controller@method | gated today | proposed key |
|---|---|---|---|---|
| tenant.fiscal-years.archive | POST /fiscal-years/{fiscalYear}/archive | FiscalYearArchiveController@store | admin | fiscal_year.close_archive (owner only) |
| tenant.fiscal-year-archives.show | GET /fiscal-year-archives/{fiscalYearArchive} | ArchivedFiscalYearController@show | admin | fiscal_year_archive.view |
| tenant.fiscal-year-archives.voucher | GET /fiscal-year-archives/{fiscalYearArchive}/vouchers/{voucherId} | ArchivedFiscalYearController@voucher | admin | fiscal_year_archive.view |

### routes/tenant-agents.php (4)

| route name | method + URI | controller@method | gated today | proposed key |
|---|---|---|---|---|
| tenant.agents.index | GET /agents | AgentController@index | any auth | agents.view |
| tenant.agents.store | POST /agents | AgentController@store | any auth | agents.create |
| tenant.agents.update | PUT /agents/{agent} | AgentController@update | any auth | agents.edit |
| tenant.agents.destroy | DELETE /agents/{agent} | AgentController@destroy | any auth | agents.delete |

## Totals

Route definitions counted per file (explicit `Route::get/post/put/delete` calls, confirmed with a grep count of
`Route::(get|post|put|patch|delete|match|any|resource|apiResource|view|redirect)(` per file, which also returned 227):

| file | routes | file | routes |
|---|---|---|---|
| tenant.php | 5 | tenant-reports-accounting.php | 20 |
| tenant-impersonation.php | 1 | tenant-reports-sales-purchase.php | 16 |
| tenant-business.php | 54 | tenant-reports-inventory.php | 1 |
| tenant-stores.php | 4 | tenant-reports-tds.php | 1 |
| tenant-ledger.php | 14 | tenant-reports-stock-valuation.php | 1 |
| tenant-sales.php | 11 | tenant-reports-item-wise-sales.php | 1 |
| tenant-purchase.php | 12 | tenant-reports-item-wise-purchase.php | 1 |
| tenant-sales-returns.php | 8 | tenant-reports-category-wise.php | 3 |
| tenant-purchase-returns.php | 7 | tenant-reports-vat-summary.php | 2 |
| tenant-stock-adjustments.php | 6 | tenant-reports-stock-movement-register.php | 1 |
| tenant-stock-transfers.php | 4 | tenant-reports-sales-with-note.php | 1 |
| tenant-stock-conversions.php | 4 | tenant-reports-damage-lost-stock.php | 1 |
| tenant-employees.php | 3 | tenant-reports-brand-wise.php | 1 |
| tenant-profile.php | 3 | tenant-reports-print-log.php | 1 |
| tenant-fixed-assets.php | 5 | tenant-quotations.php | 7 |
| tenant-receipts.php | 4 | tenant-payments.php | 3 |
| tenant-item-varieties.php | 4 | tenant-backups.php | 4 |
| tenant-notices.php | 4 | tenant-activity-log.php | 1 |
| tenant-pos.php | 1 | tenant-fiscal-year-archive.php | 3 |
| tenant-agents.php | 4 | | |

**Grand total: 227 routes** in 39 files = 9 allowlisted + 218 gated. Gated routes today: 75 `admin`,
143 `any auth`. The 218 gated routes map to 144 route-bearing keys; the 3 account ledger routes are each
covered by two keys (account and party), so the "routes covered" column below sums to 221.

## Allowlist (authenticated or pre-auth, no permission)

| route | why |
|---|---|
| (unnamed) GET / | Redirect only (dashboard or login); renders nothing. |
| tenant.login, tenant.login.store | Guest routes, the user is not authenticated yet. |
| tenant.impersonate | Outside `auth:web` by design; protected by the signed URL that only the central `TenantController::impersonate()` issues. Nothing to authorize against yet. |
| tenant.logout | Every user must be able to end their own session. |
| tenant.dashboard | Landing page after login for every user. Active notices are read here (see `DashboardController`); there is no separate notice-read route. P09 should trim dashboard widgets by permission rather than gate the page. |
| tenant.profile.edit, tenant.profile.update, tenant.profile.password | A user managing their own account and password; never another user's. |

The P09 route-audit test must additionally accept the 3 account ledger routes as "controller-authorized"
(see Shared lookup endpoints, item 1), unless P04 exposes a gate that takes the account as an argument.

## Permission keys (147)

Legacy column omitted: `config/privileges.php` is absent from this repo.

| key | module | group | label | owner_only | routes |
|---|---|---|---|---|---|
| accounts.view | core | Chart of accounts | View chart of accounts | no | 4 |
| accounts.create | core | Chart of accounts | Add account groups, subgroups and accounts | no | 3 |
| accounts.edit | core | Chart of accounts | Edit account groups, subgroups and accounts | no | 3 |
| accounts.delete | core | Chart of accounts | Delete account groups, subgroups and accounts | no | 3 |
| opening_balances.import | core | Chart of accounts | Import opening balances | no | 1 |
| opening_balances.cancel | core | Chart of accounts | Reverse an opening balance import | no | 1 |
| customers.view | core | Customers and suppliers | View customers | no | 1 |
| customers.create | core | Customers and suppliers | Add customers | no | 1 |
| customers.edit | core | Customers and suppliers | Edit customers | no | 1 |
| customers.delete | core | Customers and suppliers | Delete customers | no | 1 |
| customers.import | core | Customers and suppliers | Import customers | no | 2 |
| suppliers.view | core | Customers and suppliers | View suppliers | no | 1 |
| suppliers.create | core | Customers and suppliers | Add suppliers | no | 1 |
| suppliers.edit | core | Customers and suppliers | Edit suppliers | no | 1 |
| suppliers.delete | core | Customers and suppliers | Delete suppliers | no | 1 |
| suppliers.import | core | Customers and suppliers | Import suppliers | no | 2 |
| party_ledger.view | core | Customers and suppliers | View customer and supplier ledgers | no | 1 |
| party_ledger.print | core | Customers and suppliers | Print customer and supplier ledgers | no | 1 |
| party_ledger.export | core | Customers and suppliers | Export customer and supplier ledgers | no | 1 |
| items.view | core | Items | View items | no | 2 |
| items.create | core | Items | Add items | no | 1 |
| items.edit | core | Items | Edit items, units and VAT status | no | 5 |
| items.delete | core | Items | Delete items | no | 1 |
| items.import | core | Items | Import items | no | 2 |
| items.print | core | Items | Print barcode labels | no | 1 |
| brands.view | core | Items | View brands | no | 1 |
| brands.manage | core | Items | Add, edit and delete brands | no | 3 |
| item_categories.view | core | Items | View item categories | no | 2 |
| item_categories.manage | core | Items | Add, edit and delete item categories | no | 6 |
| fiscal_year.view | core | Fiscal years | View fiscal years | no | 1 |
| fiscal_year.create | core | Fiscal years | Add a fiscal year | no | 1 |
| fiscal_year.edit | core | Fiscal years | Post corrections into a reopened fiscal year | no | 0 |
| fiscal_year.close_archive | core | Fiscal years | Close, reopen, relock and archive fiscal years | yes | 4 |
| fiscal_year_archive.view | core | Fiscal years | Browse archived fiscal years | no | 2 |
| users.manage | core | Users and roles | Manage users | no | 3 |
| roles.manage | core | Users and roles | Manage roles and permissions | yes | 0 |
| ownership.transfer | core | Users and roles | Transfer ownership | yes | 0 |
| sales.view | sales | Sales | View sales | no | 1 |
| sales.create | sales | Sales | Create sales | no | 3 |
| sales.print | sales | Sales | Print sales invoices | no | 1 |
| sales.export | sales | Sales | Export sales | no | 1 |
| sales.cancel | sales | Sales | Cancel sales | no | 1 |
| capital_sales.view | sales | Sales | View capital sales | no | 1 |
| capital_sales.create | sales | Sales | Create capital sales | no | 1 |
| capital_sales.print | sales | Sales | Print capital sales | no | 1 |
| capital_sales.cancel | sales | Sales | Cancel capital sales | no | 1 |
| sales_returns.view | sales | Sales returns | View sales returns | no | 1 |
| sales_returns.create | sales | Sales returns | Post sales returns against a bill | no | 1 |
| sales_return_requests.create | sales | Sales returns | Request a sales return | no | 1 |
| sales_return_requests.manage | sales | Sales returns | Approve or reject return requests | no | 2 |
| unlinked_sales_returns.create | sales | Sales returns | Post sales returns without a bill | no | 1 |
| sales_returns.print | sales | Sales returns | Print sales returns | no | 1 |
| sales_returns.cancel | sales | Sales returns | Cancel sales returns | no | 1 |
| receipts.view | sales | Receipts | View receipts | no | 1 |
| receipts.create | sales | Receipts | Record receipts | no | 1 |
| receipts.print | sales | Receipts | Print receipts | no | 1 |
| receipts.cancel | sales | Receipts | Cancel receipts | no | 1 |
| pos.view | pos | Point of sale | Open point of sale | no | 1 |
| agents.view | agents | Sales agents | View agents | no | 1 |
| agents.create | agents | Sales agents | Add agents | no | 1 |
| agents.edit | agents | Sales agents | Edit agents | no | 1 |
| agents.delete | agents | Sales agents | Delete agents | no | 1 |
| quotations.view | quotations | Quotations | View quotations | no | 1 |
| quotations.create | quotations | Quotations | Create quotations | no | 1 |
| quotations.edit | quotations | Quotations | Edit quotations and convert them to sales | no | 2 |
| quotations.delete | quotations | Quotations | Delete quotations | no | 1 |
| quotations.print | quotations | Quotations | Print quotations | no | 1 |
| quotations.cancel | quotations | Quotations | Cancel quotations | no | 1 |
| purchases.view | purchases | Purchases | View purchases | no | 1 |
| purchases.create | purchases | Purchases | Create purchases | no | 1 |
| purchases.print | purchases | Purchases | Print purchases | no | 1 |
| purchases.export | purchases | Purchases | Export purchases | no | 1 |
| purchases.cancel | purchases | Purchases | Cancel purchases | no | 1 |
| capital_purchases.view | purchases | Purchases | View capital purchases | no | 1 |
| capital_purchases.create | purchases | Purchases | Create capital purchases | no | 1 |
| capital_purchases.print | purchases | Purchases | Print capital purchases | no | 1 |
| capital_purchases.export | purchases | Purchases | Export capital purchases | no | 1 |
| capital_purchases.cancel | purchases | Purchases | Cancel capital purchases | no | 1 |
| capital_purchase_settlements.create | purchases | Purchases | Settle capital purchases | no | 1 |
| capital_purchase_settlements.cancel | purchases | Purchases | Cancel capital purchase settlements | no | 1 |
| purchase_returns.view | purchases | Purchase returns | View purchase returns | no | 1 |
| purchase_returns.create | purchases | Purchase returns | Post purchase returns against a bill | no | 1 |
| unlinked_purchase_returns.create | purchases | Purchase returns | Post purchase returns without a bill | no | 2 |
| purchase_returns.print | purchases | Purchase returns | Print purchase returns | no | 1 |
| purchase_returns.export | purchases | Purchase returns | Export purchase returns | no | 1 |
| purchase_returns.cancel | purchases | Purchase returns | Cancel purchase returns | no | 1 |
| payments.view | purchases | Payments | View payments | no | 1 |
| payments.create | purchases | Payments | Record payments | no | 1 |
| payments.cancel | purchases | Payments | Cancel payments | no | 1 |
| stock_adjustments.view | inventory | Stock | View stock adjustments | no | 1 |
| stock_adjustments.create | inventory | Stock | Create stock adjustments | no | 1 |
| stock_adjustments.print | inventory | Stock | Print stock adjustments | no | 1 |
| stock_adjustments.cancel | inventory | Stock | Cancel stock adjustments | no | 1 |
| opening_stock.import | inventory | Stock | Import opening stock | no | 2 |
| stock_transfers.view | inventory | Stock | View stock transfers | no | 1 |
| stock_transfers.create | inventory | Stock | Create stock transfers | no | 1 |
| stock_transfers.print | inventory | Stock | Print stock transfers | no | 1 |
| stock_transfers.cancel | inventory | Stock | Cancel stock transfers | no | 1 |
| stock_conversions.view | inventory | Stock | View production and refining | no | 1 |
| stock_conversions.create | inventory | Stock | Create production and refining entries | no | 1 |
| stock_conversions.print | inventory | Stock | Print production and refining entries | no | 1 |
| stock_conversions.cancel | inventory | Stock | Cancel production and refining entries | no | 1 |
| stores.view | inventory | Stores and varieties | View stores | no | 1 |
| stores.manage | inventory | Stores and varieties | Add, edit and delete stores | no | 3 |
| item_varieties.view | inventory | Stores and varieties | View item varieties | no | 1 |
| item_varieties.manage | inventory | Stores and varieties | Add, edit and delete item varieties | no | 3 |
| journal_vouchers.view | accounting | Vouchers | View vouchers | no | 1 |
| journal_vouchers.create | accounting | Vouchers | Post journal vouchers | no | 1 |
| cash_bank_vouchers.create | accounting | Vouchers | Post cash and bank vouchers | no | 1 |
| journal_vouchers.print | accounting | Vouchers | Print vouchers | no | 1 |
| journal_vouchers.export | accounting | Vouchers | Export vouchers | no | 1 |
| journal_vouchers.cancel | accounting | Vouchers | Cancel vouchers | no | 1 |
| account_ledger.view | accounting | Account ledger | View any account ledger | no | 1 |
| account_ledger.print | accounting | Account ledger | Print any account ledger | no | 1 |
| account_ledger.export | accounting | Account ledger | Export any account ledger | no | 1 |
| fixed_assets.view | accounting | Fixed assets | View fixed assets | no | 1 |
| fixed_assets.create | accounting | Fixed assets | Add fixed assets | no | 2 |
| fixed_assets.manage | accounting | Fixed assets | Dispose assets and post depreciation | no | 2 |
| financial_statements.view | reports | Accounting reports | View trial balance, income statement and balance sheet | no | 3 |
| financial_statements.print | reports | Accounting reports | Print financial statements | no | 3 |
| financial_statements.export | reports | Accounting reports | Export financial statements | no | 3 |
| day_book.view | reports | Accounting reports | View day book | no | 1 |
| day_book.print | reports | Accounting reports | Print day book | no | 1 |
| day_book.export | reports | Accounting reports | Export day book | no | 1 |
| cash_bank_book.view | reports | Accounting reports | View cash book and bank book | no | 2 |
| cash_bank_book.print | reports | Accounting reports | Print cash book and bank book | no | 2 |
| cash_bank_book.export | reports | Accounting reports | Export cash book and bank book | no | 2 |
| cancelled_documents.view | reports | Accounting reports | View cancelled documents | no | 1 |
| cancelled_documents.export | reports | Accounting reports | Export cancelled documents | no | 1 |
| sales_reports.view | reports | Sales and purchase reports | View sales reports | no | 5 |
| sales_reports.export | reports | Sales and purchase reports | Export sales reports | no | 1 |
| purchase_reports.view | reports | Sales and purchase reports | View purchase reports | no | 4 |
| purchase_reports.export | reports | Sales and purchase reports | Export purchase reports | no | 1 |
| receivables_reports.view | reports | Sales and purchase reports | View receivables and debtors | no | 2 |
| receivables_reports.export | reports | Sales and purchase reports | Export debtors | no | 1 |
| payables_reports.view | reports | Sales and purchase reports | View payables and creditors | no | 2 |
| payables_reports.export | reports | Sales and purchase reports | Export creditors | no | 1 |
| tax_reports.view | reports | Tax reports | View VAT books, VAT summary and TDS | no | 4 |
| tax_reports.export | reports | Tax reports | Export VAT books and VAT summary | no | 3 |
| stock_reports.view | reports | Stock reports | View stock reports and item ledgers | no | 6 |
| stock_reports.print | reports | Stock reports | Print item ledgers | no | 1 |
| stock_reports.export | reports | Stock reports | Export item ledgers | no | 1 |
| stock_valuation.view | reports | Stock reports | View stock valuation | no | 1 |
| activity_log.view | admin | Audit | View activity log | no | 1 |
| print_log.view | admin | Audit | View print log | no | 1 |
| backups.manage | admin | Backups | Manage backups | yes | 4 |
| notices.manage | admin | Notices | Manage dashboard notices | no | 4 |

Counts: core 37, sales 20, pos 1, agents 4, quotations 6, purchases 21, inventory 17, accounting 12,
reports 25, admin 4 = **147 keys**. Owner-only: exactly `roles.manage`, `backups.manage`,
`fiscal_year.close_archive`, `ownership.transfer`.

Keys with no route (enforced in code, not by `can:` middleware):

- `roles.manage`, `ownership.transfer`: routes arrive in P11/P12.
- `fiscal_year.edit`: replaces the in-code admin check in `App\Support\ClosedFiscalYearGuard::assertDateInOpenYear()`
  (`$actor?->role?->slug !== 'admin'`, "Only an admin may post into a reopened fiscal year"). Admin only today,
  so it is not a parity key. P06 swaps the slug check for `$actor->can('fiscal_year.edit')`.

Other in-code admin checks P05/P06 must convert (found by grep, not route-level):
`PurchaseController` and `CapitalPurchaseController` `canCancel` props (use `purchases.cancel`,
`capital_purchases.cancel`), `StoreUnlinkedPurchaseReturnRequest::authorize()` (use
`unlinked_purchase_returns.create`), `UserController` last-admin guard (P12).

## Staff parity keys (100)

Every key below covers only routes that are `any auth` today (none behind `role:admin`). P03 grants exactly this
list to the existing Staff role. Keys with 0 routes are excluded (none of them is reachable by Staff today).

| module | keys |
|---|---|
| core (26) | accounts.view, customers.view, customers.create, customers.edit, customers.delete, customers.import, suppliers.view, suppliers.create, suppliers.edit, suppliers.delete, suppliers.import, party_ledger.view, party_ledger.print, party_ledger.export, items.view, items.create, items.edit, items.delete, items.import, items.print, brands.view, brands.manage, item_categories.view, item_categories.manage, fiscal_year.view, fiscal_year.create |
| sales (14) | sales.view, sales.create, sales.print, sales.export, capital_sales.view, capital_sales.create, capital_sales.print, sales_returns.view, sales_returns.create, sales_return_requests.create, sales_returns.print, receipts.view, receipts.create, receipts.print |
| pos (1) | pos.view |
| agents (4) | agents.view, agents.create, agents.edit, agents.delete |
| quotations (6) | quotations.view, quotations.create, quotations.edit, quotations.delete, quotations.print, quotations.cancel |
| purchases (15) | purchases.view, purchases.create, purchases.print, purchases.export, capital_purchases.view, capital_purchases.create, capital_purchases.print, capital_purchases.export, capital_purchase_settlements.create, purchase_returns.view, purchase_returns.create, purchase_returns.print, purchase_returns.export, payments.view, payments.create |
| inventory (14) | stock_adjustments.view, stock_adjustments.create, stock_adjustments.print, opening_stock.import, stock_transfers.view, stock_transfers.create, stock_transfers.print, stock_conversions.view, stock_conversions.create, stock_conversions.print, stores.view, stores.manage, item_varieties.view, item_varieties.manage |
| accounting (6) | journal_vouchers.view, journal_vouchers.print, journal_vouchers.export, account_ledger.view, account_ledger.print, account_ledger.export |
| reports (14) | sales_reports.view, sales_reports.export, purchase_reports.view, purchase_reports.export, receivables_reports.view, receivables_reports.export, payables_reports.view, payables_reports.export, tax_reports.view, tax_reports.export, stock_reports.view, stock_reports.print, stock_reports.export, stock_valuation.view |

Admin-only keys (47, not granted to Staff): accounts.create, accounts.edit, accounts.delete,
opening_balances.import, opening_balances.cancel, fiscal_year.edit, fiscal_year.close_archive,
fiscal_year_archive.view, users.manage, roles.manage, ownership.transfer, sales.cancel, capital_sales.cancel,
sales_return_requests.manage, unlinked_sales_returns.create, sales_returns.cancel, receipts.cancel,
purchases.cancel, capital_purchases.cancel, capital_purchase_settlements.cancel, unlinked_purchase_returns.create,
purchase_returns.cancel, payments.cancel, stock_adjustments.cancel, stock_transfers.cancel,
stock_conversions.cancel, journal_vouchers.create, cash_bank_vouchers.create, journal_vouchers.cancel,
fixed_assets.view, fixed_assets.create, fixed_assets.manage, financial_statements.view,
financial_statements.print, financial_statements.export, day_book.view, day_book.print, day_book.export,
cash_bank_book.view, cash_bank_book.print, cash_bank_book.export, cancelled_documents.view,
cancelled_documents.export, activity_log.view, print_log.view, backups.manage, notices.manage.
(100 + 47 = 147.)

### Mixed keys (none remain)

Every place where a natural key would have mixed admin and non-admin routes was split so parity is exact:

| natural grouping | split into | why |
|---|---|---|
| chart of accounts reads vs writes | `accounts.view` (any auth) vs `accounts.create/edit/delete` (admin) | reads are open today, writes admin |
| opening balances template + import + reverse | template under `accounts.view` (any auth); `opening_balances.import`, `opening_balances.cancel` (admin) | putting the template under `.import` would take a download away from Staff; the template is a sheet of the chart Staff can already read |
| fiscal years | `fiscal_year.view/create` (any auth) vs `fiscal_year.close_archive` (admin today, owner only now) | |
| journal vouchers | `journal_vouchers.view/print/export` (any auth) vs `.create`, `.cancel`, `cash_bank_vouchers.create` (admin) | |
| sales returns | `sales_returns.create`, `sales_return_requests.create` (any auth) vs `unlinked_sales_returns.create`, `sales_return_requests.manage`, `sales_returns.cancel` (admin) | unlinked post and approve/reject are admin today |
| purchase returns | `purchase_returns.create` (any auth) vs `unlinked_purchase_returns.create` (admin, covers the quote endpoint too) | |
| capital purchase settlements | `.create` (any auth) vs `.cancel` (admin) | |

**Parity conflicts: none.** No Staff route loses access and no admin route becomes Staff-reachable.

Intended (non-parity) behaviour changes, by design of the plan, not by this map: non-owner admins lose
fiscal year close/reopen/relock/archive and backups (owner-only keys).

## Shared lookup endpoints and cross-module decisions

1. **Account ledger (`tenant.accounts.ledger`, `.print`, `.export`).** Two keys per route: `account_ledger.X`
   (any account, accounting module) or `party_ledger.X` (only accounts that belong to a customer or supplier,
   core module). A single `can:` middleware cannot express "either key, and the second only for party
   accounts", so P06 authorizes in `AccountController` (`can(account_ledger.X)` or
   `can(party_ledger.X) && account is a customer/supplier account`, else 403) and P09 lists these 3 routes as
   controller-authorized in the route-audit test. Open question: should agent accounts count as party accounts?
   This map says no (agents are their own module); P06 can widen it later without a key change.
2. **`tenant.items.lookup-barcode` -> `items.view`.** Consumers: Sales create form, POS, Purchase create
   (`PurchaseCreateStagingRow.vue`). It returns the full `Item` model including cost fields, so it is not
   allowlisted. `items.view` is `core` (always entitled), so the Cashier and Manager templates (P03/P11) must
   include `items.view`. A role with `sales.create` but not `items.view` gets a 403 on scan; P05/P09 should
   hide the scan box in that case.
3. **Quick-add from other forms.** `POST /customers` from the sale form and POS (`useSaleCreateCustomer.js`,
   `PosCustomerModal.vue`) stays `customers.create`; `POST /suppliers` and `POST /items` from the purchase form
   (`usePurchaseCreateQuickAdd.js`) stay `suppliers.create` / `items.create`. Rationale: creating a master record
   is a real privilege and a cashier template can include it explicitly; the UI hides the quick-add button
   without it.
4. **Sale note templates (`tenant.sales.note-templates.*`) -> `sales.create`.** Only used by the sale form's
   note picker; anyone who can create a sale manages the picker, as today.
5. **POS.** `pos.view` only opens the till; the sale itself posts to `POST /sales`, gated `sales.create`.
   A POS-only cashier needs `pos.view` + `sales.create` (+ `items.view`, and `customers.create` for walk-in quick-add).
6. **Quotation convert-to-sale -> `quotations.edit`.** The route must be gated by a key in the `quotations`
   module so disabling quotations actually closes it. P05 also checks `sales.create` in the controller, since
   the action creates a sale.
7. **Index pages host the create forms.** There is no separate `create` GET route for sales, purchases,
   returns, receipts, payments or stock documents: the form lives on the index page. So `X.create` is only
   usable with `X.view`. P11 (role editor) should auto-tick `.view` when `.create` is ticked, or P05 to P07
   should let the index render the form for a `.create`-only user. Flagged for the coordinator.
8. **Item ledger (`tenant.items.ledger*`) -> `stock_reports.view/print/export`,** per the P01 note. It is linked
   from the Items list and every stock report (`resources/js/lib/itemLedger.js`); P09 hides the Ledger row
   action without `stock_reports.view`.
9. **Purchase return unlinked quote (`tenant.purchase-returns.quote-unlinked`) -> `unlinked_purchase_returns.create`,**
   the same key as the post it previews (matches its current admin gate).
10. **Opening balance template -> `accounts.view`** (see Mixed keys). Opening stock template and import share
    `opening_stock.import`; both are any auth today.

## Decisions the coordinator should review

- `fiscal_year.close_archive` also covers **reopen** and **relock** (both admin today). Reason: a non-owner
  reopening a year only the owner may close undoes the owner's decision. Alternative: a grantable
  `fiscal_year.manage` for reopen/relock (adds one key, no parity effect).
- Singular `fiscal_year.*` / `fiscal_year_archive.*` nouns follow the mandated `fiscal_year.close_archive`;
  every other resource is plural.
- `sales_return_requests.manage` means approve/reject (no `approve` action exists in the convention).
- Granting all 100 parity keys keeps Staff exactly as today, including some powerful any-auth routes worth a
  later tightening pass (not in scope here): customer/supplier/item delete and import, `opening_stock.import`,
  `fiscal_year.create`, `stores.manage`, `quotations.cancel`, and the full account ledger.
