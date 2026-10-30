# M05a Perpetual hooks: purchases, adjustments, transfers, conversions

Phase 3 (parallel) | Implements MC8 (purchase side and stock documents) | Agent: builder-high

## Owned files

- `app/Models/Purchase.php`, `PurchaseReturn.php`, `StockAdjustment.php`, `StockTransfer.php` and
  `StockConversion.php`.
- Their controllers and FormRequests, only where validation changes.
- `resources/js/pages/Tenant/Inventory/StockAdjustments/*.vue`: the rate is required when a perpetual item comes
  in.
- `resources/js/pages/Tenant/Inventory/StockConversions/*.vue`: hide the `production` type for new entries.
- Tests:
  - New: `tests/Feature/Tenant/Manufacturing/PerpetualPurchaseHooksTest.php` and
    `PerpetualStockDocumentHooksTest.php`.
  - Any existing test whose expectation changes. List them in the report.

## Tasks

- [x] 1. Purchase and purchase return, on both post and cancel:
  - Perpetual lines hit the class account.
  - Movements carry cost_value, per MC8.
  - A purchase that mixes trading and raw lines still produces one balanced voucher.
- [x] 2. Stock documents:
  - Stock adjustment: valued, voucher type InventoryCost, against EXE23.
  - Opening import: perpetual lines go to their class account instead of AS11.
  - Transfer: both legs carry the same cost_value.
  - Conversion: rejects perpetual items. The `production` type is hidden in the create form and rejected server
    side for new entries.
- [x] 3. Tests (write, do not run):
  - A raw purchase posts Dr AS12, not EXE8.
  - A purchase return credits AS12 at the original cost share.
  - Cancelling restores P1.
  - A perpetual adjustment out posts Dr EXE23 / Cr AS12 at the moving average.
  - Importing opening stock of a finished good posts Dr AS14.
  - A transfer leaves both AS12 and the subledger unchanged.
  - Conversion rejects a raw item.
