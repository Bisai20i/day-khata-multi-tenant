# Quick Reference: Architectural Contracts (C1–C10)

For full details, see `todo/CONTRACTS.md`. Below is the essential quick-reference.

| Contract | Core Invariant | Key Classes / Locations |
|---|---|---|
| **C1. Money & Quantity** | `Money` (scale 2), `Quantity` (scale 4). Immutable, exact decimal math via `brick/math`. Rounding: `HalfUp`. Banned: `(float)`, `round()`, `floatval()`, `.toFixed()`. | `App\Support\Money\Money`, `App\Support\Money\Quantity`, `resources/js/lib/money.js` |
| **C2. Decimal Eloquent Cast** | Replaces `'decimal:N'`. Throws `InvalidAmount` if setting a value with more decimals than the scale (prevents silent DB rounding). | `App\Casts\Decimal` |
| **C3. DocumentCalculator** | Deterministic calculation engine for Sales, Purchases, Returns, Quotations. Header discount allocated proportionally using largest-remainder algorithm. | `App\Support\Billing\DocumentCalculator`, `resources/js/lib/money.js::calculateDocument` |
| **C4. Cancellation & Reversals** | Cancellations are permitted **only while the fiscal year remains open**. A cancellation never deletes rows; it posts a dated Reversal journal voucher and adjusts inventory movements. | `VoucherType::SaleReversal`, `VoucherType::PurchaseReversal` |
| **C5. VAT & TDS Period Integrity** | Cancelled vouchers take effect on the cancellation date, ensuring previously reported VAT/TDS return periods remain unaltered. | Reporting queries, `SalesVatBook`, `PurchaseVatBook` |
| **C6. Periodic Stock Valuation** | Weighted average cost basis. Unit conversions (Box to Pcs) adjust base quantity and unit cost proportionally. Stock movements track transactions without perpetual auto-posting. | `ItemStockMovement`, `ItemCostCalculator` |
| **C7. Gapless Sequential Numbering** | Distinct sequence counters per invoice type (Full Tax, ABIT, PAN, Reversals). Support starting-number configuration for mid-year tenant onboarding. | `CompanySetting`, `VoucherNumberGenerator` |
| **C8. Tenant DB Isolation** | Physical database per tenant (`stancl/tenancy`). SQLite in dev/tests, MySQL 8 in production. All cross-tenant queries forbidden. | Central vs Tenant database connections |
| **C9. Route & Permission Mapping** | Every tenant route carries `->middleware('can:<permission>')`. Permissions defined in `config/permissions.php`. | `config/permissions.php`, `RouteAuthorizationAuditTest` |
| **C10. UI Component Sizing** | Vue SFCs kept around ~500 lines (max 750). Composable extractions for shared table/filter logic. Flat corners, purple theme tokens. | `resources/css/app.css`, `resources/js/components/` |
