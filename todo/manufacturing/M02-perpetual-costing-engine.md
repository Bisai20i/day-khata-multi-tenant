# M02 Perpetual costing engine

Phase 2 (alone, after M01 is committed) | Implements MC1 immutability and MC3 | Agent: builder-high

## Owned files

- New: `app/Support/Inventory/PerpetualCosting.php`.
- Edit:
  - `app/Models/Item.php`: the trailing `$costValue` parameter on `recordStockMovement`, per MC3.
  - `app/Support/Inventory/StockCosting.php`: `totalPeriodicClosingValue`, and perpetual-aware `valuationRows`.
  - `app/Http/Controllers/Tenant/Inventory/ItemController.php` and its FormRequest(s): the inventory_class field
    on create, update and import, and the immutability rule.
  - `resources/js/pages/Tenant/Inventory/Items/*.vue`: the class select. Extract a child component if the file
    gets close to 750 lines (see `.ai/rules/js.md`).
- Tests:
  - New: `tests/Unit/Support/PerpetualCostingTest.php` and
    `tests/Feature/Tenant/Manufacturing/ItemInventoryClassTest.php`.
  - Existing `StockCostingTest`, only if a case must change. List any such change in the report.

## Tasks

- [x] 1. `PerpetualCosting`, exactly as MC3 specifies:
  - Sums use scaled integers in SQL.
  - The average is carried at 12 decimals, with a single rounding.
  - Issuing the whole quantity balance takes the whole value balance.
  - The fallback chain applies when there is no average.
  - `recordStockMovement` fills `cost_value` for perpetual items and throws for an unpriced perpetual
    in-movement.
- [x] 2. Periodic split and item class: (the modal select was added by the coordinator in `components/inventory/ItemsIndexFormModal.vue`)
  - `StockCosting::totalPeriodicClosingValue()` excludes perpetual items.
  - `valuationRows()` values perpetual items with `valueBalance()` and returns `inventory_class`.
  - Do NOT change any caller yet. M06 switches the year-end close and the report.
  - Item form: a class select, Trading by default. Stockable is enforced for the perpetual classes.
  - The class is locked once any movement exists: a server rule, a disabled select and a hint.
- [x] 3. Tests (write, do not run):
  - Buy 10 @ 100 and 10 @ 130, then issue 15: the cost is 1725.00 and the balance 575.00.
  - Issue the remaining 5: the cost is exactly 575.00, with no residue.
  - Three purchases at awkward rates, then issue everything: the value balance is 0.00.
  - A backdated purchase does not re-cost an earlier issue.
  - Falls back to purchase_rate.
  - An unpriced perpetual in-movement throws.
  - Transfer legs net to zero.
  - The periodic total excludes a raw item.
  - A class change is rejected after the first movement.

## Checkpoint (M02 agent, mid-task)

- Done and lint-clean: `PerpetualCosting` (new), `Item::recordStockMovement(..., $costValue)` (cost_value set via
  forceFill because `ItemStockMovement` has no fillable/cast for it: cross-file request), `StockCosting::
  totalPeriodicClosingValue` + perpetual-aware `valuationRows` (adds `inventory_class` key and filter),
  `ItemController` (class on create/update/import, stockable rule, immutability re-checked under row lock),
  `Items/Index.vue` passes `inventoryClasses` to the form modal.
- Not in ownership: `resources/js/components/inventory/ItemsIndexFormModal.vue` holds the form; the class select
  there is a cross-file request.
- Tests written (not run): `tests/Unit/Support/PerpetualCostingTest.php`, `tests/Feature/Tenant/Manufacturing/ItemInventoryClassTest.php`. Remaining: only the modal select (cross-file).
