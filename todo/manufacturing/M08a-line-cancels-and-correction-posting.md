# M08a Cancel latest completion/return, posting into a reopened year

Follow-up (parallel with M08b) | Decided by the user on 2026-10-25 | Agent: builder-high

## Owned files

- `app/Models/ProductionEntry.php`, `ProductionCompletion.php`, `MaterialIssueNote.php`.
- `app/Http/Controllers/Tenant/Manufacturing/ProductionEntryController.php`, `MaterialIssueNoteController.php`.
- `app/Http/Requests/Tenant/Manufacturing/*`.
- `resources/js/pages/Tenant/Manufacturing/**`, `resources/js/components/manufacturing/**`.
- Tests: new `tests/Feature/Tenant/Manufacturing/ProductionLineCancelTest.php` and `ManufacturingCorrectionPostingTest.php`,
  plus the existing manufacturing tests if an expectation changes (list them).

Routes and permission keys go to the coordinator as cross-file requests. Suggested routes:
- `POST /production-entries/{production_entry}/completions/{completion}/cancel`, key `production_entries.cancel`.
- `POST /production-entries/{production_entry}/returns/{voucher}/cancel` (or a better key you justify), key
  `production_entries.edit`.

## Decisions (do not re-ask)

1. **Cancelling a single completion or material return:** allowed only for the **latest** live row of that kind on the
   entry, only while the entry is `in_progress` (a final completion makes it `completed`; cancelling the final
   completion puts the entry back to `in_progress`). Partial completions are costed from the WIP left by the previous
   one, so only the last one can be undone without re-costing later rows.
2. **Posting into a year reopened for correction:** every manufacturing posting accepts an optional `reason`, passed to
   `JournalVoucher::post` / `reverse` and `ClosedFiscalYearGuard` exactly as other documents do (sales, purchases,
   stock adjustments: copy their pattern). The UI shows a required reason field when the document date falls in a year
   open for correction.

## Tasks

- [x] 1. `ProductionEntry::cancelCompletion(ProductionCompletion, User, string $reason)` and
  `cancelMaterialReturn(...)`:
  - Lock order as in MC6.
  - Reverse the voucher with `JournalVoucher::reverse`, cancel the movement and the WIP rows. Restoring WIP must
    restore the per-item quantities and per-bucket values exactly as they were before the row (P2 holds).
  - Completion cancel: refuse when the finished item's store stock would go negative (the goods were sold or moved),
    with a message naming the shortfall.
  - Return cancel: refuse when the raw item's store stock would go negative.
  - Open year or correction year only (C4/C5).
- [x] 2. Correction posting: add an optional `reason` to `MaterialIssueNote::post/cancel` and every `ProductionEntry`
  method that posts; pass it through; controllers and FormRequests accept it; the Vue dialogs and Create pages show it
  when needed (find how the sales or stock adjustment pages decide this and reuse that helper).
- [x] 3. Tests (write, do not run):
  - Cancel the latest partial completion: AS13/AS14 GL and the subledgers return to the pre-completion figures; a
    following completion costs the same as if the cancelled one never happened.
  - Cancelling a non-latest completion is refused; cancelling the final completion reopens the entry.
  - A completion whose goods were sold cannot be cancelled.
  - Return cancel restores WIP; a non-latest return is refused.
  - A material issue and a direct cost post into a year reopened for correction with a reason, and are refused
    without one; P1/P2 hold after relock.
