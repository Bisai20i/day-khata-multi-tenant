<?php

namespace App\Support\Branches\Audit;

use App\Enums\VoucherType;
use App\Support\Branches\BranchAccounts;
use App\Support\Branches\BranchAudit;
use App\Support\Branches\BranchReportTotals;
use App\Support\Branches\InterBranchBalances;
use App\Support\Branches\StockInTransit;
use App\Support\Manufacturing\ManufacturingAccounts;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Ledger invariants of plan 12 (section 5.2): I1 (each voucher balances per
 * branch), I2 (no line or header without a real branch) and I3
 * (Inter-Branch Clearing nets to zero, Stock Transferred equals Stock
 * Received), each per fiscal year where it is a per-year fact.
 *
 * I3 allows exactly one legitimate gap, the goods in transit at the year's
 * end (StockInTransit::valueByBranchPair(), "as booked"), the same rule the
 * Inter-branch balances report and the year-end gate use. Opening lines
 * carry each year's clearing balances forward, so a year's clearing nets
 * are cumulative and compare against the transit at its end.
 *
 * Since BR25 a transfer between branches may carry raw material and
 * finished goods: clearing still carries the whole value, INI23 / EXE11
 * only the periodic share and AS12 / AS14 the perpetual share, so each
 * check compares against the matching StockInTransit basis.
 */
final class LedgerChecks
{
    public function __construct(private readonly AuditContext $context) {}

    /**
     * I1: per (voucher, line branch), debits equal credits. Clearing lines
     * are ordinary lines of the voucher, so a voucher BranchBalancer
     * balanced passes. One grouped pass over the lines; cancelled vouchers
     * are included (their lines stay in the books and were posted
     * balanced).
     */
    public function balancedPerBranch(): BranchAuditResult
    {
        $net = 'SUM('.AuditContext::paisa('journal_voucher_lines.debit').') - SUM('.AuditContext::paisa('journal_voucher_lines.credit').')';

        $unbalanced = DB::table('journal_voucher_lines')
            ->selectRaw("journal_voucher_id, branch_id, {$net} as net_scaled")
            ->groupBy('journal_voucher_id', 'branch_id')
            ->havingRaw("{$net} <> 0");

        $count = DB::query()->fromSub($unbalanced, 'unbalanced')->distinct()->count('journal_voucher_id');
        $findings = new AuditFindings;

        if ($count > 0) {
            $rows = DB::query()->fromSub($unbalanced, 'unbalanced')
                ->orderBy('journal_voucher_id')
                ->orderBy('branch_id')
                ->limit(BranchAudit::LIST_LIMIT)
                ->get();

            $labels = $this->context->voucherLabels($rows->pluck('journal_voucher_id')->map(fn (mixed $id): int => (int) $id)->unique()->values()->all());
            $items = [];

            foreach ($rows as $row) {
                $netMoney = AuditContext::money($row->net_scaled);
                $items[] = sprintf(
                    '%s: %s is off by %s %s',
                    $labels[(int) $row->journal_voucher_id],
                    $this->context->branchName($row->branch_id),
                    $netMoney->abs()->toString(),
                    $netMoney->isPositive() ? 'Dr' : 'Cr',
                );
            }

            $findings->add($count, $items);
        }

        $total = DB::table('journal_vouchers')->count();

        return $findings->result(
            'ledger.balanced_per_branch',
            'I1',
            'Vouchers balance per branch',
            BranchAuditResult::FAIL,
            "All {$total} voucher(s) balance inside every branch.",
            ':count voucher(s) do not balance inside one of their branches.',
        );
    }

    /**
     * I2: every line and every header names an existing branch; a clearing
     * counterparty names an existing branch other than the line's own.
     * A LEFT JOIN catches NULL and dangling ids alike (SQLite does not
     * always enforce foreign keys).
     */
    public function branchTags(): BranchAuditResult
    {
        $findings = new AuditFindings;

        $lines = DB::table('journal_voucher_lines as l')
            ->leftJoin('branches as b', 'b.id', '=', 'l.branch_id')
            ->whereNull('b.id');
        $this->addVouchers($findings, $lines, 'l.journal_voucher_id', 'has a line without a known branch');

        $counterparties = DB::table('journal_voucher_lines as l')
            ->leftJoin('branches as cb', 'cb.id', '=', 'l.counterparty_branch_id')
            ->whereNotNull('l.counterparty_branch_id')
            ->where(fn (QueryBuilder $bad) => $bad->whereNull('cb.id')->orWhereColumn('l.counterparty_branch_id', 'l.branch_id'));
        $this->addVouchers($findings, $counterparties, 'l.journal_voucher_id', 'has a clearing line whose counterparty branch is unknown or its own branch');

        $headers = DB::table('journal_vouchers as v')
            ->leftJoin('branches as b', 'b.id', '=', 'v.branch_id')
            ->whereNull('b.id');
        $this->addVouchers($findings, $headers, 'v.id', 'has no known header branch');

        // A header without lines passes I1 and the line checks above
        // vacuously, yet a posted voucher always has lines.
        $empty = DB::table('journal_vouchers as v')
            ->whereNotExists(fn (QueryBuilder $lines) => $lines
                ->selectRaw('1')
                ->from('journal_voucher_lines as el')
                ->whereColumn('el.journal_voucher_id', 'v.id'));
        $this->addVouchers($findings, $empty, 'v.id', 'has no lines');

        return $findings->result(
            'ledger.branch_tags',
            'I2',
            'Every voucher and line names a real branch',
            BranchAuditResult::FAIL,
            'Every voucher header, line and clearing counterparty names an existing branch, and every voucher has lines.',
            ':count voucher problem(s) with a missing or unknown branch, or no lines.',
        );
    }

    /**
     * I3, clearing half: per fiscal year, ASA25 nets to the value in
     * transit company-wide (FAIL), and each pair of branches' two sides
     * add up to the transit between them (FAIL). Balances without a
     * counterparty (carried forward before BR19) are a separate WARN.
     * Clearing carries a transfer's whole value, every inventory class, so
     * the transit here is StockInTransit::ALL (BR25).
     *
     * @return list<BranchAuditResult>
     */
    public function clearing(): array
    {
        $title = 'Inter-Branch Clearing nets to zero';

        if ($this->context->clearingConflict !== null) {
            return [BranchAuditResult::warn('ledger.clearing', 'I3', $title, ucfirst($this->context->clearingConflict).'. Nothing to check.')];
        }

        if ($this->context->clearingAccountId === null) {
            return [BranchAuditResult::warn('ledger.clearing', 'I3', $title, 'The chart has no Inter-Branch Clearing account (ASA25); run the tenant migrations. Nothing to check.')];
        }

        $findings = new AuditFindings;
        $unattributed = new AuditFindings;
        $years = 0;

        foreach ($this->context->postedFiscalYears() as $year) {
            $nets = InterBranchBalances::netsByPair(
                $this->context->clearingAccountId,
                fn (Builder $vouchers) => $vouchers->where('fiscal_year_id', $year->id),
            );
            $transit = StockInTransit::valueByBranchPair($year->end_date);

            if ($nets === [] && $transit === []) {
                continue;
            }

            $years++;
            $total = Money::sum($this->flatten($nets));
            $inTransit = Money::sum($this->flatten($transit));

            if (! $total->isEqualTo($inTransit)) {
                $findings->addOne(sprintf(
                    'FY %s: clearing nets to %s across all branches, goods in transit explain %s, unexplained %s',
                    $year->name,
                    $total->toString(),
                    $inTransit->toString(),
                    $total->minus($inTransit)->toString(),
                ));
            }

            foreach ($this->pairs($nets, $transit) as [$left, $right]) {
                $leftSide = $nets[$left][$right] ?? Money::zero();
                $rightSide = $nets[$right][$left] ?? Money::zero();
                $expected = ($transit[$left][$right] ?? Money::zero())->plus($transit[$right][$left] ?? Money::zero());
                $difference = $leftSide->plus($rightSide)->minus($expected);

                if (! $difference->isZero()) {
                    $findings->addOne(sprintf(
                        'FY %s: %s shows %s toward %s, which shows %s back; in transit %s; unexplained %s',
                        $year->name,
                        $this->context->branchName($left),
                        $leftSide->toString(),
                        $this->context->branchName($right),
                        $rightSide->toString(),
                        $expected->toString(),
                        $difference->toString(),
                    ));
                }
            }

            foreach ($nets as $branchId => $byCounterparty) {
                $amount = $byCounterparty[InterBranchBalances::UNATTRIBUTED] ?? null;

                if ($amount !== null) {
                    $unattributed->addOne(sprintf(
                        'FY %s: %s holds %s %s with no counterparty branch',
                        $year->name,
                        $this->context->branchName($branchId),
                        $amount->abs()->toString(),
                        $amount->isPositive() ? 'Dr' : 'Cr',
                    ));
                }
            }
        }

        return [
            $findings->result(
                'ledger.clearing',
                'I3',
                $title,
                BranchAuditResult::FAIL,
                $years === 0 ? 'No clearing entries yet.' : "Clearing nets to the goods in transit, company-wide and per pair, in {$years} fiscal year(s).",
                ':count clearing difference(s) not explained by goods in transit.',
            ),
            $unattributed->result(
                'ledger.clearing_unattributed',
                'I3',
                'Clearing balances without a counterparty',
                BranchAuditResult::WARN,
                'Every clearing balance names the branch it is owed by or to.',
                ':count clearing balance(s) carry no counterparty branch (carried forward before pair tracking); the pairwise report lists them as unattributed.',
            ),
        ];
    }

    /**
     * I3, stock half: per fiscal year, Stock Transferred to Branches (INI23,
     * credit) less Stock Received from Branches (EXE11, debit), year-end
     * closing entries left out, equals the change in goods in transit over
     * the year. Only the periodic (trading) share of a transfer goes through
     * INI23 / EXE11 (BR25: raw material and finished goods leave and
     * re-enter AS12 / AS14 instead, perpetualTransferLegs()), so the change
     * in transit is on StockInTransit::PERIODIC; clearing() keeps the whole
     * value. Same formula as the Inter-branch balances report. A WARN,
     * and nothing checked, when either code belongs to one of the tenant's
     * own accounts (AuditContext::controlAccount()): its postings are not
     * transfers, so the formula would compare unrelated figures.
     */
    public function stockAccounts(): BranchAuditResult
    {
        $title = 'Stock Transferred equals Stock Received';

        $found = [];
        $conflicts = [];

        foreach ([BranchAccounts::STOCK_TRANSFERRED_CODE, BranchAccounts::STOCK_RECEIVED_CODE] as $code) {
            [$id, $conflict] = AuditContext::controlAccount($code);

            if ($conflict !== null) {
                $conflicts[] = $conflict;
            } elseif ($id !== null) {
                $found[$code] = $id;
            }
        }

        if ($conflicts !== []) {
            return BranchAuditResult::warn('ledger.stock_accounts', 'I3', $title, ucfirst(implode('; ', $conflicts)).'. Nothing to check.');
        }

        $accountIds = collect($found);

        if ($accountIds->isEmpty()) {
            return BranchAuditResult::warn('ledger.stock_accounts', 'I3', $title, 'The chart has neither INI23 nor EXE11; run the tenant migrations. Nothing to check.');
        }

        $findings = new AuditFindings;
        $years = 0;

        foreach ($this->context->postedFiscalYears() as $year) {
            $nets = BranchReportTotals::netByAccountAndBranch(
                fn (Builder $vouchers) => $vouchers->where('fiscal_year_id', $year->id)
                    ->where('voucher_type', '!=', VoucherType::ClosingEntry->value),
                null,
                $accountIds->values()->all(),
            );

            $net = fn (string $code): Money => $accountIds->has($code)
                ? Money::sum(array_values($nets[$accountIds[$code]] ?? []))
                : Money::zero();

            $transferred = $net(BranchAccounts::STOCK_TRANSFERRED_CODE)->negated();
            $received = $net(BranchAccounts::STOCK_RECEIVED_CODE);
            $dayBefore = CarbonImmutable::parse($year->start_date)->subDay()->toDateString();
            $expected = StockInTransit::totalValue($year->end_date, StockInTransit::PERIODIC)
                ->minus(StockInTransit::totalValue($dayBefore, StockInTransit::PERIODIC));

            if ($transferred->isZero() && $received->isZero() && $expected->isZero()) {
                continue;
            }

            $years++;
            $difference = $transferred->minus($received)->minus($expected);

            if (! $difference->isZero()) {
                $findings->addOne(sprintf(
                    'FY %s: transferred %s, received %s, change in transit %s, unexplained %s',
                    $year->name,
                    $transferred->toString(),
                    $received->toString(),
                    $expected->toString(),
                    $difference->toString(),
                ));
            }
        }

        return $findings->result(
            'ledger.stock_accounts',
            'I3',
            $title,
            BranchAuditResult::FAIL,
            $years === 0 ? 'No branch stock transfers posted yet.' : "Stock Transferred less Stock Received equals the change in transit in {$years} fiscal year(s).",
            ':count fiscal year(s) where Stock Transferred and Stock Received differ by more than the goods in transit.',
        );
    }

    /**
     * BR25, perpetual legs of transfers between branches: per fiscal year,
     * the net debit on Raw Material and Finished Goods (AS12 + AS14) over
     * the vouchers a transfer between branches references (dispatch,
     * receipt, reversal of a cancelled dispatch) equals minus the change in
     * perpetual value in transit over the year (StockInTransit::PERPETUAL,
     * as booked). A dispatch credits the sender's class account by the
     * perpetual lines' value and puts it in transit; a receipt or a
     * cancellation debits it back and takes it out of transit. Together
     * with stockAccounts() (periodic share) and clearing() (whole value)
     * this proves every transfer voucher split its value between INI23 /
     * EXE11 and AS12 / AS14 as the transfer's lines say. A WARN, and
     * nothing checked, before the BR25 migration (no perpetual_value
     * column) or when the chart has neither class account.
     */
    public function perpetualTransferLegs(): BranchAuditResult
    {
        $title = 'Perpetual transfer legs match the goods in transit';

        if (! $this->context->hasColumn('stock_transfers', 'perpetual_value')) {
            return BranchAuditResult::warn('ledger.perpetual_transfer_legs', 'I8', $title, 'stock_transfers.perpetual_value is missing; run the tenant migrations. Nothing to check.');
        }

        $accountIds = [];

        foreach ([ManufacturingAccounts::RAW_MATERIAL, ManufacturingAccounts::FINISHED_GOODS] as $code) {
            $id = DB::table('accounts')->where('code', $code)->orderBy('id')->value('id');

            if ($id !== null) {
                $accountIds[] = (int) $id;
            }
        }

        if ($accountIds === []) {
            return BranchAuditResult::warn('ledger.perpetual_transfer_legs', 'I8', $title, 'The chart has neither AS12 nor AS14; run the tenant migrations. Nothing to check.');
        }

        $net = 'SUM('.AuditContext::paisa('l.debit').') - SUM('.AuditContext::paisa('l.credit').')';

        $netByYear = DB::table('journal_voucher_lines as l')
            ->join('journal_vouchers as v', 'v.id', '=', 'l.journal_voucher_id')
            ->whereIn('l.account_id', $accountIds)
            ->whereExists(fn (QueryBuilder $transfers) => $transfers
                ->selectRaw('1')
                ->from('stock_transfers as t')
                ->whereColumn('t.from_branch_id', '!=', 't.to_branch_id')
                ->where(fn (QueryBuilder $references) => $references
                    ->whereColumn('t.journal_voucher_id', 'v.id')
                    ->orWhereColumn('t.receipt_journal_voucher_id', 'v.id')
                    ->orWhereColumn('t.reversal_journal_voucher_id', 'v.id')))
            ->groupBy('v.fiscal_year_id')
            ->selectRaw("v.fiscal_year_id as fiscal_year_id, {$net} as net_scaled")
            ->get()
            ->mapWithKeys(fn (object $row): array => [(int) $row->fiscal_year_id => AuditContext::money($row->net_scaled)])
            ->all();

        $findings = new AuditFindings;
        $years = 0;

        foreach ($this->context->postedFiscalYears() as $year) {
            $ledgerNet = $netByYear[(int) $year->id] ?? Money::zero();
            $dayBefore = CarbonImmutable::parse($year->start_date)->subDay()->toDateString();
            $change = StockInTransit::totalValue($year->end_date, StockInTransit::PERPETUAL)
                ->minus(StockInTransit::totalValue($dayBefore, StockInTransit::PERPETUAL));

            if ($ledgerNet->isZero() && $change->isZero()) {
                continue;
            }

            $years++;
            $difference = $ledgerNet->plus($change);

            if (! $difference->isZero()) {
                $findings->addOne(sprintf(
                    'FY %s: transfer vouchers move AS12 + AS14 by %s %s, perpetual goods in transit changed by %s, unexplained %s',
                    $year->name,
                    $ledgerNet->abs()->toString(),
                    $ledgerNet->isNegative() ? 'Cr' : 'Dr',
                    $change->toString(),
                    $difference->toString(),
                ));
            }
        }

        return $findings->result(
            'ledger.perpetual_transfer_legs',
            'I8',
            $title,
            BranchAuditResult::FAIL,
            $years === 0 ? 'No raw material or finished goods transferred between branches yet.' : "Transfer vouchers move AS12 + AS14 by exactly the change in perpetual goods in transit in {$years} fiscal year(s).",
            ':count fiscal year(s) where the AS12 / AS14 legs of transfer vouchers differ from the perpetual goods in transit.',
        );
    }

    /**
     * Every unordered pair of distinct branches that has a clearing balance
     * or goods in transit in either direction, lower id first.
     *
     * @param  array<int, array<int, Money>>  $nets
     * @param  array<int, array<int, Money>>  $transit
     * @return list<array{0: int, 1: int}>
     */
    private function pairs(array $nets, array $transit): array
    {
        $pairs = [];

        foreach ([$nets, $transit] as $map) {
            foreach ($map as $branchId => $byCounterparty) {
                foreach (array_keys($byCounterparty) as $counterpartyId) {
                    if ($counterpartyId === InterBranchBalances::UNATTRIBUTED || $counterpartyId === $branchId) {
                        continue;
                    }

                    $pair = [min($branchId, $counterpartyId), max($branchId, $counterpartyId)];
                    $pairs[$pair[0].'-'.$pair[1]] = $pair;
                }
            }
        }

        ksort($pairs);

        return array_values($pairs);
    }

    /**
     * @param  array<int, array<int, Money>>  $nested
     * @return list<Money>
     */
    private function flatten(array $nested): array
    {
        $flat = [];

        foreach ($nested as $inner) {
            foreach ($inner as $value) {
                $flat[] = $value;
            }
        }

        return $flat;
    }

    private function addVouchers(AuditFindings $findings, QueryBuilder $query, string $voucherColumn, string $problem): void
    {
        [$count, $ids] = AuditContext::distinctIds($query, $voucherColumn);

        if ($count === 0) {
            return;
        }

        $labels = $this->context->voucherLabels($ids);
        $findings->add($count, array_map(fn (int $id): string => "{$labels[$id]} {$problem}", $ids));
    }
}
