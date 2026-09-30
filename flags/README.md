# Flags index (audit of sales, purchases, journal entries, capital and services)

Audited 2026-09-21 by read-only agents. Severities were assigned by each agent with no shared rubric, so they are unchecked. All fixes are committed; the full suite (1,540 tests) passed on 2026-09-30.

| File | Flags | P0/P1 status | P2 status |
|---|---|---|---|
| sales.md | SAL-01..12 | SAL-01..04 FIXED | SAL-05, SAL-06, SAL-08..11 FIXED; SAL-12 FIXED; SAL-07 deferred |
| purchases.md | PUR-01..12 | PUR-01..03 FIXED | PUR-04..08, PUR-09..12 FIXED |
| journal-entries.md | JE-01..11 | JE-01..04 FIXED | JE-05..11 FIXED |
| capital-and-services.md | CS-01..11 | CS-01..03 FIXED (CS-05 also); CS-08 (P0) FIXED | CS-04, CS-06, CS-07, CS-09..11 FIXED |

"FIXED" means the code was changed and tests were written. Verified by the full test run on 2026-09-30.

PUR-10..12 and CS-08..11 came from checking the legacy cancelled-purchase fixes (2026-09-28) against this repo, using the single rubric below.

## Start of next session (done 2026-09-30)

All four steps below are complete: migrations ran, the suite is green, the cross-module review ran through F07/F10, and D3 accepted the list.

1. Run the new migrations: FK restrict on journal lines, purchase fiscal_year_id scope (check how existing rows are backfilled), capital purchase settlements.
2. Run the new tests: LedgerIntegrityTest, SalesP1FixesTest, PurchaseFlagFixesTest, CapitalPurchaseSettlementTest. Then the existing suites. SalesReturnWorkflowTest was edited (request as owner, approve/reject as a separate admin).
3. Cross-module review (not done): four agents edited shared code. Check the journal immutability guards (voucher only status and reversal_of_id updatable, lines immutable) against sales, purchase and capital flows, including builder narration updates on new vouchers. Check AccountUnderHead rule and SalesReturn::refundAccountQuery for consistency.
4. Confirm the system account list protected in Account.php (AS1, AS11, AS31, ASA23, CA2, EXE8, EXE20-22, INI20, INI30, LIA20, LIA21). It was inferred, not specified.

## Open follow-ups from the fixes

- ~~Vue unlinked purchase-return form needs the reason field and a "credit to supplier" option~~ Done 2026-09-29 (F02).
- ~~CapitalPurchaseSettlement is not registered with ActivityLogObserver~~ Done 2026-09-29 (F03).
- ~~SAL-04 decision not recorded in CONTRACTS C5~~ Recorded 2026-09-29 (F00).
- Tests assume seeded account codes AS1, EXE8, LIA21, a non-admin role in seeded roles, and factory heads not marked profit-and-loss.
- JE-04 lock (lockForUpdate) is only verified by review, since SQLite ignores it.
- Query-builder bulk deletes skip model guards; only the FK protects vouchers.
- Purchase flags were checked against CONTRACTS.md, not legacy (legacy purchase controllers are empty). Sales flags were not compared with legacy.

## Next step

Re-triaged on 2026-09-28 against one rubric and re-verified on the development head. The gap-fill plan (merged flags G-01..G-25, decisions D1-D4, chunks F00-F10, status board) is in `todo/flags/START.md`.
