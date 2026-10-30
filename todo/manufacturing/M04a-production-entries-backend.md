# M04a Production entries, backend

Phase 3 (parallel with M03, M05a, M05b) | Implements MC6 | Agent: builder-high

## Owned files

`app/Models/ProductionEntry.php`, `ProductionDirectCost.php`, `ProductionCompletion.php`,
`ProductionWipEntry.php`: add the logic to the M01 skeletons. This task consumes `WipLedger` from M03 (built in
parallel): code against MC7 exactly.

Tests: `tests/Feature/Tenant/Manufacturing/ProductionEntryTest.php` and
`tests/Feature/Tenant/Manufacturing/ProductionCostingScenarioTest.php`.

## Tasks

- [x] 1. `create`, `attachIssueNotes`, `addDirectCost`, `cancelDirectCost` and `returnMaterial`, per MC6.
- [x] 2. `complete` (partial and final), `abandon`, `cancel` and `costSheet()`, per MC6. Every method:
  - locks the entry row first, then the items, then the fiscal year;
  - calls the closed-year guard;
  - posts through `JournalVoucher::post`.
- [x] 3. Tests (write, do not run).
  - Scenario, one step at a time:
    1. Issue RM-A 10 @ 50 and RM-B 4 @ 125: WIP 1000.00.
    2. Add labour 300 and power 200: WIP 1500.00.
    3. Plan 100 units, then complete 40 (non-final): FG 600.00, WIP 900.00.
    4. Add 60 more cost: WIP 960.00.
    5. Final completion of 58 units: FG 960.00, WIP 0.00, cost per unit = 1560.00 / 98.
  - After every step, assert the AS13 GL balance == `WipLedger::balance()` (P2) and AS14 == the FG subledger (P1).
  - Also:
    - An entry without an issue note is rejected.
    - A note from another store is rejected.
    - A non-final completion >= the remaining planned quantity is rejected.
    - Returning more than the WIP quantity is rejected.
    - Abandon posts Dr EXE24.
    - Cancel with a completion is rejected.
    - A date in a closed year is rejected.

## Checkpoint (M04a agent)

Items 1-2 written in `app/Models/ProductionEntry.php` (reviewed by reading, not run). Partial completions split
the cost over per-material WIP buckets + a non-material bucket with `Money::allocate()`; final/abandon drain them.
Return and abandon WIP rows have the posted JournalVoucher as `source`. Item 3 tests written (not run). All items done.
