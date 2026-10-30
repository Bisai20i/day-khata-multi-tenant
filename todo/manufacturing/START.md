# START HERE: Manufacturing module (2026-10-25)

This plan builds raw material -> WIP -> finished goods production with perpetual journals, production costing,
year-end reconciliation and manufacturing reports. Read `CONTRACTS.md` (MC1-MC10) before any task file. The
coordinator role, the sub-agent rules, the forbidden commands and the gate procedure are the same as in
`todo/START.md` ("Rules for every sub-agent", "Gates"). Read both sections and apply them here unchanged.

## Differences from todo/START.md

- Branch `manufacturing`, created from `main`. **The user commits, not the coordinator** (decided 2026-10-25). At
  each gate the coordinator lists the files per task for the user to commit. No push.
- Agents: `builder` or `builder-high`, as named in each task file. Each task file has 3 checkboxes, which is one
  agent invocation (`.ai/rules/general.md`, transcript cap).
- Migration prefix `2026_10_25_10xxxx` (M01 only; later tasks request schema changes from the coordinator).
- Coordinator-only files: `config/permissions.php`, `routes/tenant.php`, `resources/js/lib/nav-items.js`,
  `todo/permissions/ROUTE-MAP.md`, `tests/Feature/Tenant/Authorization/RouteAuthorizationAuditTest.php`,
  `app/Providers/AppServiceProvider.php` (activity log observers), this file.

## Coordinator wiring (do this after Phase 1)

- `config/permissions.php`: add a module `manufacturing` (label "Manufacturing", requires `inventory` and
  `accounting`) and the keys `material_issues.view|create|cancel|print`,
  `production_entries.view|create|edit|cancel|print`, `manufacturing_reports.view|export`. The module is OFF for
  existing tenants (no grant migration), per `.ai/rules/config.md`.
- Nav: a "Manufacturing" section under Inventory, with Material Issues, Production and Manufacturing Reports,
  each with its `permission`.
- Add the routes to `routes/tenant.php` and to ROUTE-MAP as each task delivers them.
- Activity log observers for MaterialIssueNote, ProductionEntry, ProductionDirectCost and ProductionCompletion.

## Phases

| Phase | Tasks | Depends on | Gate |
|---|---|---|---|
| 1 Foundation | M01 | nothing | **STOP**: user runs `php artisan test --compact tests/Feature/Tenant/Manufacturing tests/Unit` and `php artisan migrate:fresh --seed` on dev |
| 2 Engine | M02 | M01 committed | **STOP**: user runs `php artisan test --compact --filter=Costing` and `tests/Feature/Tenant/Inventory` |
| 3 Documents | M03, M04a, M05a, M05b (parallel) | M02 committed | continue unless an agent reports a blocker |
| 4 Books and UI | M04b, M06, M07 (parallel) | Phase 3 committed | **STOP**: user runs the full `php artisan test`, `npm run build`, `php artisan tenants:migrate` on a dev tenant, then the browser checklist |

## Browser checklist (user, after Phase 4)

- Create a raw item and a finished item. Purchase the raw item: the Ledger shows Dr Raw Material Inventory.
- Issue material, open a production entry from it, add labour, complete part of it, then complete the rest.
  The cost sheet shows Total Production Cost and Cost per Unit, and WIP ends at 0.00.
- Sell the finished good: the sale voucher has a Cost of Goods Sold line at the produced cost.
- The Balance Sheet inventory schedule shows RM, WIP, FG and trading stock, and the totals agree.
- The COGS schedule's computed COGS equals the COGS account, with a zero reconciling line.
- Close a test year that has in-progress production: it closes, WIP carries forward, and the entry can be
  completed in the new year.

## Deferred (not in this plan)

- Converting an existing stocked trading item into a perpetual class (needs a transfer journal from AS11).
- Bills of materials and recipes, multi-stage production, by-products and multiple outputs per entry, scrap
  valuation.
- FIFO.
- Per-store perpetual cost.

## Status board

| Task | Title | Phase | Status | Commit(s) |
|---|---|---|---|---|
| M01 | Schema, accounts, enums, models | 1 | built, awaiting user tests | |
| M02 | Perpetual costing engine | 2 | built, awaiting user tests | |
| M03 | Material Issue Notes | 3 | built, awaiting user tests | |
| M04a | Production entries backend | 3 | built, awaiting user tests | |
| M05a | Perpetual hooks: purchase and stock documents | 3 | built, awaiting user tests | |
| M05b | Perpetual hooks: sales and COGS | 3 | built, awaiting user tests | |
| M04b | Production entries UI | 4 | built, awaiting user tests | |
| M06 | Fiscal year reconciliation and snapshots | 4 | built, awaiting user tests | |
| M07 | Manufacturing reports | 4 | built, awaiting user tests | |
| M08a | Cancel latest completion/return, correction-year posting | follow-up | built, awaiting user tests | |
| M08b | Strict stock for perpetual items | follow-up | built, awaiting user tests | |

Gate notes:

**Phase 1, 2026-10-25.** M01 delivered; lint clean, Pint clean. Coordinator fixes: removed the static
per-tenant id cache from `ManufacturingAccounts::id()` (test tenants are recreated under the same id, so a
cached id could point at another test's account); MC2 updated to "uncached". Coordinator wiring: `manufacturing`
module (requires inventory, accounting; not in default_modules, so off unless enabled) and its 11 permission
keys in `config/permissions.php`. Known pre-existing issue reported by M01: `2026_09_13_110000`'s `down()` calls
`Account::delete()` on EXE9/INI22, which the system-code guard refuses; not changed (existing migration).

**Parallel build, 2026-10-25.** At the user's request M02-M07 ran in parallel against the contracts, without the
Phase 1 test gate. All eight reported; ownership was clean. Coordinator integration: `cost_value` fillable + Decimal
cast on ItemStockMovement; inventory class select in ItemsIndexFormModal (M02 request); `routes/tenant-manufacturing.php`
+ require; nav (Manufacturing under Inventory, Manufacturing Reports, Stock Conversion relabelled "Refining &
Repackaging"); ROUTE-MAP; activity log observers; movement reference labels; one credit-account query (no bank name
matching); M07 test credit account fixed; manufacturing entitled in the two HTTP test tenants; control-account guard
+ ControlAccountGuardTest; Balance Sheet and Income Statement links. Lint and Pint clean, no float ops. No test has
been run yet.

Open product questions (not blocking): single completion/return cancellation (today only abandon or full cancel);
posting manufacturing documents into a year reopened for correction (not supported, guard refuses); whether
purchase/return cancels of perpetual items should ignore allow_negative_stock like material issues do.

**Follow-ups M08a/M08b, 2026-10-25.** User answered the three open questions (latest-row cancels; reason-based
posting into a correction year; perpetual items always enforce stock). Both agents reported; ownership clean; lint and
Pint clean. Coordinator wired `production-entries.completions.cancel` and `.returns.cancel` routes + ROUTE-MAP. Earlier
user test run: 2 permission-test failures fixed (manufacturing added to default_modules per the "defaults = every
non-core module" invariant; RoleBackfill parity test switched to the subset assertion its own comment prescribed).

Still open: (a) completion/return cancels work in the open year only, because `JournalVoucher::reverse` refuses
correction years (C4); (b) backdated completions/returns are costed from the entry's current WIP, so one dated before
later activity on the same entry can leave that entry's WIP negative at a year end (GL and subledger still agree).
Recommended: refuse a completion or return dated before the entry's latest posting.
