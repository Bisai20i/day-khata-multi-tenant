# M03 Material Issue Notes

Phase 3 (parallel with M04a, M05a, M05b) | Implements MC5 and MC7 (`WipLedger`) | Agent: builder

## Owned files

- `app/Models/MaterialIssueNote.php` and `MaterialIssueItem.php`: add the logic to the M01 skeletons.
- `app/Support/Manufacturing/WipLedger.php` (MC7). M04a consumes it in parallel, so implement it FIRST and
  exactly as MC7 specifies.
- New `app/Http/Controllers/Tenant/Manufacturing/MaterialIssueNoteController.php` and its FormRequest(s).
- New `routes/tenant-manufacturing.php`. Create it with the material-issue routes only; M04b sends its routes as a
  cross-file request.
- `resources/js/pages/Tenant/Manufacturing/MaterialIssues/{Index,Create,Show}.vue`.
- `resources/views/pdf/material-issue-note.blade.php`.
- Tests: `tests/Feature/Tenant/Manufacturing/MaterialIssueNoteTest.php`.

Permission keys to request from the coordinator: `material_issues.view|create|cancel|print` (module
`manufacturing`).

## Tasks

- [x] 1. `WipLedger` per MC7 first. Then `MaterialIssueNote::post()` and `cancel()` per MC5:
  - Lock order as MC5 lists.
  - Raw materials only.
  - Store-scoped stock check, aggregated per item, that ignores allow_negative_stock.
  - A MaterialIssue voucher numbered as the note: Dr AS13 / Cr AS12.
  - WIP rows.
  - Copy patterns from `StockConversion` and `StockAdjustment`.
- [x] 2. Controller and pages:
  - Actions: index (date and status filters, pagination), create, store, show, cancel, and print with
    `PrintLog::record`.
  - Every route gated with `can:`.
  - The Vue item picker lists raw materials only, showing on-hand quantity and the current moving-average rate.
  - Unit select.
  - Cost preview through `money.js`. It is a preview only; the server figure is authoritative.
  - BS dates, defaulting to `todayInKathmandu()`.
- [x] 3. Tests (write, do not run):
  - An issue posts a balanced voucher, Dr AS13 / Cr AS12, equal to the movement's cost_value, plus WIP rows.
  - A trading or finished item is rejected.
  - A shortfall is rejected even with allow_negative_stock on.
  - A date in a closed year is rejected.
  - Cancelling an unattached note reverses all three ledgers.
  - Cancelling an attached note is rejected.
  - A user without `material_issues.create` gets 403.
