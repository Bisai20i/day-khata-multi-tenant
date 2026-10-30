# M05b Perpetual hooks: sales, POS, sales returns (COGS)

Phase 3 (parallel) | Implements MC8 (sales side) | Agent: builder-high

## Owned files

- `app/Models/Sale.php` and `app/Models/SalesReturn.php`.
- The POS posting path, if it is separate (grep for it).
- Tests:
  - New: `tests/Feature/Tenant/Manufacturing/CogsRecognitionTest.php`.
  - Any existing sales test whose voucher-line count assertion changes. List them in the report.

## Tasks

- [x] 1. Sale and POS post:
  - For each perpetual line, add Dr EXE10 / Cr class account at the movement's cost_value. Put it in the same
    voucher, with the narration "Cost of goods sold".
  - Trading lines are unchanged.
  - Cancel already mirrors the voucher through `JournalVoucher::reverse`. Verify that and test it.
- [x] 2. Sales return (linked, unlinked, and the pending workflow): Dr class account / Cr EXE10.
  - A linked return is costed at the original sale movement's cost share, per MC8.
  - An unlinked return is costed at `issueCost`.
- [x] 3. Tests (write, do not run):
  - Produce 100 FG for 1560.00 (moving average 15.60). Selling 5 posts Dr EXE10 78.00 / Cr AS14 78.00.
  - A mixed trading and FG invoice still balances, and its trading part posts no COGS.
  - Cancel nets EXE10 back to zero.
  - A return of 2 credits EXE10 31.20.
  - P1 holds after every step.
