# T08 Inventory and costing

Phase 2 | Parallel with T03-T07, T09 | Implements C10 | Audit refs: P0-11 (stock documents bypass the fiscal
year guard), P0-17 (cost basis, valuation), P1 float quantity guards, store-scoped and aggregated guards,
conversion factor drift, item posting account, opening stock import stacking.

## Owned files

`app/Models/Item.php`, `app/Models/ItemUnit.php`, `app/Models/ItemStockMovement.php`,
`app/Enums/StockMovementType.php`, `app/Enums/StockAdjustmentReason.php`, `app/Models/StockAdjustment.php`,
`app/Models/StockAdjustmentLine.php`, `app/Models/StockTransfer.php`, `app/Models/StockTransferLine.php`,
`app/Models/StockConversion.php`, `app/Models/StockConversionLine.php`, new
`app/Support/Inventory/StockCosting.php`, `app/Http/Controllers/Tenant/Inventory/ItemController.php`,
`StockAdjustmentController.php`, `StockTransferController.php`, `StockConversionController.php`,
`routes/tenant-stock-adjustments.php`, `routes/tenant-stock-transfers.php`,
`routes/tenant-stock-conversions.php`, `resources/js/pages/Tenant/Inventory/Items/Index.vue`,
`resources/js/pages/Tenant/Inventory/StockAdjustments/*.vue`, `StockTransfers/*.vue`,
`StockConversions/*.vue`, `resources/views/pdf/stock-adjustment.blade.php`, `stock-transfer.blade.php`,
`stock-conversion.blade.php`, `database/factories/ItemFactory.php`, `ItemUnitFactory.php`,
`tests/Feature/Tenant/Inventory/*` (all existing files there), new tests under
`tests/Feature/Tenant/Inventory/` and `tests/Unit/Support/`. Migrations with prefix `2026_09_12_08`.

## Tasks

- [ ] 1. Migration: `item_stock_movements.value` decimal(15,2) nullable (C10). Backfill best effort:
  purchase movements from their purchase line's net value (line total minus its share of the header
  discount), opening stock and priced adjustments from `quantity x unit_cost_rate`, others null. Recompute
  `unit_cost_rate` per base unit for purchase movements. Idempotent.
- [ ] 2. `Item::recordStockMovement()`, `currentStock()`, `currentStockByItem()`, `lockForStockOut()` exactly
  per C10. `currentStock` via SQL `SUM(CASE ...)` on DECIMAL returning `Quantity`. Casts to `Decimal`.
- [ ] 3. `StockCosting` per C10 (weighted average over priced in-movements up to the date, purchase returns
  subtract, transfers excluded, single rounding for value, fallback to `items.purchase_rate`). Document the
  method in the class docblock (it is the valuation policy the user chose: weighted average, fixed basis).
- [ ] 4. Stock documents (adjustment, transfer, conversion, opening stock import): every posting and cancel
  goes through `ClosedFiscalYearGuard::assertDateInOpenYear()` (C4); out-guards are store-scoped, aggregate
  several lines of the same item, lock items via `lockForStockOut()`, compare with `Quantity` exactly (the
  `0.19999999999999998` message disappears). Adjustments-in accept a rate and record `value`; conversions keep
  the deferred output costing but must record input values consistently. Cancels lock and re-check.
- [ ] 5. Opening stock import: re-importing replaces the previous opening batch (cancel the prior opening
  adjustment, then post the new one) with a warning in the UI, and posts the ledger entry Dr `AS11` / Cr the
  opening-balance equity or capital account used by the opening balance import (read
  `ChartOfAccountsSeeder` and T03's import to pick the same account; document it). Value = sum of line values.
- [ ] 6. Items: `ItemController` + `Items/Index.vue` get a posting-account select (Expense or Fixed Asset
  groups) for service/capital items, also as an import column; unit conversion factor must be `>= 1` with at
  most 4 decimals (the smallest unit is the base unit; explain this in the form hint); block deactivating an
  item with stock on hand; friendly error instead of an FK exception on delete; quantities shown with
  `formatQuantity`, rates with `formatRate`.
- [ ] 7. Stock pages: previews via `money.js` (`multiplyMoney` per line), 4dp quantities, default date
  `todayInKathmandu()`, list pages with pagination and a date filter (adjustments), BS dates.
- [ ] 8. Tests (write, do not run): currentStock exact after 0.1 + 0.2 - 0.3 movements; valuation of a Box
  purchase per base unit with discounts; 100,000 @ 1 + 200,000 @ 2 values at 500,000.00 exactly; transfer does
  not change the all-stores valuation; fallback to purchase rate; stock document dated in a closed year
  rejected; store-scoped adjustment guard with two lines of the same item; conversion factor below 1 rejected;
  opening stock re-import replaces and posts the AS11 entry once.
