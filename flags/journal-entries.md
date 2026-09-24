# Journal entry / general ledger core audit

Scope: manual and cash/bank vouchers, JournalVoucher::post/write/reverse/cancel, chart of accounts,
numbering, period locking, ledger and trial balance reports. Read only; nothing was run.
Legacy day_khata has no comparable model layer (MySQL triggers), so parity notes are limited.

## Flags

### JE-01 (P1) FIXED: Ledger rows are not immutable at the model or DB layer
- FIXED: updating/deleting guards on JournalVoucher (only status/reversal_of_id updatable) and JournalVoucherLine, FK now restrictOnDelete. Files: app/Models/JournalVoucher.php, JournalVoucherLine.php, migration 2026_09_21_110000_restrict_delete_on_journal_voucher_lines.php, tests/Feature/Tenant/Accounting/LedgerIntegrityTest.php.
- File: app/Models/JournalVoucher.php:19-31, app/Models/JournalVoucherLine.php:10-31,
  database/migrations/tenant/2026_08_25_100013_create_journal_voucher_lines_table.php (cascadeOnDelete).
- Wrong: neither model has updating/deleting guards. Any future controller, tinker session or bulk
  JournalVoucherLine::query()->update() can edit or delete a posted line, and deleting a voucher cascades
  its lines away. Immutability holds today only because no route does it.
- Evidence: grep for deleting/updating/booted in app/Models finds only Account and FiscalYear.
  reverse() itself does $locked->update(['status'=>'cancelled']), so status must stay writable.
- Fix: in booted() throw on JournalVoucherLine update/delete and on JournalVoucher delete, and allow
  only status and reversal_of_id to change on update. Change the FK to restrictOnDelete. Add a test.

### JE-02 (P1) FIXED: Accounts the engine depends on can be deleted or renamed, and any posted account can be deleted
- FIXED: Account::SYSTEM_CODES protected from delete/rename/recode/move, accounts with postings cannot be deleted, controller returns a validation error. Files: app/Models/Account.php, AccountController.php, LedgerIntegrityTest.php.
- File: app/Http/Controllers/Tenant/Accounting/AccountController.php:137-149; dependents in
  JournalVoucher.php (Account::where('code','AS1')->firstOrFail() in cashOrBankLines,
  Account::where('name','Profit & Loss')->firstOrFail() in rollForward) and
  AccountingReportController.php:839-855 (sweepVoucherIds keys off code AS11).
- Wrong: destroy() has no check. An account with postings hits the FK restrict and returns a raw 500 (no
  friendly error). An account with no postings, such as Cash-In-Hand AS1 or Profit & Loss, can be deleted,
  renamed or recoded via update(), after which every cash voucher or roll-forward correction throws
  ModelNotFoundException. If AS11 is missing, sweepVoucherIds treats every ClosingEntry as a sweep and
  the trial balance and income statement drift.
- Fix: refuse delete when the account has lines or is a system account (AS1, AS11, EXE9, INI22,
  Profit & Loss, TDS payable, and so on). Refuse code and name changes on those. Return a validation error.

### JE-03 (P1) FIXED: Moving an account to a different head after it has postings is not blocked
- FIXED: Account updating guard rejects a head change once the account has lines. Files: app/Models/Account.php, AccountController.php, LedgerIntegrityTest.php.
- File: AccountController.php:137-142 and :629-641 (validated()).
- Wrong: AccountGroupController blocks moving a group to another head once accounts have postings
  (AccountGroupController.php:72-76, test at ChartOfAccountsTest:234), but Account::update lets an admin
  repoint account_group_id/account_subgroup_id to any head. This silently reclassifies history: an
  Expense account becomes an Asset, the P&L sweep set changes, and closed-year statements no longer match
  what was filed.
- Fix: same guard in update(): if the account has lines and the resolved head changes, reject.

### JE-04 (P1) FIXED: Concurrent close vs post: fiscal year status is read without a lock
- FIXED: post() and reverse() now read the fiscal year with lockForUpdate (close() already locked it). Files: app/Models/JournalVoucher.php, LedgerIntegrityTest.php. Note: SQLite makes the lock a no-op, so the race itself is verified by review.
- File: JournalVoucher.php post() (FiscalYear::findOrFail / FiscalYear::current() with no lockForUpdate)
  and reverse() ($locked->fiscalYear()->firstOrFail()).
- Wrong: the voucher row and sequence row are locked, but the fiscal year row is not. A FiscalYear::close()
  running in parallel can pass its sweep while a voucher that read the year as Open commits afterwards, leaving
  an unswept posting in a closed year (the balance sheet then trips assertBalanced).
- Evidence: no lockForUpdate on FiscalYear in post(); nextVoucherNumber() locks only voucher_sequences.
- Fix: lock the FiscalYear row (lockForUpdate) in post() and reverse(), and have close() take the same lock.
  Add a test that closes and posts back to back.

### JE-05 (P2) Reversal and cancellation fail once the calendar passes the open year's end date
- File: JournalVoucher.php reverse() (uses static::today()), assertDateInsideFiscalYear().
- Wrong: the reversal is always dated today (Kathmandu) but written into the original's open year, and write()
  requires the date to be inside that year. In the days after Asar end and before the admin closes the year,
  every cancel of a manual voucher and every source-document cancel (all callers of reverse()) throws
  "date is outside fiscal year". The reversal also lands in a later report window than the original.
- Fix: clamp the reversal date to min(today, year end), or accept an explicit date and validate it.

### JE-06 (P2) reverse() does not check the target type
- File: JournalVoucher.php reverse(); callers AccountController.php:575-594.
- Wrong: only callers (cancel() via manuallyCancellableTypes, the opening-balance narration check) restrict
  what may be reversed. reverse() will reverse a Reversal voucher (un-cancelling with no audit link), a
  ClosingEntry, or a RollForwardAdjustment. Defence in depth is absent.
- Fix: refuse VoucherType::Reversal, ClosingEntry, RollForwardAdjustment in reverse() itself unless an
  explicit internal flag is passed.

### JE-07 (P2) No upper bound or per-line constraints on amounts and narration in the model
- File: JournalVoucherController.php:54-62, JournalVoucher::validateLines().
- Wrong: debit/credit have no max, while the column is decimal(20,2). A value over 18 integer digits gives a
  raw SQL out-of-range 500 on MySQL strict mode. validateLines() (used by every module posting) does not cap
  narration at 255 and does not verify account_id exists (FK only). The manual journal also lacks distinct on
  account_id (cash/bank has it), so Dr X 100 / Cr X 100 posts as a no-op voucher that consumes a number.
- Fix: add a max amount rule, validate narration length in validateLines(), reject a voucher that nets to zero
  on every account, or add distinct.

### JE-08 (P2) Ledger and journal list expose the full books to non-admin staff
- File: routes/tenant-ledger.php:31-58; JournalVoucherController::index and print.
- Wrong: the accounting reports are role:admin ("different sensitivity class"), but accounts/{account}/ledger
  (plus print and export) and the journal-vouchers index and print are open to any authenticated staff. A counter
  user can pull the ledger of Capital, Loans or any bank account for any fiscal year, the same data the
  admin-only trial balance protects. index() also ->get()s every voucher with all lines and no pagination
  (unbounded response, grows with the year).
- Fix: put ledger, print and export behind role:admin (or a permission), or restrict to party accounts.
  Paginate index().

### JE-09 (P2) Ledger and report inputs are not validated
- File: AccountController.php:162-176, 216-224, 226-236; AccountingReportController.php:778-810.
- Wrong: fiscal_year_id, from, to are read with integer()/string() and never validated as dates. A bogus
  fiscal_year_id on the reports silently falls back to the open year (wrong-year figures shown without an
  error). String max()/min() on unvalidated dates yields odd windows (from=abc sorts after any date).
- Fix: validate fiscal_year_id (exists) and from/to (date) on every report and ledger action.

### JE-10 (P2) Trial balance silently drops accounts with no resolvable head/group
- File: AccountingReportController.php:1098-1101.
- Wrong: "if (! $head || ! $group) { continue; }" removes the row from the report and from the totals with no
  warning. Because the totals come from the rows, the trial balance can show equal Dr/Cr while the ledger is
  out of step. The balance sheet has assertBalanced; the trial balance has no equivalent check.
- Fix: collect skipped accounts into an Unclassified row or a visible warning, and check that closing debit
  total equals closing credit total.

### JE-11 (P2) Test gaps
- No test exercises nextVoucherNumber() under concurrency (SQLite in-memory makes lockForUpdate a no-op, so
  the MySQL locking claim is unverified).
- No test that a Reversal or ClosingEntry cannot be reversed via JournalVoucher::reverse() directly.
- No test for deleting a system account or one with postings (JE-02), or moving an account across heads (JE-03).
- No test for line immutability (JE-01), reversal after the year end date (JE-05), or a trial balance with an
  unclassified account (JE-10).
- No test for oversize amount or narration through JournalVoucher::post() directly (JE-07).

## Checked and fine
- Balance enforcement: validateLines() uses Money (exact 2dp), rejects negatives, requires exactly one
  positive side per line, at least two lines, and compares exact sums; decimal:0,2 blocks third-decimal input,
  and Money::of rejects it even when the controller is bypassed.
- post(), reverse() and cancel() run inside DB::transaction; voucher and lines are created together.
- Numbering: insertOrIgnore plus lockForUpdate on voucher_sequences with a unique (fiscal_year_id,
  voucher_type) index; voucher number unique per year and type; reversals use their own series.
- Reversal: mirrored lines, reversal_of_id unique (double reverse blocked at DB level), row lock on the
  original, closed-year targets refused, cancel restricted to journal and cash/bank types with no source record.
- Period locking: ClosedFiscalYearGuard::ensurePostable (reopen plus reason plus admin),
  assertDateInsideFiscalYear in write(), one open year enforced in FiscalYear saving, roll-forward retargets
  P&L lines to Profit & Loss.
- Authorization: journal store, cash-bank store, cancel, chart writes, opening balance import and all accounting
  reports are role:admin; Arr::only prevents a client choosing voucher_type on the manual journal.
- Tenancy: database-per-tenant, so there is no tenant_id scoping to miss.
- Reports: balances use scaled-integer SUMs cast to Money (no floats), whereDate avoids the SQLite time suffix
  bug, windows are clamped inside one fiscal year, opening balance excludes cross-year sums, running balance is
  Money, sweep vouchers excluded structurally, balance sheet has assertBalanced.
- Existing tests cover unbalanced, rounding, negative, zero, out-of-year date, closed and reopened years,
  double cancel, per-type numbering, staff 403s and reversal netting to zero.
