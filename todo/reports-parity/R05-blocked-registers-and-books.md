# R05 Registers and books (BLOCKED, do not start)

These reports live in controllers that the user's own parallel sessions still have uncommitted changes in:

- `app/Http/Controllers/Tenant/Reports/SalesPurchaseReportController.php` (Debtors, Creditors, Aged reports)
  holds the **Sales Register, Purchase Register, Sales VAT Book, Purchase VAT Book, Sales Return Register,
  Purchase Return Register**.
- `app/Http/Controllers/Tenant/Reports/AccountingReportController.php` (Ledger session) holds the
  **Day Book, Cash Book, Bank Book**.

Start only after the user confirms those sessions are committed. Until then any edit risks overwriting their
work. When unblocked, split into R05a (Sales and Purchase registers plus VAT books and return registers) and
R05b (Day Book, Cash Book, Bank Book), which are then independent.

## Known gaps to cover when unblocked

- Registers: strict dates, customer / supplier / payment-mode / item-category filters, totals tying to the VAT
  Summary, Excel and PDF parity, BS date columns, links to documents.
- VAT books: legacy `salesVatReport`, `purchaseVatReport`, `salesReturnVatReport`, `purchaseReturnVatReport`,
  `allVatReport` columns checked against the Nepal statutory layout; the Unified VAT Register already covers the
  combined view (batch 1), so keep the two consistent.
- Day Book: voucher-type filter, drill to the voucher, Dr and Cr totals that tie to the Trial Balance.
- Cash and Bank Book: opening balance carry-over correct at fiscal-year boundaries (known open risk: the jump
  when `from` is one day past the year start), per-bank-account selector, running balance, print and export.
