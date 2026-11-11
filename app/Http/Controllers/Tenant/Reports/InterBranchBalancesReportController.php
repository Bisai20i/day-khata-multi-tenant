<?php

namespace App\Http\Controllers\Tenant\Reports;

use App\Enums\FiscalYearStatus;
use App\Enums\VoucherType;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Branch;
use App\Models\FiscalYear;
use App\Models\StockTransfer;
use App\Support\Branches\ActiveBranchSelection;
use App\Support\Branches\BranchAccounts;
use App\Support\Branches\BranchContext;
use App\Support\Branches\BranchFilter;
use App\Support\Branches\BranchReportTotals;
use App\Support\Branches\InterBranchBalances;
use App\Support\Branches\StockInTransit;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Inter-branch balances (plan 12, section 5.9, BR19): who owes whom between
 * branches, and whether the two sides of every pair agree.
 *
 * **Pairs.** Read from Inter-Branch Clearing (ASA25) lines grouped by
 * (branch, counterparty branch) up to `to` in the chosen fiscal year, the
 * year's opening lines included (InterBranchBalances). A pair's two sides are
 * each branch's own books: A's net debit toward B (B owes A) and B's net
 * debit toward A. Posted entries are always mirrored, so the two should
 * cancel; the one legitimate gap is a stock transfer in transit, whose
 * dispatch is in the sender's books and whose receipt is not yet in the
 * receiver's (StockInTransit::valueByBranchPair()). A gap that transit does
 * not explain is flagged as a reconciliation difference.
 *
 * **Unattributed.** Clearing balances carried forward by a year-end close
 * before BR19 have no counterparty and are listed per branch on their own
 * ("Unattributed (before branch tracking)"), never guessed into pairs.
 *
 * **Transfers and stock accounts.** Transfers between branches in transit at
 * `to` and those received in the window, and the window's Stock Transferred
 * to Branches (INI23) and Stock Received from Branches (EXE11) from the
 * ledger, each beside the same figure rebuilt from the transfers themselves.
 * Since BR25 a transfer may also carry raw material and finished goods:
 * clearing, the pair table, the company check and the transfer list keep
 * the whole value (StockInTransit::ALL), while INI23 / EXE11 only carry the
 * periodic share (StockInTransit::PERIODIC); the perpetual share, which
 * moves through AS12 / AS14, gets its own row when there is any.
 *
 * **Scope.** Without a choice the report covers every branch the user may
 * see (like the branch comparison). The choice selects which pairs and
 * transfers are listed (those with an end in it); for an unrestricted user
 * both sides of a listed pair are read, so the mirror check works for a
 * single branch too. A user limited to some branches reads only their own
 * branches' lines (BranchAccessScope): the other side of a pair outside
 * their access is shown as not visible rather than read, and they get no
 * company-wide check. Not multi-branch: nothing to show, `branchReport` null.
 *
 * Every amount is an exact decimal string; the page only formats.
 */
class InterBranchBalancesReportController extends Controller
{
    private const INPUT_RULES = [
        'fiscal_year_id' => ['nullable', 'integer', 'exists:fiscal_years,id'],
        'from' => ['nullable', 'date'],
        'to' => ['nullable', 'date', 'after_or_equal:from'],
    ];

    public function index(Request $request): Response
    {
        return Inertia::render('Tenant/Reports/InterBranchBalances', $this->report($request));
    }

    /**
     * @return array<string, mixed>
     */
    private function report(Request $request): array
    {
        $request->validate(self::INPUT_RULES);

        if (! $request->has('branches') && ! $request->has('branch')) {
            $request->merge(['branches' => [ActiveBranchSelection::ALL]]);
        }

        $filter = BranchFilter::fromRequest($request);

        $report = [
            'fiscalYears' => [],
            'fiscalYearId' => null,
            'from' => null,
            'to' => null,
            'branchReport' => null,
            'missingAccounts' => false,
            'pairs' => [],
            'unattributed' => [],
            'positions' => [],
            'companyCheck' => null,
            'transfers' => [],
            'stockAccounts' => null,
        ];

        if (! $filter->isActive()) {
            return $report;
        }

        $report['branchReport'] = $filter->payload();
        $report['fiscalYears'] = $this->fiscalYearOptions();
        $fiscalYear = $this->resolveFiscalYear($request);

        if ($fiscalYear === null) {
            return $report;
        }

        [$from, $to] = $this->window($request, $fiscalYear);
        $report = [...$report, 'fiscalYearId' => $fiscalYear->id, 'from' => $from, 'to' => $to];

        $clearingId = InterBranchBalances::clearingAccountId();

        if ($clearingId === null) {
            return [...$report, 'missingAccounts' => true];
        }

        $allowed = BranchContext::allowedBranchIds();
        $selected = $filter->effectiveIds();
        $names = $this->branchNames();

        $nets = InterBranchBalances::netsByPair(
            $clearingId,
            fn (Builder $vouchers) => $vouchers->where('fiscal_year_id', $fiscalYear->id)->whereDate('date', '<=', $to),
        );
        $transit = StockInTransit::valueByBranchPair($to);

        return [
            ...$report,
            'pairs' => $this->pairs($nets, $transit, $selected, $allowed, $names),
            'unattributed' => $this->unattributed($nets, $selected, $names),
            'positions' => $this->positions($nets, $selected, $names),
            'companyCheck' => $allowed === null && $selected === null ? $this->companyCheck($nets, $transit) : null,
            'transfers' => $this->transfers($from, $to, $selected, $names),
            'stockAccounts' => $this->stockAccounts($fiscalYear, $from, $to, $filter, $allowed === null && $selected === null),
        ];
    }

    /**
     * One row per branch pair with a balance or goods in transit, listed when
     * either end is in the selection.
     *
     * @param  array<int, array<int, Money>>  $nets
     * @param  array<int, array<int, Money>>  $transit
     * @param  list<int>|null  $selected
     * @param  list<int>|null  $allowed
     * @param  array<int, array{name: string, order: int}>  $names
     * @return list<array<string, mixed>>
     */
    private function pairs(array $nets, array $transit, ?array $selected, ?array $allowed, array $names): array
    {
        $pairs = [];

        foreach ([$nets, $transit] as $source) {
            foreach ($source as $branchId => $byCounterparty) {
                foreach (array_keys($byCounterparty) as $counterpartyId) {
                    if ($counterpartyId === InterBranchBalances::UNATTRIBUTED || $counterpartyId === $branchId) {
                        continue;
                    }

                    [$first, $second] = $this->ordered($branchId, $counterpartyId, $names);
                    $pairs["{$first}-{$second}"] = [$first, $second];
                }
            }
        }

        uasort($pairs, fn (array $left, array $right): int => [$this->order($left[0], $names), $this->order($left[1], $names)]
            <=> [$this->order($right[0], $names), $this->order($right[1], $names)]);

        $visible = fn (int $branchId): bool => $allowed === null || in_array($branchId, $allowed, true);
        $rows = [];

        foreach ($pairs as $key => [$first, $second]) {
            if ($selected !== null && ! in_array($first, $selected, true) && ! in_array($second, $selected, true)) {
                continue;
            }

            $firstView = $visible($first) ? ($nets[$first][$second] ?? Money::zero()) : null;
            $secondView = $visible($second) ? ($nets[$second][$first] ?? Money::zero()) : null;
            $inTransit = ($transit[$first][$second] ?? Money::zero())->plus($transit[$second][$first] ?? Money::zero());

            if (($firstView === null || $firstView->isZero()) && ($secondView === null || $secondView->isZero()) && $inTransit->isZero()) {
                continue;
            }

            $difference = $firstView !== null && $secondView !== null ? $firstView->plus($secondView) : null;
            $unexplained = $difference?->minus($inTransit);

            $rows[] = [
                'key' => $key,
                'first' => ['id' => $first, 'name' => $this->name($first, $names)],
                'second' => ['id' => $second, 'name' => $this->name($second, $names)],
                'firstView' => $this->side($firstView, $first, $second, $names),
                'secondView' => $this->side($secondView, $second, $first, $names),
                'inTransit' => $inTransit->toString(),
                'difference' => $difference?->toString(),
                'unexplained' => $unexplained?->toString(),
                'status' => match (true) {
                    $difference === null => 'one_side',
                    ! $unexplained->isZero() => 'mismatch',
                    ! $difference->isZero() => 'in_transit',
                    default => 'matched',
                },
            ];
        }

        return $rows;
    }

    /**
     * One side of a pair in words: who owes whom and how much, by $ownerId's
     * own books; null when the user may not see that branch's books.
     *
     * @param  array<int, array{name: string, order: int}>  $names
     * @return array{amount: string, debtor: string|null, creditor: string|null}|null
     */
    private function side(?Money $net, int $ownerId, int $otherId, array $names): ?array
    {
        if ($net === null) {
            return null;
        }

        return [
            'amount' => $net->abs()->toString(),
            // A debit balance toward the other branch: the other branch owes.
            'debtor' => $net->isZero() ? null : $this->name($net->isPositive() ? $otherId : $ownerId, $names),
            'creditor' => $net->isZero() ? null : $this->name($net->isPositive() ? $ownerId : $otherId, $names),
        ];
    }

    /**
     * @param  array<int, array<int, Money>>  $nets
     * @param  list<int>|null  $selected
     * @param  array<int, array{name: string, order: int}>  $names
     * @return list<array{branchId: int, branchName: string, amount: string, direction: string}>
     */
    private function unattributed(array $nets, ?array $selected, array $names): array
    {
        $rows = [];

        foreach ($this->branchesInOrder(array_keys($nets), $names) as $branchId) {
            $net = $nets[$branchId][InterBranchBalances::UNATTRIBUTED] ?? null;

            if ($net === null || ($selected !== null && ! in_array($branchId, $selected, true))) {
                continue;
            }

            $rows[] = [
                'branchId' => $branchId,
                'branchName' => $this->name($branchId, $names),
                'amount' => $net->abs()->toString(),
                'direction' => $net->isPositive() ? 'receivable' : 'payable',
            ];
        }

        return $rows;
    }

    /**
     * Each selected branch's whole clearing balance (all counterparties and
     * the unattributed bucket): what the other branches owe it, net.
     *
     * @param  array<int, array<int, Money>>  $nets
     * @param  list<int>|null  $selected
     * @param  array<int, array{name: string, order: int}>  $names
     * @return list<array{branchId: int, branchName: string, amount: string, direction: string}>
     */
    private function positions(array $nets, ?array $selected, array $names): array
    {
        $rows = [];

        foreach ($this->branchesInOrder(array_keys($nets), $names) as $branchId) {
            if ($selected !== null && ! in_array($branchId, $selected, true)) {
                continue;
            }

            $net = Money::sum(array_values($nets[$branchId]));

            $rows[] = [
                'branchId' => $branchId,
                'branchName' => $this->name($branchId, $names),
                'amount' => $net->abs()->toString(),
                'direction' => match (true) {
                    $net->isZero() => 'settled',
                    $net->isPositive() => 'receivable',
                    default => 'payable',
                },
            ];
        }

        return $rows;
    }

    /**
     * Company-wide only: the clearing account across every branch, which
     * nets to zero (invariant I3) except for goods in transit.
     *
     * @param  array<int, array<int, Money>>  $nets
     * @param  array<int, array<int, Money>>  $transit
     * @return array{total: string, inTransit: string, unexplained: string}
     */
    private function companyCheck(array $nets, array $transit): array
    {
        $total = Money::sum($this->flatten($nets));
        $inTransit = Money::sum($this->flatten($transit));

        return [
            'total' => $total->toString(),
            'inTransit' => $inTransit->toString(),
            'unexplained' => $total->minus($inTransit)->toString(),
        ];
    }

    /**
     * Transfers between branches with an end in the selection: in transit at
     * `to`, then those received inside the window, newest first.
     *
     * @param  list<int>|null  $selected
     * @param  array<int, array{name: string, order: int}>  $names
     * @return list<array<string, mixed>>
     */
    private function transfers(string $from, string $to, ?array $selected, array $names): array
    {
        $inSelection = fn (Builder $query) => $query->when($selected !== null, fn (Builder $ends) => $ends->where(fn (Builder $either) => $either
            ->whereIn('stock_transfers.from_branch_id', $selected)
            ->orWhereIn('stock_transfers.to_branch_id', $selected)));

        $inTransit = StockInTransit::transfers($to, asBooked: true)->tap($inSelection)->orderByDesc('date')->orderByDesc('id')->get();

        $received = StockTransfer::query()
            ->whereColumn('from_branch_id', '!=', 'to_branch_id')
            ->where('status', StockTransfer::STATUS_RECEIVED)
            ->whereDate('received_on', '>=', $from)
            ->whereDate('received_on', '<=', $to)
            ->tap($inSelection)
            ->whereKeyNot($inTransit->modelKeys())
            ->orderByDesc('received_on')
            ->orderByDesc('id')
            ->get();

        $row = fn (StockTransfer $transfer, string $state): array => [
            'id' => $transfer->id,
            'date' => $transfer->date->toDateString(),
            'receivedOn' => $transfer->received_on?->toDateString(),
            'from' => $this->name((int) $transfer->from_branch_id, $names),
            'to' => $this->name((int) $transfer->to_branch_id, $names),
            'value' => Money::of($transfer->total_value)->toString(),
            'state' => $state,
            'currentStatus' => $transfer->status,
        ];

        return [
            ...$inTransit->map(fn (StockTransfer $transfer): array => $row($transfer, 'in_transit'))->all(),
            ...$received->map(fn (StockTransfer $transfer): array => $row($transfer, 'received'))->all(),
        ];
    }

    /**
     * Stock Transferred to Branches and Stock Received from Branches in the
     * window, from the ledger (year-end closing entries left out, they only
     * sweep the year's balance away) beside the same figure rebuilt from the
     * transfers: dispatched less cancelled for the sending side, received
     * for the receiving side. Both are for the selection only. For the whole
     * company, transferred less received is the change in goods in transit
     * over the window. Every records figure and the transit change are the
     * periodic share only (BR25), the part these two accounts carry.
     *
     * @return array<string, mixed>
     */
    private function stockAccounts(FiscalYear $fiscalYear, string $from, string $to, BranchFilter $filter, bool $companyWide): array
    {
        $accountIds = Account::query()
            ->whereIn('code', [BranchAccounts::STOCK_TRANSFERRED_CODE, BranchAccounts::STOCK_RECEIVED_CODE])
            ->pluck('id', 'code');

        $nets = $accountIds->isEmpty() ? [] : BranchReportTotals::netByAccountAndBranch(
            fn (Builder $vouchers) => $vouchers->where('fiscal_year_id', $fiscalYear->id)
                ->where('voucher_type', '!=', VoucherType::ClosingEntry->value)
                ->whereDate('date', '>=', $from)
                ->whereDate('date', '<=', $to),
            $filter->lineBranchIds(),
            $accountIds->values()->map(fn (mixed $id): int => (int) $id)->all(),
        );

        $ledgerNet = fn (string $code): Money => $accountIds->has($code)
            ? Money::sum(array_values($nets[(int) $accountIds[$code]] ?? []))
            : Money::zero();

        $selected = $filter->effectiveIds();
        $periodic = StockInTransit::PERIODIC;
        $transferredLedger = $ledgerNet(BranchAccounts::STOCK_TRANSFERRED_CODE)->negated();
        $receivedLedger = $ledgerNet(BranchAccounts::STOCK_RECEIVED_CODE);
        $transferredRecords = $this->dispatchedValue($from, $to, $selected, $periodic)
            ->minus($this->cancelledValue($from, $to, $selected, $periodic));
        $receivedRecords = $this->receivedValue($from, $to, $selected, $periodic);

        $netLedger = $transferredLedger->minus($receivedLedger);
        $expected = null;

        if ($companyWide) {
            $dayBefore = CarbonImmutable::parse($from)->subDay()->toDateString();
            $expected = $this->transitTotal($to, $periodic)->minus($this->transitTotal($dayBefore, $periodic));
        }

        return [
            'transferred' => $this->comparison($transferredLedger, $transferredRecords),
            'received' => $this->comparison($receivedLedger, $receivedRecords),
            'net' => $netLedger->toString(),
            'expectedNet' => $expected?->toString(),
            'unexplained' => $expected === null ? null : $netLedger->minus($expected)->toString(),
            'perpetual' => $this->perpetual($from, $to, $selected),
        ];
    }

    /**
     * Raw material and finished goods sent between branches (BR25), which
     * travel at their AS12 / AS14 cost and so are in neither INI23 nor
     * EXE11 above: the perpetual value in transit at `to` on transfers with
     * an end in the selection (as booked, like the transfer list), and the
     * window's perpetual dispatches (less cancellations) and receipts. Null
     * when none of these is non-zero, so the page is unchanged for a
     * company that never sends them (and before the BR25 migration).
     *
     * @param  list<int>|null  $selected
     * @return array{inTransit: string, dispatched: string, received: string}|null
     */
    private function perpetual(string $from, string $to, ?array $selected): ?array
    {
        if (! StockInTransit::hasPerpetualValue()) {
            return null;
        }

        $basis = StockInTransit::PERPETUAL;
        $inTransit = Money::zero();

        foreach (StockInTransit::valueByBranchPair($to, $basis) as $fromId => $byReceiver) {
            foreach ($byReceiver as $toId => $value) {
                if ($selected === null || in_array($fromId, $selected, true) || in_array($toId, $selected, true)) {
                    $inTransit = $inTransit->plus($value);
                }
            }
        }

        $dispatched = $this->dispatchedValue($from, $to, $selected, $basis);
        $cancelled = $this->cancelledValue($from, $to, $selected, $basis);
        $received = $this->receivedValue($from, $to, $selected, $basis);

        if ($inTransit->isZero() && $dispatched->isZero() && $cancelled->isZero() && $received->isZero()) {
            return null;
        }

        return [
            'inTransit' => $inTransit->toString(),
            'dispatched' => $dispatched->minus($cancelled)->toString(),
            'received' => $received->toString(),
        ];
    }

    /**
     * @return array{ledger: string, records: string, difference: string}
     */
    private function comparison(Money $ledger, Money $records): array
    {
        return [
            'ledger' => $ledger->toString(),
            'records' => $records->toString(),
            'difference' => $ledger->minus($records)->toString(),
        ];
    }

    /**
     * @param  list<int>|null  $selected
     * @param  string  $basis  a StockInTransit value basis
     */
    private function dispatchedValue(string $from, string $to, ?array $selected, string $basis): Money
    {
        return $this->sumValue($this->crossBranch()
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->when($selected !== null, fn (Builder $query) => $query->whereIn('from_branch_id', $selected)), $basis);
    }

    /**
     * Transfers whose dispatch was reversed (cancelled in transit) inside
     * the window, by the reversal voucher's date.
     *
     * @param  list<int>|null  $selected
     * @param  string  $basis  a StockInTransit value basis
     */
    private function cancelledValue(string $from, string $to, ?array $selected, string $basis): Money
    {
        return $this->sumValue($this->crossBranch()
            ->where('status', StockTransfer::STATUS_CANCELLED)
            ->whereExists(fn (QueryBuilder $reversal) => $reversal
                ->selectRaw('1')
                ->from('journal_vouchers')
                ->whereColumn('journal_vouchers.id', 'stock_transfers.reversal_journal_voucher_id')
                ->whereDate('journal_vouchers.date', '>=', $from)
                ->whereDate('journal_vouchers.date', '<=', $to))
            ->when($selected !== null, fn (Builder $query) => $query->whereIn('from_branch_id', $selected)), $basis);
    }

    /**
     * @param  list<int>|null  $selected
     * @param  string  $basis  a StockInTransit value basis
     */
    private function receivedValue(string $from, string $to, ?array $selected, string $basis): Money
    {
        return $this->sumValue($this->crossBranch()
            ->where('status', StockTransfer::STATUS_RECEIVED)
            ->whereDate('received_on', '>=', $from)
            ->whereDate('received_on', '<=', $to)
            ->when($selected !== null, fn (Builder $query) => $query->whereIn('to_branch_id', $selected)), $basis);
    }

    /**
     * Company-wide goods in transit at $asOf on a StockInTransit basis.
     */
    private function transitTotal(string $asOf, string $basis): Money
    {
        return StockInTransit::totalValue($asOf, $basis);
    }

    /**
     * Every Money of a branch => branch => Money map, in one list.
     *
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

    /**
     * @return Builder<StockTransfer>
     */
    private function crossBranch(): Builder
    {
        return StockTransfer::query()->whereColumn('from_branch_id', '!=', 'to_branch_id');
    }

    /**
     * The transfers' exact value on $basis: StockInTransit::ALL is
     * SUM(total_value), PERIODIC the trading share INI23 / EXE11 carry
     * (total less perpetual_value), PERPETUAL the AS12 / AS14 share. Summed
     * as scaled integers (the SQLite REAL-affinity reason
     * Item::currentStockByItem() gives) and subtracted as whole paisa.
     *
     * @param  Builder<StockTransfer>  $query
     */
    private function sumValue(Builder $query, string $basis): Money
    {
        $row = $query->selectRaw(StockInTransit::valueSums())->toBase()->first();

        return StockInTransit::valueOf($row, $basis);
    }

    /**
     * Every branch's name and display position (sort order, then id), one
     * small query. Only names of branches that appear in the user's own
     * visible lines or transfers are ever put in the payload.
     *
     * @return array<int, array{name: string, order: int}>
     */
    private function branchNames(): array
    {
        $names = [];
        $position = 0;

        foreach (Branch::query()->orderBy('sort_order')->orderBy('id')->get(['id', 'name']) as $branch) {
            $names[(int) $branch->id] = ['name' => (string) $branch->name, 'order' => $position++];
        }

        return $names;
    }

    /**
     * @param  array<int, array{name: string, order: int}>  $names
     */
    private function name(int $branchId, array $names): string
    {
        return $names[$branchId]['name'] ?? "#{$branchId}";
    }

    /**
     * @param  array<int, array{name: string, order: int}>  $names
     */
    private function order(int $branchId, array $names): int
    {
        return $names[$branchId]['order'] ?? PHP_INT_MAX;
    }

    /**
     * @param  array<int, array{name: string, order: int}>  $names
     * @return array{0: int, 1: int}
     */
    private function ordered(int $left, int $right, array $names): array
    {
        return [$this->order($left, $names), $left] <= [$this->order($right, $names), $right] ? [$left, $right] : [$right, $left];
    }

    /**
     * @param  list<int>  $branchIds
     * @param  array<int, array{name: string, order: int}>  $names
     * @return list<int>
     */
    private function branchesInOrder(array $branchIds, array $names): array
    {
        usort($branchIds, fn (int $left, int $right): int => [$this->order($left, $names), $left] <=> [$this->order($right, $names), $right]);

        return $branchIds;
    }

    /**
     * @return list<array{id: int, name: string, status: string, startDate: string, endDate: string}>
     */
    private function fiscalYearOptions(): array
    {
        return FiscalYear::query()
            ->orderByDesc('start_date')
            ->get(['id', 'name', 'status', 'start_date', 'end_date'])
            ->map(fn (FiscalYear $fiscalYear): array => [
                'id' => $fiscalYear->id,
                'name' => $fiscalYear->name,
                'status' => $fiscalYear->status->value,
                'startDate' => $fiscalYear->start_date->toDateString(),
                'endDate' => $fiscalYear->end_date->toDateString(),
            ])
            ->all();
    }

    /**
     * The requested year, else the open one, else the latest (the balance
     * reports' rule, AccountingReportController::resolveFiscalYear()).
     */
    private function resolveFiscalYear(Request $request): ?FiscalYear
    {
        $requested = $request->integer('fiscal_year_id');

        if ($requested) {
            return FiscalYear::findOrFail($requested);
        }

        return FiscalYear::query()->where('status', FiscalYearStatus::Open)->first()
            ?? FiscalYear::query()->orderByDesc('start_date')->first();
    }

    /**
     * The window, clamped inside the fiscal year like the accounting
     * reports. `to` (the as-of date of the balances) defaults to today when
     * today falls in the year, else the year's last day; `from` (the start
     * of the stock-account and received-transfer window) to its first day.
     *
     * @return array{0: string, 1: string}
     */
    private function window(Request $request, FiscalYear $fiscalYear): array
    {
        $start = $fiscalYear->start_date->toDateString();
        $end = $fiscalYear->end_date->toDateString();
        $today = CarbonImmutable::now('Asia/Kathmandu')->toDateString();

        $from = $request->string('from')->toString();
        $to = $request->string('to')->toString();

        $from = $from !== '' ? max($from, $start) : $start;
        $to = $to !== '' ? min($to, $end) : ($today >= $start && $today <= $end ? $today : $end);

        return $from > $to ? [$start, $end] : [$from, $to];
    }
}
