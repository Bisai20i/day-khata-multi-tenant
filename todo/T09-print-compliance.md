# T09 Print compliance

Phase 2 | Parallel with T03-T08 | Implements C9 | Audit refs: P1 reprint count / "Copy of Original", BS date on
documents, amount in words, fiscal year on documents, print list report.

Module agents call `PrintLog::record()` and pass the C9 variables from their own `print()` actions; you own
the shared pieces and the layout.

## Owned files

New: `app/Models/PrintLog.php`, `app/Support/AmountInWords.php`,
`app/Http/Controllers/Tenant/Reports/PrintLogReportController.php`,
`resources/js/pages/Tenant/Reports/PrintLog.vue`, `routes/tenant-reports-print-log.php`,
`tests/Unit/Support/AmountInWordsTest.php`, `tests/Feature/Tenant/Reports/PrintLogReportTest.php`.
Modify: `app/Support/NepaliCalendar.php`, `resources/views/pdf/layout.blade.php`,
`tests/Unit/Support/NepaliCalendarTest.php`. Migrations with prefix `2026_09_12_09`.

## Tasks

- [x] 1. Migration `print_logs` per C9 (morphs + index, `copy_number`, `printed_by` FK, `printed_at`).
  `PrintLog::record()` computes the next copy number inside a transaction with a lock on the existing rows
  for that document, so two simultaneous prints never share a number.
- [x] 2. `AmountInWords::rupees(Money)`: Indian numbering (thousand, lakh, crore), paisa, negative amounts
  ("Minus ..."), zero ("Rupees Zero Only"). Pure PHP.
- [x] 3. `NepaliCalendar::formatBs()` per C9 on top of the existing `adToBs()`; do not change existing method
  behaviour.
- [x] 4. `pdf/layout.blade.php`: accept the C9 variables with safe defaults so documents not yet passing them
  still render; show "Original" or "Copy of Original - {n-1}", BS date first with AD beside it, fiscal year
  name. Keep every existing section/yield the document views rely on (read all `resources/views/pdf/*.blade.php`
  before editing).
- [x] 5. Print log report (admin): document type, number, copy number, who, when (BS + AD), date filter,
  pagination. Request the route `require` and nav entry from the coordinator in your report.
- [x] 6. Tests (write, do not run): amount in words for 0, 0.50, 1, 1,23,456.50, 10,00,00,000.00, negative;
  copy numbers 1, 2, 3 for repeated records; layout renders with and without the new variables; formatBs for
  known dates already covered by `NepaliCalendarTest`.
