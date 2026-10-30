# M07 Manufacturing reports

Phase 4 (parallel with M04b, M06) | Implements MC10 | Agent: builder

## Owned files

- New `app/Support/Manufacturing/ManufacturingReports.php`.
- `app/Http/Controllers/Tenant/Reports/ManufacturingReportController.php`.
- Excel exports under `app/Exports/Manufacturing/`.
- PDFs: `resources/views/pdf/manufacturing-*.blade.php`.
- `resources/js/pages/Tenant/Reports/Manufacturing/{StockLedger,InventorySchedule,CogsSchedule,Reconciliation}.vue`.
- Tests: `tests/Feature/Tenant/Manufacturing/ManufacturingReportsTest.php`.

Cross-file requests to the coordinator:
- Routes, nav entries and the `manufacturing_reports.view|export` keys.
- One link each from the existing Balance Sheet and Income Statement pages to the inventory schedule and the
  COGS schedule.

## Tasks

- [x] 1. `ManufacturingReports`:
  - Stock ledger for each class (RM, WIP, FG), with running quantity and value.
  - Inventory schedule.
  - COGM/COGS schedule, including the reconciling line.
  - Reconciliation view (MC9 `check`).
- [x] 2. Controller, pages, and PDF and Excel exports. The date range defaults to the current fiscal year. BS
  dates.
- [x] 3. Tests (write, do not run), using the M04a scenario plus one sale:
  - The stock ledgers close at the GL balances.
  - The inventory schedule total equals AS12 + AS13 + AS14 + trading stock.
  - Computed COGS equals EXE10, with a zero reconciling line.
  - Opening figures come from the prior year's snapshot.
