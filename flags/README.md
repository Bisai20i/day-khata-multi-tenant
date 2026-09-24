# Flags index (audit of sales, purchases, journal entries, capital and services)

Audited 2026-09-21 by read-only agents. Severities were assigned by each agent with no shared rubric, so they are unchecked. Nothing here has been run, tested or committed.

| File | Flags | P0/P1 status | P2 status |
|---|---|---|---|
| sales.md | SAL-01..12 | SAL-01..04 FIXED | SAL-05..12 open |
| purchases.md | PUR-01..09 | PUR-01..03 FIXED | PUR-04..09 open |
| journal-entries.md | JE-01..11 | JE-01..04 FIXED | JE-05..11 open |
| capital-and-services.md | CS-01..07 | CS-01..03 FIXED (CS-05 also) | CS-04, CS-06, CS-07 open |

"FIXED" means the code was changed and tests were written. It does not mean verified by a test run.

## Start of next session

1. Run the new migrations: FK restrict on journal lines, purchase fiscal_year_id scope (check how existing rows are backfilled), capital purchase settlements.
2. Run the new tests: LedgerIntegrityTest, SalesP1FixesTest, PurchaseFlagFixesTest, CapitalPurchaseSettlementTest. Then the existing suites. SalesReturnWorkflowTest was edited (request as owner, approve/reject as a separate admin).
3. Cross-module review (not done): four agents edited shared code. Check the journal immutability guards (voucher only status and reversal_of_id updatable, lines immutable) against sales, purchase and capital flows, including builder narration updates on new vouchers. Check AccountUnderHead rule and SalesReturn::refundAccountQuery for consistency.
4. Confirm the system account list protected in Account.php (AS1, AS11, AS31, ASA23, CA2, EXE8, EXE20-22, INI20, INI30, LIA20, LIA21). It was inferred, not specified.

## Open follow-ups from the fixes

- Vue unlinked purchase-return form needs the reason field and a "credit to supplier" option, or it gets validation errors.
- CapitalPurchaseSettlement is not registered with ActivityLogObserver (needs AppServiceProvider).
- SAL-04 decision (role:admin on unlinked returns and approve/reject, no self-approval) is not recorded in todo/CONTRACTS.md C5.
- Tests assume seeded account codes AS1, EXE8, LIA21, a non-admin role in seeded roles, and factory heads not marked profit-and-loss.
- JE-04 lock (lockForUpdate) is only verified by review, since SQLite ignores it.
- Query-builder bulk deletes skip model guards; only the FK protects vouchers.
- Purchase flags were checked against CONTRACTS.md, not legacy (legacy purchase controllers are empty). Sales flags were not compared with legacy.

## Suggested next step

Re-triage all flags against a single rubric (P0: wrong books or a missing legacy feature, P1: books can be made wrong or a control is missing, P2: hardening), then work the P2s.
