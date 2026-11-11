# Implementation Plan 11: HR Department - Staff Payroll

## 1. Requirement Summary
Add an **HR module** (separate entitlement, nav section, routes, tables) whose first feature is **Staff Payroll**:
- Track per-staff **Salary, Bonus, Allowance**.
- Post **salary expense** from HR into the ledger with correct double-entry.
- **Unpaid salary -> Payable** and **staff advances -> Receivable** on the Balance Sheet.
- Modular: later HR features (attendance, leave, deductions, SSF/PF/TDS, loans, departments) plug in without reshaping payroll.
- Must survive the upcoming **per-tenant Branch** feature without a rewrite.

## 2. What the codebase already gives us (verified)
- **Database-per-tenant** (stancl). Tenant migrations live in `database/migrations/tenant`. A "tenant" is one company; Branch will be a table *inside* each tenant DB.
- **Single ledger**: `JournalVoucher::post()` (date-in-fiscal-year guard, closed-year gate, gapless per-type numbering via `VoucherType`), `JournalVoucher::reverse()` for cancellation (open year only). Reports (Trial Balance, P&L, Balance Sheet, Day Book, Account Ledger) read only `journal_voucher_lines`, so anything posted through `post()` shows up everywhere automatically.
- **Balance Sheet** (`AccountingReportController::balanceSheet`) groups by Head -> Group -> Subgroup -> Account. A Liabilities account shows as payable, an Assets account as receivable. No report changes are needed if the right accounts exist.
- **Document-owns-voucher pattern**: `Payment`/`Receipt`/`FixedAssetDepreciation` hold `journal_voucher_id`; `JournalVoucher::sourceRecordLabel()` lists owners so a manual cancel is refused. HR documents must register there.
- **Module/permission pattern**: `config/permissions.php` module + keys, `routes/tenant-<x>.php` with `can:` per route, `nav-items.js` with `permission`, ROUTE-MAP + `RouteAuthorizationAuditTest`. Manufacturing is the closest template (`todo/manufacturing/`).
- **Branch precedent**: only `stores` (`users.store_id`, `store_id` on sales/purchases/stock docs). No branch concept exists. There is **no employee/staff model** - "employees" in `routes/tenant-employees.php` are login `User`s, so naming here must avoid that clash (`StaffMember`, not `Employee`).

## 3. Design decisions

### 3.1 Staff is NOT a User
`hr_staff` is the payroll master; `user_id` is an optional unique link to a login. Most staff never log in, and users have no pay data. Do not extend `users`.

### 3.2 Control accounts + HR sub-ledger (not one ledger account per staff)
The Agent/Customer pattern (`HasLedgerAccount`, one account per person) is rejected here: the Balance Sheet classifies by head, not by sign, so a staff member with a *debit* balance (advance given) would show as a negative liability instead of a **Receivable**.

Instead, two control accounts, and the per-person balance lives in HR tables:

| Code | Name | Group | Role |
|---|---|---|---|
| LIA22 | Salary Payable | Current Liabilities | unpaid salary -> **Payable** |
| ASA24 | Staff Advances Receivable | Current Assets | advances not yet recovered -> **Receivable** |
| EXE25 | Salaries & Wages | Indirect Expenses | component "Salary" |
| EXE26 | Staff Bonus | Indirect Expenses | component "Bonus" |
| EXE27 | Staff Allowances | Indirect Expenses | component "Allowance" |

(Codes checked against `ChartOfAccountsSeeder`: free.) All five go into `Account::SYSTEM_CODES`, `ChartOfAccountsSeeder`, and a new idempotent tenant migration for existing tenants (copy `2026_11_03_100000_add_round_off_account_to_chart_of_accounts.php`; resolve groups by name). Per-staff outstanding = HR sub-ledger; a **reconciliation check** (sub-ledger total vs control-account balance as at date) catches drift, as the manufacturing reconciliation does. Manual journal/cash-bank lines against LIA22/ASA24 should be rejected (see Risks).

### 3.3 Table-driven pay components (the extensibility seam)
Salary/Bonus/Allowance are **rows** in `hr_pay_components` (`code`, `name`, `kind` = earning for now, `expense_account_id`, `is_system`, `is_active`), not hard-coded columns. The poster groups payslip items by component -> expense account. Adding Overtime, Commission, or later a `deduction` kind is data, not schema.

### 3.4 Branch-readiness (build the seam, not the feature)
Do now:
1. Nullable, indexed `branch_id` as `unsignedBigInteger` with **no FK** on `hr_staff`, `hr_payroll_runs`, `hr_payroll_payments`, `hr_staff_advances`. The branch feature's own migration adds the FK (and backfills a default branch). Declaring the type now avoids the MySQL FK type-mismatch trap in `.ai/rules/migrations.md`.
2. **One voucher per branch per payroll run; a run is single-branch.** Never mix branches in one voucher. When Branch adds a header-level `branch_id` to `journal_vouchers`, payroll is already compatible, and branch-wise Trial Balance / Balance Sheet works.
   *Superseded by `plans/12-multi-branch.md` BR09: a run may span branches; each payslip posts in its staff member's branch and the voucher balances per branch.*
3. All posting goes through two classes only (`PayrollPoster`, `StaffAdvancePoster`) so stamping the branch is a single edit point.
4. No stored per-branch aggregates or cache columns (outstanding is always computed from rows).
5. Staff has a *home* branch; the run's branch is chosen at creation and filters which staff it pulls in. Branch-user access (like `users.store_id`) is the branch feature's job, not HR's.

Do NOT build now: Branch model, scopes, switcher, branch permissions.

### 3.5 Lifecycle: draft -> posted -> (cancelled)
Generate a run for a period (prefilled from each staff's active pay items), edit amounts (add bonus, adjust), then **post**. Posted runs are immutable; cancel = `JournalVoucher::reverse()` (open fiscal year only, same rule as every other module; closed-year errors are corrected via a new run/adjustment). A run with live payments cannot be cancelled until the payments are.

## 4. Accounting entries

**Payroll run post** (VoucherType `payroll`, one voucher):
```
Dr Salaries & Wages (EXE25)        sum of Salary items
Dr Staff Bonus (EXE26)             sum of Bonus items
Dr Staff Allowances (EXE27)        sum of Allowance items
    Cr Salary Payable (LIA22)      gross - advance recovery
    Cr Staff Advances Receivable (ASA24)   advance recovery (only if > 0)
```
Expense lines are one per component account (not per staff); per-staff detail is in `hr_payroll_lines`. Line narration quotes the run number.

**Salary payment** (VoucherType `salary_payment`): `Dr LIA22 / Cr Cash (AS1) or chosen bank account`; allocated to payroll lines (partial payment allowed, over-allocation refused with exact `Money` comparisons and row locks, same discipline as `Payment`).

**Staff advance** (VoucherType `staff_advance`): `Dr ASA24 / Cr Cash/Bank`. Recovery happens only through a payroll run's `advance_recovery` (capped at the staff's outstanding advance).

Balance Sheet result: unpaid salary sits in Current Liabilities as *Salary Payable*; unrecovered advances in Current Assets as *Staff Advances Receivable*. Income Statement shows the three expense accounts; year-end close sweeps them (Expenses head) with no extra work; Payable/Receivable carry forward via the existing opening-balance mechanism.

## 5. Data model (tenant DB, all `hr_`-prefixed, money `decimal(20,2)` + `Decimal` cast)
- `hr_staff`: staff_code (unique), name, mobile_no, address, designation, department (plain string now; future `hr_departments`), join_date, end_date?, pan_no?, bank details?, user_id? (unique), is_active, branch_id?.
- `hr_pay_components`: see 3.3. Seeded: Salary, Bonus, Allowance.
- `hr_staff_pay_items`: staff_id, component_id, amount, effective_from, effective_to? (history by new row, never edit in place). Bonus is normally one-off, entered on the run.
- `hr_payroll_runs`: fiscal_year_id, period_start, period_end, period_label, run_number (= voucher number), status (draft/posted/cancelled), journal_voucher_id?, reversal_journal_voucher_id?, totals, branch_id?, created_by, posted_by, cancel fields.
- `hr_payroll_lines`: run_id, staff_id, gross, advance_recovery, net. Unique (run_id, staff_id) with an explicit short index name.
- `hr_payroll_line_items`: line_id, component_id, amount, note?.
- `hr_payroll_payments` + `hr_payroll_payment_lines` (payment, payroll_line, amount): mirror of `Payment`/`PaymentAllocation`.
- `hr_staff_advances`: staff_id, date, amount, mode/bank_account_id, journal_voucher_id, status, cancel fields, branch_id?.

All FKs `restrictOnDelete` (project convention: deactivate, never delete). Add the new tables' account columns (`expense_account_id`, `bank_account_id`) to `Account::REFERENCES` so accounts in use cannot be deleted.

## 6. Permissions / module
- Module `hr` (label "HR"), `requires: ['accounting']`. Add to `default_modules` (tests/tinker). **Off for existing tenants** - no grant migration (`.ai/rules/config.md`).
- Keys: `hr_staff.view|create|edit`, `pay_components.view|manage`, `payroll.view|create|manage|cancel|print|export` (`create` = build/edit drafts, `manage` = post), `payroll_payments.view|create|cancel`, `staff_advances.view|create|cancel`, `hr_reports.view|export`.
- All routes `can:`-gated, in new `routes/tenant-hr.php`, required from `routes/tenant.php`. ROUTE-MAP + `RouteAuthorizationAuditTest` updated.

## 7. Backend structure (follows Manufacturing)
- Controllers `app/Http/Controllers/Tenant/Hr/{StaffController,PayrollRunController,PayrollPaymentController,StaffAdvanceController,HrReportController}`; Requests `app/Http/Requests/Tenant/Hr/`.
- Models (flat in `app/Models`, names chosen to avoid `User`/`Employee` clash): `StaffMember`, `PayComponent`, `StaffPayItem`, `PayrollRun`, `PayrollLine`, `PayrollLineItem`, `PayrollPayment`, `PayrollPaymentLine`, `StaffAdvance`. Factories for each (project rule).
- `app/Support/Hr/`: `PayrollPoster`, `PayrollGenerator` (prefill from pay items), `StaffAdvancePoster`, `HrAccounts` (uncached code -> id lookup, per the manufacturing lesson), `StaffBalances` (outstanding payable/advance per staff + reconciliation).
- `VoucherType` gains `Payroll`, `SalaryPayment`, `StaffAdvance` (own gapless series; not manually cancellable). Register owners in `JournalVoucher::sourceRecordLabel()`; add labels wherever voucher types are listed (Day Book filter, Cancelled Documents report, `ActivityLogController` model names). Observers registered in `AppServiceProvider`.
- Exports/print reuse the existing PDF/Excel patterns.

## 8. Frontend (Inertia + Vue, `resources/js/pages/Tenant/Hr/`)
Staff (list/form with pay items), Payroll runs (list, generate/edit draft, show with post/cancel/pay), Payments, Advances, Reports (Payroll Register, Staff Payable/Advance summary with reconciliation, Staff Statement). New "HR" nav section (`nav-items.js`, each item with `permission`). Keep files in the 250-750 line band (`.ai/rules/js.md`): the run editor needs a child component/composable split from the start.

## 9. Phases and gates
Coordinator-only files (per `.ai/rules/js-lib.md` and the manufacturing START): `config/permissions.php`, `routes/*.php`, `nav-items.js`, ROUTE-MAP, route audit test, `AppServiceProvider`. Workers never run tests/migrations; the user runs them and commits (memory: user commits and runs tests themselves).

| Phase | Scope | Gate (user runs) |
|---|---|---|
| H1 Foundation | enums, 5 accounts (seeder + migration), all `hr_` tables, models, factories, `HrAccounts`, module + permission keys | `php artisan test --compact tests/Feature/Tenant/Hr`, fresh migrate + rollback on MySQL (FK/index-name traps) |
| H2 Staff + pay items | StaffController, requests, pages, nav, routes | route audit test |
| H3 Payroll engine | `PayrollGenerator`, `PayrollPoster`, run draft/post/cancel, ledger + Balance Sheet tests | `--filter=Payroll`, `AccountingReport` tests |
| H4 Payments + advances | payment allocation, advance post, recovery cap, cancel rules | `--filter=Payroll\|StaffAdvance` |
| H5 Reports + print/export | register, staff balances, reconciliation, PDFs | full `php artisan test`, `npm run build`, `tenants:migrate` on a dev tenant, browser checklist |

Browser checklist: create staff + pay items; generate and post a run; Balance Sheet shows Salary Payable = net; pay part in cash and part by bank, payable drops; give an advance, Receivable appears; recover it in the next run; cancel a run (blocked while paid); closed-year behaviour.

## 10. Tests (Pest, `tests/Feature/Tenant/Hr`)
Posting lines and balance; one expense line per component; Payable on the Balance Sheet; Receivable on the Balance Sheet; partial and over-payment; advance recovery cap; cancel via reversal and the blocked-when-paid / closed-year cases; sub-ledger vs control-account reconciliation; permission and entitlement gating (`userWithPermissions()`, `tenancy()->end()` after changing `enabled_modules`); account-in-use deletion refusal; migration idempotency.

## 11. Risks / things to settle
- **Manual postings to LIA22/ASA24** would desync the sub-ledger. Recommend rejecting them in the manual journal and cash/bank voucher validation (small change), plus the reconciliation report as a backstop.
- **Opening balances**: a tenant switching on HR mid-year with existing unpaid salary needs those balances imported into LIA22 and mirrored as an opening sub-ledger entry. Defer, but H1 should leave room (`hr_payroll_runs` allowing an "opening" kind).
- `journal_voucher_lines` has no staff/branch dimension and the fiscal-year archiver copies a fixed schema; this plan deliberately avoids touching either.
- Statutory deductions (TDS on salary, SSF/PF) are out of scope; `kind = deduction` on components is the intended hook.

## 11a. As built (2026-11-05) - deviations from the plan above
- Permission keys shipped: `hr_staff.view|create|edit`, `pay_components.view|manage`, `payroll.view|create|manage|cancel`, `payroll_payments.view|create|cancel`, `staff_advances.view|create|cancel`, `hr_reports.view|export` (no `payroll.print|export`; no payslip print page yet).
- Reports: Payroll Register and Staff Balances (with ledger reconciliation) have Excel export; Staff Statement is screen-only. No PDF yet.
- Posting is into the open fiscal year only; posting HR documents into a reopened closed year is not supported yet.
- Manual journal and cash/bank vouchers reject LIA22 / ASA24 (`HrAccounts::assertNotControlAccounts`), and the opening-balance import blocks them.
- Migrations: `2026_11_05_100000_create_hr_payroll_tables`, `2026_11_05_100100_add_hr_accounts_and_pay_components` (existing tenants; new tenants get the accounts and components from `ChartOfAccountsSeeder`).
- Cancelled HR documents are not in the Cancelled Documents report (it is party-based); they are in the Day Book and the activity log.

## 12. Assumptions to confirm
1. Staff Advances (the Receivable side) are in v1. They are the only way a Receivable arises, and they are cheap on top of the engine.
2. Monthly runs labelled by Nepali (BS) month; period stored as real dates.
3. Bonus and ad-hoc allowances are entered on the run; Salary and recurring Allowance come from pay items.
4. Payment is a separate step from posting (accrual), not "post and pay" in one action.
5. `hr` is a separate paid/entitled module, off for existing tenants.
