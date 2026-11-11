<?php

namespace App\Support\Branches;

use App\Support\Branches\Audit\AuditContext;
use App\Support\Branches\Audit\BranchAuditResult;
use App\Support\Branches\Audit\DocumentChecks;
use App\Support\Branches\Audit\LedgerChecks;
use App\Support\Branches\Audit\NumberingChecks;
use App\Support\Branches\Audit\StockChecks;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Read-only audit of one tenant's multi-branch invariants (plan 12, section
 * 5.2, BR23): the logic behind `branches:audit`, returning structured
 * results so a page or a test can reuse it.
 *
 * Checks, in report order: Main sound; I1 vouchers balance per branch; I2
 * every voucher, line and document names a real branch; I3 Inter-Branch
 * Clearing nets to the goods in transit (company-wide and per pair) and
 * Stock Transferred equals Stock Received (periodic share); I8 the AS12 /
 * AS14 legs of transfer vouchers match the perpetual goods in transit
 * (BR25); I4 branch stock adds up to company stock; I8 perpetual stock
 * reconciles per branch, and perpetual transfer legs keep their dispatch
 * cost (BR25); I5 stores,
 * store-bearing documents and stock movements sit in one consistent
 * branch; I6 document vouchers are filed in the document's branch and
 * cross-branch settlements carry their clearing lines; I7 numbering. WARN
 * lists: clearing balances without a counterparty, transfers in transit,
 * series starting above 1, branches without a series code in a per-branch
 * year, inactive branches with open work. I6's access half (a restricted
 * user cannot read or post outside their branches) is behaviour, not data,
 * and is covered by tests, not here.
 *
 * Strictly read-only: every read is a SELECT, run inside
 * BranchContext::unrestricted() so the answer is the whole company's, and
 * nothing posts, locks or writes. All reads share one transaction on the
 * tenant connection (see inSnapshot()), so a posting committed while the
 * audit runs cannot make two checks see different books; the transaction
 * is always rolled back, there being nothing to commit. Aggregates are
 * computed in SQL with scaled integers (MySQL and SQLite alike); only the
 * capped example lists and the per-item stock valuation the reports
 * already build are loaded.
 * A check that throws becomes a FAIL naming the error, so one broken check
 * never hides the others.
 */
final class BranchAudit
{
    /**
     * Offending records listed per check; the count is always the full total.
     */
    public const LIST_LIMIT = 20;

    /**
     * Columns the checks rely on: the plan 12 schema up to BR20.
     *
     * @var array<string, list<string>>
     */
    private const REQUIRED_COLUMNS = [
        'branches' => ['id', 'is_main', 'is_active', 'series_code', 'default_store_id'],
        'stores' => ['branch_id'],
        'journal_vouchers' => ['branch_id', 'series_branch_id'],
        'journal_voucher_lines' => ['branch_id', 'counterparty_branch_id'],
        'voucher_sequences' => ['series_branch_id'],
        'fiscal_years' => ['numbering_scope'],
        'stock_transfers' => ['from_branch_id', 'to_branch_id', 'receipt_journal_voucher_id', 'reversal_journal_voucher_id', 'received_on'],
    ];

    /**
     * Runs every check for the current tenant as at $asOf (default today).
     *
     * @return list<BranchAuditResult>
     */
    public static function run(?string $asOf = null): array
    {
        $asOf ??= now()->toDateString();

        return BranchContext::unrestricted(fn (): array => self::inSnapshot(fn (): array => self::checks($asOf)));
    }

    /**
     * Runs $audit inside one transaction on the default (tenant) connection
     * so every read sees the same snapshot: InnoDB's REPEATABLE READ fixes
     * it at the first read and keeps it for the whole transaction. On MySQL
     * and MariaDB the transaction is also declared READ ONLY, so the server
     * itself refuses a write. That declaration is only possible when no
     * transaction is open yet (a caller's open transaction, e.g. a test's,
     * already fixes the isolation), so it is skipped then. Always rolled
     * back: nothing was written, and rolling back keeps it that way. SQLite
     * serialises writers, so a plain transaction is enough there.
     *
     * @param  Closure(): list<BranchAuditResult>  $audit
     * @return list<BranchAuditResult>
     */
    private static function inSnapshot(Closure $audit): array
    {
        $connection = DB::connection();

        if ($connection->transactionLevel() === 0 && in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
        }

        $connection->beginTransaction();

        try {
            return $audit();
        } finally {
            $connection->rollBack();
        }
    }

    /**
     * @return list<BranchAuditResult>
     */
    private static function checks(string $asOf): array
    {
        $missing = self::missingSchema();

        if ($missing !== []) {
            return [BranchAuditResult::fail(
                'schema',
                '-',
                'Multi-branch schema present',
                'The tenant database is missing '.implode(', ', $missing).'; run the tenant migrations before auditing.',
            )];
        }

        $context = AuditContext::load($asOf);
        $ledger = new LedgerChecks($context);
        $stock = new StockChecks($context);
        $documents = new DocumentChecks($context);
        $numbering = new NumberingChecks($context);

        $checks = [
            'branches.main' => fn (): BranchAuditResult => $documents->mainBranch(),
            'ledger.balanced_per_branch' => fn (): BranchAuditResult => $ledger->balancedPerBranch(),
            'ledger.branch_tags' => fn (): BranchAuditResult => $ledger->branchTags(),
            'documents.branch' => fn (): BranchAuditResult => $documents->documentBranches(),
            'ledger.clearing' => fn (): array => $ledger->clearing(),
            'ledger.stock_accounts' => fn (): BranchAuditResult => $ledger->stockAccounts(),
            'ledger.perpetual_transfer_legs' => fn (): BranchAuditResult => $ledger->perpetualTransferLegs(),
            'stock.branch_totals' => fn (): BranchAuditResult => $stock->branchTotals(),
            'stock.perpetual_by_branch' => fn (): BranchAuditResult => $stock->perpetualByBranch(),
            'stock.perpetual_transfer_costs' => fn (): BranchAuditResult => $stock->perpetualTransferCosts(),
            'stock.stores' => fn (): BranchAuditResult => $stock->stores(),
            'stock.document_stores' => fn (): BranchAuditResult => $stock->documentStores(),
            'stock.movement_stores' => fn (): BranchAuditResult => $stock->movementStores(),
            'documents.voucher_headers' => fn (): BranchAuditResult => $documents->voucherHeaders(),
            'documents.cross_branch_allocations' => fn (): BranchAuditResult => $documents->crossBranchAllocations(),
            'numbering' => fn (): array => $numbering->run(),
            'stock.in_transit' => fn (): BranchAuditResult => $stock->transfersInTransit(),
            'branches.inactive_open_work' => fn (): BranchAuditResult => $documents->inactiveBranchesWithOpenWork(),
        ];

        $results = [];

        foreach ($checks as $key => $check) {
            foreach (self::attempt($key, $check) as $result) {
                $results[] = $result;
            }
        }

        return $results;
    }

    /**
     * Whether any result is a FAIL.
     *
     * @param  list<BranchAuditResult>  $results
     */
    public static function hasFailures(array $results): bool
    {
        foreach ($results as $result) {
            if ($result->isFailure()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Closure(): (BranchAuditResult|list<BranchAuditResult>)  $check
     * @return list<BranchAuditResult>
     */
    private static function attempt(string $key, Closure $check): array
    {
        try {
            $outcome = $check();

            return $outcome instanceof BranchAuditResult ? [$outcome] : $outcome;
        } catch (Throwable $e) {
            return [BranchAuditResult::fail($key, '-', "Check {$key}", 'The check could not run: '.$e::class.': '.$e->getMessage())];
        }
    }

    /**
     * @return list<string> "table.column" (or "table") entries that are missing
     */
    private static function missingSchema(): array
    {
        $missing = [];

        foreach (self::REQUIRED_COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                $missing[] = $table;

                continue;
            }

            $present = Schema::getColumnListing($table);

            foreach ($columns as $column) {
                if (! in_array($column, $present, true)) {
                    $missing[] = "{$table}.{$column}";
                }
            }
        }

        return $missing;
    }
}
