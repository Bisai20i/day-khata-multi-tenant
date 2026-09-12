# T14 Accounting parity features

Phase 4 | Parallel with T12, T13 | Runs only after the user has run the full suite green after Phase 3 |
Audit refs: section 3 "Accounting" in `plans/gap-audit-2026-09-11.md`.

In Phase 4 you own the files that T03, T09 and T11 owned in earlier phases. Build on their committed code;
do not undo any earlier rule.

## Owned files

Everything under "Owned files" in `todo/T03-ledger-core-numbering-settings.md`,
`todo/T09-print-compliance.md` and `todo/T11-books-fiscal-year-assets.md`, plus new PDF views under
`resources/views/pdf/` for the documents listed below, new exports under `app/Exports/`, new tests.
Migrations with prefix `2026_09_14_14`.

## Tasks

- [ ] 1. Cash and bank vouchers: new voucher types `CashReceipt`, `CashPayment`, `BankReceipt`,
  `BankPayment`, `Contra`, each with its own number series; one form where the user picks the other accounts
  and the cash/bank line is added automatically; exact balance via `money.js`; listed and filterable in the
  Day Book.
- [ ] 2. Print and export for accounting: PDF (dompdf, existing layout) and Excel for Trial Balance, Income
  Statement, Balance Sheet, Day Book, Cash Book, Bank Book, account ledger, Debtors/Creditors (coordinate with
  T10's export via a cross-file request if the export lives in T10's file), and journal voucher print. Every
  print goes through `PrintLog::record()`.
- [ ] 3. Account ledger: arbitrary date range inside the fiscal year with correct opening balance, and a
  per-line drill-down to the source document (bill number, link).
- [ ] 4. Cancelled documents report: every cancelled sale, purchase, return, receipt, payment, capital
  document and journal voucher with number, date, cancel date, who and reason (from the C5 columns), export.
- [ ] 5. Fixed assets: register an existing asset with an opening cost and accumulated depreciation without a
  payment (posted against the opening-balance equity account), and record input VAT on a fixed asset purchase
  (Dr VAT receivable) so it reaches the VAT books.
- [ ] 6. Tests (write, do not run) for every item above.
