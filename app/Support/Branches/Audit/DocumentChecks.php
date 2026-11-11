<?php

namespace App\Support\Branches\Audit;

use App\Models\Branch;
use App\Support\Branches\BranchAudit;
use App\Support\Branches\BranchDeactivationGuard;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Document consistency (plan 12, sections 5.3 and 5.4; the audit side of I2
 * and I6): Main is sound, every document names a real branch, each
 * document's vouchers are filed in the document's branch, a receipt or
 * payment settling another branch's document carries the clearing line
 * that keeps both branches balanced, and (WARN) no inactive branch still
 * holds open work (BranchDeactivationGuard, the same list that blocks a
 * deactivation).
 */
final class DocumentChecks
{
    /**
     * Tables whose rows carry their own branch column(s).
     *
     * @var array<string, list<string>>
     */
    private const BRANCH_COLUMNS = [
        'sales' => ['branch_id'],
        'purchases' => ['branch_id'],
        'sales_returns' => ['branch_id'],
        'purchase_returns' => ['branch_id'],
        'quotations' => ['branch_id'],
        'capital_purchases' => ['branch_id'],
        'capital_sales' => ['branch_id'],
        'capital_purchase_settlements' => ['branch_id'],
        'receipts' => ['branch_id'],
        'payments' => ['branch_id'],
        'stock_adjustments' => ['branch_id'],
        'stock_conversions' => ['branch_id'],
        'stock_transfers' => ['from_branch_id', 'to_branch_id'],
        'material_issue_notes' => ['branch_id'],
        'production_entries' => ['branch_id'],
        'fixed_assets' => ['branch_id'],
        'hr_staff' => ['branch_id'],
        'hr_payroll_runs' => ['branch_id'],
        'hr_payroll_lines' => ['branch_id'],
        'hr_payroll_payments' => ['branch_id'],
        'hr_staff_advances' => ['branch_id'],
    ];

    /**
     * Document voucher links whose voucher header must be filed in the
     * document's branch: [table, voucher column, branch column, parent].
     * With a parent [table, foreign key] the branch is read from the parent
     * row. Reversals keep the original's header branch
     * (JournalVoucher::reverse()), so they are listed too. Each poster was
     * read to confirm its header branch (BR05 to BR09, BR18).
     *
     * @var list<array{0: string, 1: string, 2: string, 3?: array{0: string, 1: string}}>
     */
    private const VOUCHER_LINKS = [
        ['sales', 'journal_voucher_id', 'branch_id'],
        ['sales', 'reversal_journal_voucher_id', 'branch_id'],
        ['purchases', 'journal_voucher_id', 'branch_id'],
        ['purchases', 'reversal_journal_voucher_id', 'branch_id'],
        ['sales_returns', 'journal_voucher_id', 'branch_id'],
        ['sales_returns', 'refund_journal_voucher_id', 'branch_id'],
        ['sales_returns', 'reversal_journal_voucher_id', 'branch_id'],
        ['purchase_returns', 'journal_voucher_id', 'branch_id'],
        ['purchase_returns', 'refund_journal_voucher_id', 'branch_id'],
        ['purchase_returns', 'reversal_journal_voucher_id', 'branch_id'],
        ['receipts', 'journal_voucher_id', 'branch_id'],
        ['receipts', 'reversal_journal_voucher_id', 'branch_id'],
        ['payments', 'journal_voucher_id', 'branch_id'],
        ['payments', 'reversal_journal_voucher_id', 'branch_id'],
        ['capital_sales', 'journal_voucher_id', 'branch_id'],
        ['capital_sales', 'reversal_journal_voucher_id', 'branch_id'],
        ['capital_purchases', 'journal_voucher_id', 'branch_id'],
        ['capital_purchases', 'reversal_journal_voucher_id', 'branch_id'],
        ['capital_purchase_settlements', 'journal_voucher_id', 'branch_id'],
        ['capital_purchase_settlements', 'reversal_journal_voucher_id', 'branch_id'],
        ['stock_adjustments', 'journal_voucher_id', 'branch_id'],
        ['stock_adjustments', 'reversal_journal_voucher_id', 'branch_id'],
        ['material_issue_notes', 'journal_voucher_id', 'branch_id'],
        ['material_issue_notes', 'reversal_journal_voucher_id', 'branch_id'],
        ['production_direct_costs', 'journal_voucher_id', 'branch_id', ['production_entries', 'production_entry_id']],
        ['production_completions', 'journal_voucher_id', 'branch_id', ['production_entries', 'production_entry_id']],
        ['fixed_assets', 'journal_voucher_id', 'branch_id'],
        ['fixed_assets', 'disposal_journal_voucher_id', 'branch_id'],
        ['fixed_asset_depreciations', 'journal_voucher_id', 'branch_id', ['fixed_assets', 'fixed_asset_id']],
        ['hr_payroll_runs', 'journal_voucher_id', 'branch_id'],
        ['hr_payroll_runs', 'reversal_journal_voucher_id', 'branch_id'],
        ['hr_payroll_payments', 'journal_voucher_id', 'branch_id'],
        ['hr_payroll_payments', 'reversal_journal_voucher_id', 'branch_id'],
        ['hr_staff_advances', 'journal_voucher_id', 'branch_id'],
        ['hr_staff_advances', 'reversal_journal_voucher_id', 'branch_id'],
        ['stock_transfers', 'journal_voucher_id', 'from_branch_id'],
        ['stock_transfers', 'receipt_journal_voucher_id', 'to_branch_id'],
        ['stock_transfers', 'reversal_journal_voucher_id', 'from_branch_id'],
    ];

    /**
     * Allocation tables: [allocation table, money document, its fk, settled
     * document, its fk]. A cross-branch allocation must leave a clearing
     * line in the settled document's branch on the money document's
     * voucher (BranchBalancer, hub = the money document's branch). The
     * settled "document" may be a line table carrying its own branch_id: a
     * salary payment settles payslips (hr_payroll_lines), each in its own
     * branch (SalaryPaymentPoster).
     *
     * Staff advances are deliberately not here: the receivable is posted
     * in the staff member's home branch at the time, and hr_staff.branch_id
     * may change afterwards (StaffController::homeBranchChange() allows it
     * once the advance is recovered), so the current home branch is no
     * record of where the advance's receivable sits. I1 still proves each
     * advance voucher balances inside every branch it touches.
     *
     * @var list<array{0: ?string, 1: string, 2: ?string, 3: string, 4: string}>
     */
    private const ALLOCATIONS = [
        ['receipt_allocations', 'receipts', 'receipt_id', 'sales', 'sale_id'],
        ['payment_allocations', 'payments', 'payment_id', 'purchases', 'purchase_id'],
        [null, 'capital_purchase_settlements', null, 'capital_purchases', 'capital_purchase_id'],
        ['hr_payroll_payment_lines', 'hr_payroll_payments', 'payroll_payment_id', 'hr_payroll_lines', 'payroll_line_id'],
    ];

    public function __construct(private readonly AuditContext $context) {}

    /**
     * Exactly one Main, and it is active (BranchContext and every default
     * resolve to it).
     */
    public function mainBranch(): BranchAuditResult
    {
        $mains = array_values(array_filter($this->context->branches, fn (object $branch): bool => $branch->is_main));
        $title = 'Exactly one active Main branch';

        if (count($mains) !== 1) {
            return BranchAuditResult::fail('branches.main', '-', $title, count($mains).' branch(es) are marked Main; there must be exactly one.');
        }

        if (! $mains[0]->is_active) {
            return BranchAuditResult::fail('branches.main', '-', $title, "Main ({$mains[0]->name}) is inactive.");
        }

        $active = count(array_filter($this->context->branches, fn (object $branch): bool => $branch->is_active));

        return BranchAuditResult::pass('branches.main', '-', $title, sprintf(
            '%s is Main; %d branch(es), %d active.',
            $mains[0]->name,
            count($this->context->branches),
            $active,
        ));
    }

    /**
     * Every document row names an existing branch (NULL or dangling ids;
     * the columns are NOT NULL with a foreign key, so this only finds rows
     * written around the schema or on a SQLite file without FK checks).
     */
    public function documentBranches(): BranchAuditResult
    {
        $findings = new AuditFindings;

        foreach (self::BRANCH_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                if (! $this->context->hasColumn($table, $column)) {
                    continue;
                }

                $query = DB::table($table.' as d')
                    ->leftJoin('branches as b', 'b.id', '=', 'd.'.$column)
                    ->whereNull('b.id');
                [$count, $ids] = AuditContext::distinctIds($query, 'd.id');
                $findings->add($count, array_map(fn (int $id): string => "{$table} #{$id} has no known {$column}", $ids));
            }
        }

        return $findings->result(
            'documents.branch',
            'I2',
            'Every document names a real branch',
            BranchAuditResult::FAIL,
            'Every document, staff, payroll and asset row names an existing branch.',
            ':count row(s) without a known branch.',
        );
    }

    /**
     * Each document's vouchers are filed (header branch_id) in the
     * document's branch, so the voucher lists, the series it is numbered
     * from (BR20) and the printed branch all agree with the document.
     */
    public function voucherHeaders(): BranchAuditResult
    {
        $findings = new AuditFindings;

        foreach (self::VOUCHER_LINKS as $link) {
            [$table, $voucherColumn, $branchColumn] = $link;
            $parent = $link[3] ?? null;

            if (! $this->context->hasColumn($table, $voucherColumn)) {
                continue;
            }

            $query = DB::table($table.' as d')
                ->join('journal_vouchers as v', 'v.id', '=', 'd.'.$voucherColumn);

            if ($parent !== null) {
                [$parentTable, $foreignKey] = $parent;

                if (! $this->context->hasColumn($table, $foreignKey) || ! $this->context->hasColumn($parentTable, $branchColumn)) {
                    continue;
                }

                $query->join($parentTable.' as o', 'o.id', '=', 'd.'.$foreignKey);
                $owner = 'o.'.$branchColumn;
            } else {
                if (! $this->context->hasColumn($table, $branchColumn)) {
                    continue;
                }

                $owner = 'd.'.$branchColumn;
            }

            $query->whereColumn('v.branch_id', '!=', $owner);
            $count = (clone $query)->count();

            if ($count === 0) {
                continue;
            }

            $rows = $query->orderBy('d.id')->limit(BranchAudit::LIST_LIMIT)
                ->get(['d.id as id', 'v.id as voucher_id', 'v.branch_id as voucher_branch_id', $owner.' as owner_branch_id']);
            $labels = $this->context->voucherLabels($rows->pluck('voucher_id')->map(fn (mixed $id): int => (int) $id)->unique()->values()->all());

            $findings->add($count, $rows->map(fn (object $row): string => sprintf(
                '%s #%d (%s): %s filed in %s, document in %s',
                $table,
                $row->id,
                $voucherColumn,
                $labels[(int) $row->voucher_id],
                $this->context->branchName($row->voucher_branch_id),
                $this->context->branchName($row->owner_branch_id),
            ))->all());
        }

        return $findings->result(
            'documents.voucher_headers',
            'I6',
            'Document vouchers are filed in the document\'s branch',
            BranchAuditResult::FAIL,
            'Every linked voucher (including reversals and refunds) is filed in its document\'s branch.',
            ':count voucher link(s) filed in another branch than their document.',
        );
    }

    /**
     * A receipt, payment, capital settlement or salary payment filed in
     * one branch that settles a document of another branch must carry, on
     * its own voucher, an Inter-Branch Clearing line in the settled
     * document's branch (the party line moves there, 5.3) whose
     * counterparty is the voucher's header branch. BranchBalancer makes the
     * header branch the hub and gives every other (spoke) branch exactly
     * that line, and each of these posters files the voucher in the money
     * document's branch and posts with autoBalance, so a missing line, or
     * one paired with another branch, means the settled branch's books are
     * not balanced against the branch that took or paid the money.
     */
    public function crossBranchAllocations(): BranchAuditResult
    {
        $findings = new AuditFindings;
        $crossBranch = 0;

        foreach (self::ALLOCATIONS as [$allocationTable, $moneyTable, $moneyKey, $documentTable, $documentKey]) {
            if ($allocationTable !== null && ! $this->context->hasColumn($allocationTable, $moneyKey)) {
                continue;
            }

            if ($allocationTable === null && ! $this->context->hasColumn($moneyTable, $documentKey)) {
                continue;
            }

            if (! $this->context->hasColumn($moneyTable, 'branch_id') || ! $this->context->hasColumn($documentTable, 'branch_id')) {
                continue;
            }

            $query = $allocationTable === null
                ? DB::table($moneyTable.' as p')->join($documentTable.' as d', 'd.id', '=', 'p.'.$documentKey)
                : DB::table($allocationTable.' as a')
                    ->join($moneyTable.' as p', 'p.id', '=', 'a.'.$moneyKey)
                    ->join($documentTable.' as d', 'd.id', '=', 'a.'.$documentKey);

            $query->whereColumn('p.branch_id', '!=', 'd.branch_id');
            $crossBranch += (clone $query)->count();

            $clearingId = $this->context->clearingAccountId;
            $query->whereNotExists(fn (QueryBuilder $lines) => $lines
                ->selectRaw('1')
                ->from('journal_voucher_lines as cl')
                ->join('journal_vouchers as mv', 'mv.id', '=', 'cl.journal_voucher_id')
                ->whereColumn('cl.journal_voucher_id', 'p.journal_voucher_id')
                ->whereColumn('cl.branch_id', 'd.branch_id')
                ->whereColumn('cl.counterparty_branch_id', 'mv.branch_id')
                ->where('cl.account_id', $clearingId ?? 0));

            $count = (clone $query)->count();

            if ($count === 0) {
                continue;
            }

            $rows = $query->orderBy('p.id')->orderBy('d.id')->limit(BranchAudit::LIST_LIMIT)
                ->get(['p.id as money_id', 'p.branch_id as money_branch_id', 'd.id as document_id', 'd.branch_id as document_branch_id']);

            $findings->add($count, $rows->map(fn (object $row): string => sprintf(
                '%s #%d (%s) settles %s #%d (%s) with no clearing line in %s toward the voucher\'s header branch',
                $moneyTable,
                $row->money_id,
                $this->context->branchName($row->money_branch_id),
                $documentTable,
                $row->document_id,
                $this->context->branchName($row->document_branch_id),
                $this->context->branchName($row->document_branch_id),
            ))->all());
        }

        return $findings->result(
            'documents.cross_branch_allocations',
            'I6',
            'Cross-branch settlements carry their clearing lines',
            BranchAuditResult::FAIL,
            $crossBranch === 0
                ? 'No receipt, payment, settlement or salary payment crosses branches.'
                : "All {$crossBranch} cross-branch allocation(s) carry a clearing line in the settled document's branch toward the voucher's header branch.",
            ':count cross-branch allocation(s) without a clearing line in the settled document\'s branch toward the voucher\'s header branch.',
        );
    }

    /**
     * WARN: inactive branches that still hold open work (unpaid documents,
     * payslips, advances, assets, quotations, return requests, production,
     * draft payroll, transfers in transit, stock). Deactivation does not
     * lock out postings in flight (BR10 open point 2), so something can
     * land in a branch just after it was deactivated; such items would
     * dead-end, as the posting engine refuses inactive branches.
     */
    public function inactiveBranchesWithOpenWork(): BranchAuditResult
    {
        $findings = new AuditFindings;
        $inactive = array_filter($this->context->branches, fn (object $branch): bool => ! $branch->is_active);

        foreach ($inactive as $branchRow) {
            $branch = Branch::query()->find($branchRow->id);

            if ($branch === null) {
                continue;
            }

            foreach (BranchDeactivationGuard::blockers($branch) as $blocker) {
                $examples = $blocker['examples'] === [] ? '' : ' ('.implode(', ', $blocker['examples']).($blocker['count'] > count($blocker['examples']) ? ', ...' : '').')';
                $findings->add(1, ["{$branch->name}: {$blocker['count']} {$blocker['label']}{$examples}; {$blocker['action']}"]);
            }
        }

        return $findings->result(
            'branches.inactive_open_work',
            'I6',
            'Inactive branches hold no open work',
            BranchAuditResult::WARN,
            $inactive === [] ? 'No inactive branch.' : count($inactive).' inactive branch(es), none with open work.',
            ':count kind(s) of open work left in inactive branches; reactivate the branch to clear them.',
        );
    }
}
