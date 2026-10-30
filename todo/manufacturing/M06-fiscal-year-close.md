# M06 Fiscal year: periodic split, reconciliation, snapshots

Phase 4 (parallel with M04b, M07) | Implements MC9 | Agent: builder-high

## Owned files

- `app/Models/FiscalYear.php`.
- New `app/Support/Manufacturing/InventoryReconciliation.php`.
- `app/Models/FiscalYearInventorySnapshot.php`: the logic.
- `app/Http/Controllers/Tenant/Reports/AccountingReportController.php`: `stockPosition()` ONLY. M07 owns the new
  report methods in its own controller.
- Tests:
  - New: `tests/Feature/Tenant/Manufacturing/FiscalYearManufacturingCloseTest.php`.
  - Any existing year-close test whose expectation changes.

## Tasks

- [x] 1. Periodic split:
  - The year-end close and `stockPosition()` use `totalPeriodicClosingValue()`.
  - Perpetual accounts carry forward as ordinary balance-sheet accounts. Verify this; do not duplicate it.
- [x] 2. Reconciliation and snapshots:
  - `InventoryReconciliation::check()`.
  - `close()` refuses on any difference.
  - Snapshots are written after reconciliation and rewritten on a re-close after reopen and relock.
  - Reopening leaves the snapshots in place until the re-close.
- [x] 3. Tests (write, do not run):
  - A year with both trading and manufacturing activity closes.
  - AS11's closing balance equals the trading stock only.
  - AS12, AS13 and AS14 carry forward with the same balances.
  - The snapshots equal the reconciled figures.
  - A deliberately corrupted cost_value blocks the close, with a message naming the class.
  - In-progress production WIP survives into the new year and can be completed there.

Checkpoint (M06 agent): 1 and 2 implemented in FiscalYear (close + relock), InventoryReconciliation,
FiscalYearInventorySnapshot::rewriteFor, stockPosition(). 3 written (not run): FiscalYearManufacturingCloseTest.php.
