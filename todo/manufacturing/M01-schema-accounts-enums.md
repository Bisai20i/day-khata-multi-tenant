# M01 Schema, accounts, enums, models

Phase 1 (alone) | Implements MC1, MC2, MC4, the schema of MC5-MC7 and the MC9 table | Agent: builder

## Owned files

New: `app/Enums/InventoryClass.php`, `app/Enums/DirectCostType.php`,
`app/Support/Manufacturing/ManufacturingAccounts.php`, `app/Models/MaterialIssueNote.php`,
`MaterialIssueItem.php`, `ProductionEntry.php`, `ProductionDirectCost.php`, `ProductionCompletion.php`,
`ProductionWipEntry.php`, `FiscalYearInventorySnapshot.php` (relations, fillable, `App\Casts\Decimal` casts and
enum casts only; NO posting logic), a factory for each, migrations with prefix `2026_10_25_10xxxx`.

Edit: `app/Enums/StockMovementType.php`, `app/Enums/VoucherType.php` (plus any exhaustive match/label/prefix list
that must learn the new cases: grep for `VoucherType::Contra` and `StockMovementType::RepackagingOut`),
`app/Models/Account.php` (SYSTEM_CODES only), `database/seeders/Tenant/ChartOfAccountsSeeder.php`,
`app/Http/Controllers/Concerns/DescribesStockMovements.php` (labels only), `app/Models/Item.php` (cast,
`isPerpetual()` and `inventoryAccountId()` only), `database/factories/ItemFactory.php` (states `rawMaterial()`
and `finishedGood()`), new tests under `tests/Feature/Tenant/Manufacturing/` and `tests/Unit/`.

## Tasks

- [x] 1. Migrations:
  - Accounts AS12, AS13, AS14, EXE10, EXE23 and EXE24. Idempotent. `down()` deletes only unused accounts. Resolve
    group and subgroup by name, as the 2026_09_13_110000 migration does.
  - Columns `items.inventory_class` and `item_stock_movements.cost_value`.
  - Tables `material_issue_notes`, `material_issue_items`, `production_entries`, `production_direct_costs`,
    `production_completions`, `production_wip_entries` and `fiscal_year_inventory_snapshots`, exactly as
    MC5-MC7 and MC9 describe.
  - Short explicit index names (MySQL 64-character rule). In `down()`, drop the FK before the unique index. Safe
    on both SQLite and MySQL.
- [x] 2. Code:
  - Enums: MC1, MC4 and DirectCostType. Wire the new movement and voucher cases into every exhaustive match.
  - `ManufacturingAccounts` (MC2).
  - Seeder and `SYSTEM_CODES`.
  - Model skeletons and factories.
  - Item cast and helpers.
- [x] 3. Tests (write, do not run):
  - The migration creates the six accounts once, under the right groups. Running it twice is idempotent.
  - `InventoryClass::isPerpetual()` and its account codes.
  - The new movement types have the right direction.
  - `Item::inventoryAccountId()` throws a clear message when AS12 is missing.
