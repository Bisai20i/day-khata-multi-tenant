# M04b Production entries, controller and UI

Phase 4 (parallel with M06, M07) | Consumes MC6 | Agent: builder

## Owned files

- New `app/Http/Controllers/Tenant/Manufacturing/ProductionEntryController.php` and its FormRequests.
- `resources/js/pages/Tenant/Manufacturing/Production/{Index,Create,Show}.vue`, plus child components under
  `resources/js/components/manufacturing/`. Keep each file under about 500 lines.
- `resources/views/pdf/production-cost-sheet.blade.php`.
- Tests: `tests/Feature/Tenant/Manufacturing/ProductionEntryHttpTest.php`.

Routes and permission keys (`production_entries.view|create|edit|cancel|print`) go to the coordinator as
cross-file requests.

## Tasks

- [x] 1. Controller actions:
  - Index, with status and date filters.
  - Create: lists the unattached, posted issue notes for the chosen store and auto-fetches their lines (item
    code/name, issue quantity, rate, amount).
  - Store.
  - Show: the cost sheet, the WIP balance and a timeline.
  - Attach notes.
  - Add or cancel a direct cost, with a credit account select limited to cash, bank and payable accounts.
  - Return material.
  - Complete (partial or final).
  - Abandon.
  - Cancel.
  - Print the cost sheet, with `PrintLog`.
- [x] 2. Vue pages:
  - Create shows the issue lines read-only, plus a dynamic direct-cost table.
  - Show has the live cost sheet (Total Production Cost, completed quantity, Cost per Unit, remaining WIP) and
    action dialogs that surface server errors.
  - Money through `money.js` only.
- [x] 3. HTTP tests (write, do not run):
  - The full happy path through the routes.
  - 403 for each permission.
  - Validation messages for a missing issue-note selection and for an over-return.
