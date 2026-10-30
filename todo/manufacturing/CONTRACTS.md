# Manufacturing contracts (MC1-MC10)

Every M-task codes against these names and behaviours. An implementer that must deviate says so in its report;
the coordinator updates this file before the next phase. The root `todo/CONTRACTS.md` (C1-C10: Money/Quantity,
Decimal cast, fiscal-year guard, reversal vouchers, StockCosting) still applies in full.

Decisions taken by the user on 2026-10-25 (do not re-ask):

| Topic | Decision |
|---|---|
| Accounting model | **Hybrid perpetual.** Items classed Raw Material or Finished Good post real-time journals (RM Inventory, WIP, FG Inventory, COGS). Trading items stay periodic exactly as today (AS11 / EXE9 / INI22 at year-end). Nothing is valued twice. |
| Existing Stock Conversion | Kept for **refining and repackaging**. Its `production` type is no longer offered for new entries (old rows still display). Perpetual items are rejected by Stock Conversion. |
| Valuation | **Weighted average**, no FIFO. Perpetual items use a *moving* weighted average whose value balance equals the GL account balance exactly (MC3). |
| Execution | Plan files, then phased build with builder agents and user-run test gates (see `START.md`). |

## Mapping of the original spec's table names to this codebase

| Spec name | Implemented as | Why |
|---|---|---|
| `raw_items`, `finished_goods` | `items.inventory_class` (`trading` / `raw_material` / `finished_good`) | One item master; a second item table would split every report, unit and price list. |
| `inventory_ledgers` | `item_stock_movements` + new `cost_value` column (RM and FG), new `production_wip_entries` (WIP) | `item_stock_movements` is already the stock ledger every report reads. |
| `journal_entries` | `journal_vouchers` / `journal_voucher_lines` | Numbering, reversal, closed-year guard and archiver already live there. |
| `fiscal_years` | existing `fiscal_years` + new `fiscal_year_inventory_snapshots` | The year-end close already exists; it gains snapshots and reconciliation. |
| `material_issue_notes`, `material_issue_items`, `production_entries`, `production_direct_costs` | new tables of those names, plus `production_completions` | As specified. Completions are their own rows so partial output can post several times. |

---

## MC1. Inventory class (implemented by M01)

- Enum `App\Enums\InventoryClass: string`: `Trading = 'trading'`, `RawMaterial = 'raw_material'`,
  `FinishedGood = 'finished_good'`. Methods: `isPerpetual(): bool` (true for the two manufacturing classes),
  `inventoryAccountCode(): ?string` (`AS12` / `AS14`, null for Trading), `label(): string`.
- Column `items.inventory_class` string(20), NOT NULL, default `'trading'`, indexed. Cast to the enum on `Item`.
- `Item::isPerpetual(): bool` and `Item::inventoryAccountId(): ?int` (resolves the class's account by code;
  throws `RuntimeException` with a "run the tenant migrations" message when the account is missing).
- A perpetual item must be `is_stockable = true`.
- **The class is immutable once the item has any stock movement, cancelled ones included.** Changing it would
  leave its value in the wrong account. The item form shows the select disabled with a hint; the server rejects
  the change with a validation error. Converting a stocked trading item is deferred (see `DEFERRED` in START).

## MC2. Accounts (implemented by M01)

New system accounts, created by an idempotent migration modelled on
`2026_09_13_110000_add_trading_stock_accounts_to_chart_of_accounts.php`, also added to
`ChartOfAccountsSeeder` and `Account::SYSTEM_CODES`:

| Code | Name | Placement | Use |
|---|---|---|---|
| `AS12` | Raw Material Inventory | Current Assets > subgroup Stock | RM purchases, issues, returns |
| `AS13` | Work in Process | Current Assets > subgroup Stock | Secondary inventory |
| `AS14` | Finished Goods Inventory | Current Assets > subgroup Stock | Completions, COGS relief |
| `EXE10` | Cost of Goods Sold | Expenses > group Purchase Accounts (trading section) | Sale of perpetual items |
| `EXE23` | Inventory Adjustment | Expenses > group Indirect Expenses | Valued adjustments of perpetual items (both directions) |
| `EXE24` | Production Loss | Expenses > group Indirect Expenses | WIP written off when a production entry is abandoned |

Constants on a new `App\Support\Manufacturing\ManufacturingAccounts` class: `RAW_MATERIAL = 'AS12'`,
`WIP = 'AS13'`, `FINISHED_GOODS = 'AS14'`, `COGS = 'EXE10'`, `ADJUSTMENT = 'EXE23'`, `PRODUCTION_LOSS = 'EXE24'`,
plus `static id(string $code): int` (uncached, throws like MC1).

## MC3. Perpetual costing (implemented by M02)

`App\Support\Inventory\PerpetualCosting`, the only place perpetual cost is computed.

- New column `item_stock_movements.cost_value` decimal(15,2) nullable. Meaning: the **cost** this movement
  added to (in-direction) or removed from (out-direction) the item's perpetual value, always positive, sign from
  `StockMovementType::direction()`. Always null for trading items. Never null for a perpetual item's movement.
  The existing `value` column keeps its current meaning (sales store selling value there) and is untouched.
- **Invariant P1:** for every perpetual item, `sum(direction * cost_value)` over non-cancelled movements equals
  that item's share of its class account. Per class, the sum over items equals the GL balance of `AS12` / `AS14`.
- `static movingAverage(Item $item, string $asOf): ?BigDecimal` = value balance / quantity balance over
  non-cancelled movements dated `<= $asOf`, at 12 decimals. Null when the quantity balance is `<= 0` or nothing
  priced exists.
- `static issueCost(Item $item, Quantity $quantity, string $date): Money`, called **after** the item is locked:
  - If `$quantity` equals the whole quantity balance as of `$date`, return the whole value balance (no residue).
  - Else `Money::round(quantity x movingAverage)`.
  - Fallbacks in order when `movingAverage` is null: last in-direction `cost_value / quantity` of the item,
    then `items.purchase_rate`, else throw `InvalidArgumentException` ("no cost basis for <item>").
- `static valueBalance(Item $item, ?string $asOf = null, ?int $storeId = null): Money` and the batch form
  `valueBalanceByItem(array $itemIds, ?string $asOf)`. Summed as scaled integers in SQL, like `basisByItem`.
- `static classValue(InventoryClass $class, string $asOf): Money` (subledger total per class).
- `Item::recordStockMovement()` gains an optional trailing `Money|string|null $costValue = null`. For a
  perpetual item: in-direction requires either `$costValue` or `$value` (falls back to `$value`, the purchase
  net value) and throws if both are null; out-direction computes `issueCost()` when `$costValue` is null. The
  stored `cost_value` is returned on the created movement so the caller can post the same figure to the GL.
- Backdating: an out-movement is costed at the moving average **as of its own date**. A later backdated
  purchase does not re-cost earlier issues. P1 still holds because GL and subledger both use the posted figure.
- `StockCosting` (periodic) gains `totalPeriodicClosingValue(string $asOf, ?int $storeId = null): Money`, which
  excludes perpetual items. `valuationRows()` keeps listing perpetual items but values them with
  `valueBalance()` and adds an `inventory_class` key, so stock reports show one consistent figure per item.

## MC4. Movement types and voucher types (implemented by M01)

New `StockMovementType` cases, all priced with `cost_value`:

| Case | Value | Direction | Written by |
|---|---|---|---|
| `MaterialIssue` | `material_issue` | -1 | RM out of Main Inventory into WIP |
| `MaterialReturn` | `material_return` | +1 | Unused RM back from WIP |
| `ProductionReceipt` | `production_receipt` | +1 | FG into Main Inventory on completion |

`StockCosting::basisInTypes()` must not change behaviour for trading items. These three types only ever occur
on perpetual items, which the periodic basis no longer values. `DescribesStockMovements` gets labels for all three.

New `VoucherType` cases: `MaterialIssue = 'material_issue'`, `Production = 'production'` (direct costs,
completions, material returns, abandon), `ProductionEntry = 'production_entry'` (a header-only number series for
production entries: no voucher ever carries it), `InventoryCost = 'inventory_cost'` (stand-alone perpetual
postings such as valued adjustments). Add each to any exhaustive `match`/label/prefix list in the codebase.

## MC5. Material Issue Notes (implemented by M03)

Tables `material_issue_notes` (fiscal_year_id, number, date, store_id, production_entry_id nullable,
total_cost decimal(15,2), narration, status `posted|cancelled`, journal_voucher_id, reversal_journal_voucher_id,
cancel fields as on `StockConversion`, created_by; unique `(fiscal_year_id, number)` with a short index name)
and `material_issue_items` (material_issue_note_id, item_id, item_unit_id nullable, quantity (entered unit),
base_quantity, unit_cost_rate decimal(15,4), line_cost decimal(15,2), stock_movement_id).

`MaterialIssueNote::post(array $data, array $lines, User $actor): self`:
1. `DB::transaction`. Lock order: production entry (if given) -> items (`Item::lockForStockOut`) -> fiscal year.
2. `ClosedFiscalYearGuard::assertDateInOpenYear()`; every item must be `RawMaterial`; positive quantities;
   **store-scoped stock check aggregated per item, ignoring `allow_negative_stock`** (negative raw stock would
   make the moving average meaningless). The error names the item and the shortfall.
3. One `MaterialIssue` movement per line with `cost_value = issueCost()`.
4. One voucher, type `MaterialIssue`, its number is the note's number: Dr `AS13` / Cr `AS12` for the total.
5. One `production_wip_entries` row per line (MC7, kind `material_in`).
- A note may be posted unattached (`production_entry_id` null) and attached later by a production entry.
  `cancel(User $actor, string $reason)`: only while unattached, only in the open year (C4/C5), reverses the
  voucher with `JournalVoucher::reverse`, cancels the movements and WIP rows.

## MC6. Production Entries (implemented by M04)

Tables:
- `production_entries`: fiscal_year_id, number (series `ProductionEntry`), date, store_id, finished_item_id
  (must be `FinishedGood`), planned_quantity decimal(15,4) > 0 in base units, status
  `in_progress|completed|abandoned|cancelled`, narration, completed_at, created_by, cancel fields.
- `production_direct_costs`: production_entry_id, date, cost_type (`App\Enums\DirectCostType`: `Labor`,
  `Utilities`, `Machine`, `Handling`, `Other`), description, amount decimal(15,2) > 0, credit_account_id (any
  cash, bank, payable or liability account; validated against the chart), journal_voucher_id, cancel fields.
- `production_completions`: production_entry_id, date, quantity decimal(15,4) > 0, cost decimal(15,2),
  unit_cost decimal(15,4), is_final bool, stock_movement_id, journal_voucher_id, cancel fields.

Behaviour, each a static or instance method on `App\Models\ProductionEntry`, each in one transaction that
locks the production entry row first:
- `create(data, materialIssueNoteIds, actor)`: **at least one** posted, unattached, same-store Material Issue Note
  is required (the spec's mandatory reference). Attaching moves the notes' WIP rows to the entry.
  `attachIssueNotes(ids, actor)` adds more while `in_progress`.
- `addDirectCost(data, actor)`: voucher type `Production`, Dr `AS13` / Cr `credit_account_id`; WIP row kind
  `direct_cost`. `cancelDirectCost(cost, actor, reason)` reverses while the entry is `in_progress`.
- `returnMaterial(itemId, quantity, actor)`: only up to the item's WIP quantity balance on this entry. It is
  costed at the **WIP rate of that item on this entry** (its WIP value / WIP quantity), not the store average.
  Posts a `MaterialReturn` movement (cost_value = that cost), Dr `AS12` / Cr `AS13`, and a WIP row kind
  `material_out`.
- `complete(date, quantity, bool isFinal, actor)`:
  - `remainingPlanned = planned_quantity - sum(non-final completed quantity)`.
  - Non-final: `cost = Money::round(wipBalance x quantity / remainingPlanned)`. Rejected when
    `quantity >= remainingPlanned` (the user must mark it final).
  - Final: `cost = wipBalance` (the whole remainder, so **no orphaned WIP**). Status becomes `completed`.
  - `unit_cost = cost / quantity` (4dp, display only). Posts a `ProductionReceipt` movement on the finished item
    with `cost_value = cost`, Dr `AS14` / Cr `AS13`, and a WIP row kind `completion` (value out).
  - Material quantities are consumed in the WIP ledger in the same proportion (final consumes the remainder).
  - Rejected when the WIP balance is zero or negative.
- `abandon(actor, reason)`: Dr `EXE24` / Cr `AS13` for the WIP balance; status `abandoned`.
- `cancel(actor, reason)`: only with no live completions. It reverses direct costs and detaches the notes back
  to unattached (their own vouchers stay). Open year only.
- Cost sheet: `costSheet(): array` returns materials per item (issued, returned, net qty, net cost), direct
  costs per type, total production cost, completed quantity, cost per unit
  (`total cost transferred to FG / total completed quantity`) and the remaining WIP balance.
  Formula (spec): **Total Production Cost = sum(raw material used cost) + other direct costs; Cost per Unit =
  Total Production Cost / Finished Units Produced.**

## MC7. WIP ledger (implemented by M01 schema, written by M03/M04)

`production_wip_entries`: material_issue_note_id nullable, production_entry_id nullable, date, kind
(`material_in|material_out|direct_cost|completion|abandon`), item_id nullable (materials only), quantity
decimal(15,4) nullable, value decimal(15,2) positive, direction (+1/-1), source_type/source_id morph, cancelled.

**Invariant P2:** sum(direction x value) over non-cancelled rows equals the GL balance of `AS13`.
`App\Support\Manufacturing\WipLedger` exposes `balance(?ProductionEntry, ?string asOf): Money`,
`quantityByItem(ProductionEntry): array<int, Quantity>`, `valueByItem(ProductionEntry): array<int, Money>`.

## MC8. Perpetual hooks in existing documents (implemented by M05a / M05b)

Each one is in the same transaction and the same voucher as today, so cancel/reverse mirrors it automatically.
- **Purchase / purchase return:** a perpetual line debits (credits on return) the item's class account instead
  of `item.account_id`/`EXE8`. The movement's `cost_value` = the line's net value (the existing `value`). A
  return's `cost_value` = the original line's cost share.
- **Sale / POS / sale return:** for each perpetual line, add Dr `EXE10` / Cr class account at the movement's
  `cost_value`. A return adds Dr class account / Cr `EXE10` at the **original sale movement's cost share**
  (proportional to quantity), or at `issueCost` for an unlinked return.
- **Stock adjustment:** an adjustment of a perpetual item is always valued: in at the entered rate (required),
  out at `issueCost`. Voucher type `InventoryCost`: Dr/Cr class account against `EXE23`. The opening-stock
  import posts perpetual lines to their class account instead of `AS11`.
- **Stock transfer:** both legs carry the same `cost_value` (net zero), no voucher.
- **Stock conversion:** rejects perpetual items; `production` type hidden for new entries.

## MC9. Fiscal year (implemented by M06)

- `FiscalYear::postStockTradingEntries()` and `AccountingReportController::stockPosition()` use
  `StockCosting::totalPeriodicClosingValue()`; perpetual accounts are ordinary balance-sheet accounts that
  `postOpeningBalances()` already carries forward.
- `App\Support\Manufacturing\InventoryReconciliation::check(FiscalYear $fy, string $asOf): array` returns per
  class `{gl, subledger, difference}` for RM, WIP and FG. **`close()` refuses** (InvalidArgumentException naming
  the class and the difference) when any difference is non-zero.
- Table `fiscal_year_inventory_snapshots`: fiscal_year_id, inventory_class (`raw_material|wip|finished_good|trading`),
  item_id nullable (null for WIP rows, which carry production_entry_id instead), production_entry_id nullable,
  quantity, value, created_at. Written by `close()` after reconciliation, deleted and rewritten on a re-close after
  reopen/relock. They are the **verified closing = next year's opening** figures that the reports read for
  "Opening RM/WIP/FG".
- Locking of stock history is the existing closed-year guard (C4), which every new document calls.

## MC10. Reports (implemented by M07)

All in `App\Support\Manufacturing\ManufacturingReports`, controllers thin, Money strings in props:
- **Stock ledger** per class (RM, WIP, FG): date, document, in qty, out qty, balance qty, in value, out value,
  balance value, from `cost_value` (RM/FG) or `production_wip_entries` (WIP). Opening row from the snapshot or
  from the balance as of the day before.
- **Production cost sheet**: MC6 `costSheet()`, printable.
- **Inventory schedule** (Balance Sheet note): Raw Material, WIP, Finished Goods (GL balances) plus trading
  Stock in Hand, with a total equal to the Balance Sheet's stock lines.
- **COGM / COGS schedule** (P&L note):
  `RM consumed = Opening RM + RM purchases (net of returns) - Closing RM - RM adjustments`;
  `Total manufacturing cost = RM consumed + direct costs`;
  `COGM = Total manufacturing cost + Opening WIP - Closing WIP - Production loss`;
  `COGS = Opening FG + COGM - Closing FG +/- FG adjustments`.
  The computed COGS must equal the `EXE10` balance for the period. A mismatch is shown as a red reconciling line,
  never hidden. Closing inventory is never shown as income for perpetual classes (the existing INI22 row stays
  trading-only).

---

## Addenda after the parallel build (2026-10-25, coordinator)

- **MC6 signatures as built:** `ProductionEntry::create(array $data, array $materialIssueNoteIds, User $actor): self`,
  `attachIssueNotes(array $ids, User $actor)`, `addDirectCost(array $data, User $actor): ProductionDirectCost`,
  `cancelDirectCost(ProductionDirectCost, User, string $reason)`, `returnMaterial(int $itemId, string $quantity,
  string $date, User $actor)` (takes a date), `complete(string $date, string $quantity, bool $isFinal, User): ProductionCompletion`,
  `abandon(User, string $reason)`, `cancel(User, string $reason)`, `costSheet(): array`, `remainingPlannedQuantity(): Quantity`.
  `create` shadows Eloquent's magic static `create`: never call `ProductionEntry::create([...])` to insert a row.
- **Direct-cost credit accounts:** one definition, `ProductionDirectCost::creditAccountQuery()`: Current Assets accounts
  outside the Stock and Sundry Debtors subgroups (cash and bank, there is no bank flag) plus every Liabilities account.
- **Control accounts:** `ManufacturingAccounts::CONTROL_CODES` (AS12, AS13, AS14) are refused by the manual journal,
  cash/bank voucher and opening balance import entry points (`assertNotControlAccounts`). Only stock documents move them.
- **MC5:** a note may also be posted already attached to an in-progress entry of the same store.
- **MC8 deviations accepted:** a non-import Opening adjustment of a perpetual item credits Profit & Loss (equity), not
  EXE23; a linked purchase return's cost_value is the credited net share; transfer documents keep the typed line value
  while the movements carry the true cost. Perpetual lines with negative quantities on sales and unlinked returns are
  refused.
- **MC9:** `relock()` counts as the re-close (reconciles, then rewrites snapshots). WIP snapshot rows carry value only
  (quantity 0.0000, materials are in mixed units).
- **MC10 formula as built:** adjustments post to EXE23, not EXE10, so the net adjustment is deducted from RM consumed and
  from COGS; raw material sold directly and finished goods bought in get their own lines. The computed COGS then equals
  EXE10 exactly.
