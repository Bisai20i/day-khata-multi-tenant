# ERP parity: match construction-erp income statement and related reporting

Source of truth: six gap analyses run on 2026-11-20 against `D:\Projects\day-khata\construction-erp`
(reference: `server/services/reports.js`, `server/db/seed/coa.js`, `public/js/pages/reports.js`).
The product is not in production yet, so schema changes are acceptable, but migrations stay additive
(`todo/START.md` rule 5 and `.ai/rules/migrations.md` still apply).

## Rules for every agent (read before touching anything)

1. Read `AGENTS.md`, `.ai/rules/index.md` and every rule file whose glob matches a path you touch.
2. **Own only the files listed for your chunk.** Need a change elsewhere (routes, config, nav-items, other
   chunks' files)? Put it under "Cross-file requests" in your report. Routes, `config/**`, nav-items and
   `composer.json` / `package.json` belong to the coordinator.
3. **No tests.** Per the user's standing instruction (2026-11-07) neither the coordinator nor agents write
   tests; this overrides the DoD item "write Pest tests". Manual code review only. Never run tests, builds,
   migrations, tinker, or anything touching a database.
4. **No commits, no state-changing git.** The user commits. Allowed: `php -l`, `vendor/bin/pint <your files>`,
   `git status`, `git diff -- <your files>`.
5. Money: backend `App\Support\Money\Money` / `Quantity` only; frontend `resources/js/lib/money.js` only.
   No `(float)`, `round()`, `number_format()` on floats, `toFixed`, `parseFloat`, `Number()` math.
   Models use `App\Casts\Decimal`, never `'decimal:N'`.
6. Migrations: new files only in `database/migrations/tenant/`, reserved prefix per chunk, working `down()`,
   idempotent, backfill before constraints, SQLite and MySQL safe, index names <= 64 chars, literal constants
   in data migrations (never read config or catalogs).
7. The working tree is dirty and other agents edit other files at the same time. Ignore their changes, never
   revert or reformat files you do not own.
8. Vue files stay under ~750 lines (target ~500); extract a component or composable when close.
9. No em dashes in docs, comments or UI text.
10. Keep reading minimal (grep for the symbol, read line ranges). If your transcript nears ~150k tokens, stop,
    report what is done and what is left.
11. Final report: files changed, what was done, cross-file requests, and the exact manual verification steps
    for the user. Keep it under 300 words.

## Decisions taken (user said "do all", 2026-11-20)

| Topic | Decision |
|---|---|
| Stock costing and periodic trading account | Unchanged. Do not port the reference's perpetual COGS or moving average. |
| Income statement | Keep the trading-account rows (opening stock, purchases, returns, closing stock). Add group nesting as an extra `children` key; keep the flat row contract used by `BranchComparisonReportController` and `grossProfit()`. |
| Project dimension | New `projects` table; nullable `project_id` on `journal_voucher_lines`; one project chosen per document, inherited by every line it posts. Branch stays as is. |
| Nepali | New nullable `name_np` on accounts (stored only). The statement display toggle (`lang=ne`) was dropped on 2026-11-21: no language switcher. |
| Balance sheet | Current Year Profit / (Loss) moves inside Capital; zero nodes pruned; Liabilities & Equity on the left, Assets on the right. |
| Trial balance | Level selector (Group / Sub group / Ledger), zero rows hidden by default. |

## Status board

Order matters where chunks share `AccountingReportController.php` (about 2,600 lines, owned by exactly one
chunk at a time).

### Phase 1: foundations (parallel, disjoint)

| Chunk | Scope | Owned files | Migration prefix | Status |
|---|---|---|---|---|
| S1 | Chart: "Direct Expenses" group + Direct Wages account, "Non-Current Liabilities" group, employer SSF payable and CIT payable accounts. Seeder for new tenants plus idempotent backfill migration for existing ones. | `database/seeders/Tenant/ChartOfAccountsSeeder.php` (or wherever it lives), `app/Support/Hr/HrAccounts.php`, new migrations | `2026_11_21_10` | done (reviewed at gate) |
| S3a | Projects schema: `projects` table, nullable indexed `journal_voucher_lines.project_id`, `Project` model, factory. | new migrations, new `app/Models/Project.php`, new `database/factories/ProjectFactory.php` | `2026_11_21_11` | done (reviewed at gate) |
| F0 | Shared print/export pieces: `resources/views/pdf/partials/signatures.blade.php` (Prepared / Checked / Approved, flat borders) and an export helper that writes a company name + PAN first heading row and a BS+AD period line. New files only. | new files only | none | done (reviewed at gate) |
| E | Dashboard: profit KPI (gross profit, net profit, income, expense) built from `IncomeStatementStatement::build()` and gated on `financial_statements.view`; separate bank balance KPI; optional `monthlyPnl` chart data. | `app/Http/Controllers/Tenant/DashboardController.php`, `resources/js/pages/Tenant/Dashboard.vue` | none | done (reviewed at gate) |

### Phase 2: depends on Phase 1

| Chunk | Scope | Depends on | Status |
|---|---|---|---|
| S2 | `name_np` on accounts: migration, `Account` model, account create/edit form, seeded Nepali names for default accounts. Also owns `Account::SYSTEM_CODES` (EXE28, LIA23, LIA24) and `AccountGroup::PROTECTED_NAMES`. Also added `name_np` to account heads and subgroups (+ `AccountHead` fillable). | S1 | done |
| S3b | Projects master CRUD: controller, form request, Vue index + form. Coordinator added routes (`routes/tenant-projects.php`), permission keys and nav item. | S3a | done |
| H1 | Payroll: Direct Wages starter component (EXE28) + migration `2026_11_21_130000`. Employer SSF / CIT NOT added: payroll has no employer contributions (needs schema + design). | S1 | done |
| P0 | `JournalVoucher::write()` optional `project_id` (trailing param + per-line override), carried through reversals; caller inventory for P1 to P3. `post()` also reads `$header['project_id']`. | S3a | done |
| P1 | Project capture on sales side (migration 2026_11_21_140000) (Sale, SalesReturn, Receipt): select a project on the form, store on every posted journal line. | P0, S3b | done |
| P2 | Project capture on purchase side (Purchase, PurchaseReturn, Payment). | P0, S3b | done |
| P3 | Project capture on journal voucher and capital documents. | P0, S3b | done |

### Phase 3: reports (serial, one owner of `AccountingReportController.php` at a time)

| Order | Chunk | Scope | Status |
|---|---|---|---|
| 1 | R1 | Trial balance: level selector, zero-hiding default, signature partial, export header. Owns trial balance methods, `TrialBalanceStatement`, `TrialBalance.vue`, `TrialBalanceSection.vue`, `trial-balance.blade.php`, `TrialBalanceExport`. | done (reviewed by 3 slices, 2026-11-20) |
| 2 | R2 | Balance sheet: prune zero nodes, profit inside Capital, swap sides, Non-Current Liabilities grouping, signatures, export header. | done (reviewed by 3 slices, 2026-11-20) |
| 3 | R3a | Income statement backend: group/sub-group nesting with `Money::sum` rollups (flat rows kept, additive `*Groups` keys), `tradingCodes()` includes Direct Expenses (codeless ones via `isCodelessDirectExpense()`), optional `project_id` filter (stock 0.00 in project mode). BranchComparison and dashboard ignore `project_id`. | done (reviewed by 3 slices, 2026-11-21) |
| 4 | R3b | Income statement presentation: expandable group tree (`IncomeStatementTree.vue`), reference wording, loss in parentheses, Direct Expenses line (also in the by-branch cost of sales), project select, PDF nesting (`income-statement-groups` partial) + signatures + project label, export nesting + shared header + project line. Ledger links do not carry `project_id` yet (R4 adds the ledger project filter, then the link gets it back). | done (reviewed by 3 slices, 2026-11-21) |
| 5 | R4 | Ledger: Particulars column (other side of the voucher, max 3 names + "+N more", only where documents are on, so Cash/Bank Book keep their shape), sub-ledger summary (`SubLedgerSummary`, ties to the ledger with sub-ledgers included), optional `project_id` filter (`LedgerBook::build` trailing param; page, PDF, Excel; hidden for staff ledgers), signatures on the PDF. Income statement ledger links carry `project_id` again. | done (reviewed by 3 slices, 2026-11-21) |
| 6 | R5 | Day book: per-voucher Party (customer / supplier on the voucher's lines) and Total, optional `project_id` filter (page, PDF, Excel). | done (reviewed by 2 slices, 2026-11-21) |
| 7 | N1 | Nepali display: `lang=ne` toggle, `name_np` on the four statements, bilingual section labels. | dropped (user, 2026-11-21: no language switcher in this system). `name_np` stays as a stored field on accounts, heads and subgroups. |

### Phase 4: low priority (done, reviewed by 2 slices, 2026-11-21)

TDS column in the purchase VAT book: `tds_amount` per row (negative on a cancellation row, zero on capital
purchases) and in the totals; page, Excel (column O, Branch moves to P). There is no PDF for this book.
Running value in the item stock ledger: each row's `value` is the running balance at the cost as of that
row's date (`StockCosting::costPerBaseUnit`, now public; perpetual items use `PerpetualCosting::valueBalance`),
plus `openingValue` / `closingValue`; page, PDF, Excel (column K). Not a cumulative sum of movement values
as in the reference, because `ItemStockMovement::value` is null for sales, transfers and write-offs.

### Phase 2 gate (done, 2026-11-20)

Six parallel reviewers. Fixed by coordinator: `FiscalYearArchiver` now keeps `project_id` in archives;
`ProjectController` counts and guards across all branches and also checks document tables before delete;
new grant migration `2026_11_21_170000_grant_project_permissions_to_existing_roles.php`; ROUTE-MAP entries.
Open for the user: `PayrollLedgerTest.php:123` must list `DIRECT_WAGES`; employer SSF/CIT not built.

### Follow-up after the Phase 2 gate (2026-11-20, user: "fix minor findings, build SSF and CIT, no Nepali field on groups, closed project per industry practice")

- Closed projects: a completed or on-hold project takes no NEW postings (`App\Rules\OpenProject` on every
  `project_id` field, `JournalVoucher::assertProjectsUsable()` at posting). Reversals and roll-forwards replay
  their lines (`allowInactiveProjects`), and a return, refund or settlement against a document already in that
  project may keep it (`allow_inactive_project` header, `JournalVoucher::isSameProject()`).
- Minor findings fixed: batched project existence query; Nepali backfill matches groups/subgroups inside their
  seeded parent; chart migration `100000` stops on a code collision and its `down()` leaves groups alone; FK
  check in the four `project_id` migrations also requires `foreign_table = projects`.
- SSF / CIT in payroll (chunks SC-A schema, SC-B posting, SC-C Vue): migrations `2026_11_21_180000` (EXE32
  Employer SSF, EXE33 Employer CIT) and `180100` (rates on staff, `is_contribution_base` on components, four
  amounts per payslip, four run totals). Net = gross - recovery - TDS - employee SSF - employee CIT. Employer
  amounts are extra cost: Dr EXE32 / EXE33, Cr LIA23 / LIA24 together with the employee withholding.
- Not done on purpose: Nepali field on group and subgroup edit pages; remitting SSF / CIT to the fund uses the
  existing cash / bank payment voucher (LIA23 / LIA24 are not control accounts).

## Gates

After each phase the coordinator reviews the combined diff with several parallel narrow-slice reviewers (never
one), then gives the user: the per-chunk file list for commits, and the commands to run
(`php artisan test --compact --filter=...`, `npm run build`, migrations incl. a fresh migrate + full rollback on
MySQL). The coordinator waits for the user's results before the next phase.
