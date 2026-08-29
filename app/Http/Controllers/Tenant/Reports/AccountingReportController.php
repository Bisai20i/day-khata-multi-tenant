<?php

namespace App\Http\Controllers\Tenant\Reports;

use App\Enums\FiscalYearStatus;
use App\Enums\VoucherType;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AccountHead;
use App\Models\FiscalYear;
use App\Models\JournalVoucher;
use App\Models\JournalVoucherLine;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Trial Balance / Income Statement / Balance Sheet - pure read-only
 * aggregations over JournalVoucherLine, no new tables.
 *
 * Trial Balance and Income Statement EXCLUDE ClosingEntry-type voucher
 * lines: FiscalYear::close() posts a ClosingEntry voucher INTO the closing
 * year itself, zeroing every profit-and-loss account within that year's own
 * line set. Summing everything (including the ClosingEntry) would make a
 * closed year's trial balance/income statement always show zero P&L
 * activity, which defeats the point of either report. Balance Sheet does
 * NOT exclude anything - ClosingEntry only retargets P&L accounts (not
 * shown on a balance sheet) plus "Profit & Loss" itself (a Capital account,
 * correctly needs the closing entry's effect folded in).
 */
class AccountingReportController extends Controller
{
    public function trialBalance(Request $request): Response
    {
        $fiscalYearId = $this->resolveFiscalYearId($request);

        $rows = collect();
        $totalDebit = 0.0;
        $totalCredit = 0.0;

        if ($fiscalYearId !== null) {
            $rows = $this->accountRows(null, $fiscalYearId, excludeClosingEntry: true);
            $totalDebit = round((float) $rows->sum('debit'), 2);
            $totalCredit = round((float) $rows->sum('credit'), 2);
        }

        return Inertia::render('Tenant/Reports/TrialBalance', [
            'fiscalYears' => FiscalYear::query()->orderByDesc('start_date')->get(['id', 'name', 'status']),
            'fiscalYearId' => $fiscalYearId,
            'heads' => $this->buildHierarchy($rows),
            'totalDebit' => $totalDebit,
            'totalCredit' => $totalCredit,
        ]);
    }

    public function incomeStatement(Request $request): Response
    {
        $fiscalYearId = $this->resolveFiscalYearId($request);

        $income = [];
        $expenses = [];
        $totalIncome = 0.0;
        $totalExpenses = 0.0;

        if ($fiscalYearId !== null) {
            $income = $this->headBalances('Income', $fiscalYearId, creditNormal: true, excludeClosingEntry: true);
            $expenses = $this->headBalances('Expenses', $fiscalYearId, creditNormal: false, excludeClosingEntry: true);
            $totalIncome = round((float) collect($income)->sum('amount'), 2);
            $totalExpenses = round((float) collect($expenses)->sum('amount'), 2);
        }

        return Inertia::render('Tenant/Reports/IncomeStatement', [
            'fiscalYears' => FiscalYear::query()->orderByDesc('start_date')->get(['id', 'name', 'status']),
            'fiscalYearId' => $fiscalYearId,
            'income' => $income,
            'expenses' => $expenses,
            'totalIncome' => $totalIncome,
            'totalExpenses' => $totalExpenses,
            'netProfit' => round($totalIncome - $totalExpenses, 2),
        ]);
    }

    public function balanceSheet(Request $request): Response
    {
        $fiscalYearId = $this->resolveFiscalYearId($request);

        $rows = collect();
        $totalAssets = 0.0;
        $totalLiabilitiesAndCapital = 0.0;
        $currentYearEarnings = 0.0;

        if ($fiscalYearId !== null) {
            $fiscalYear = FiscalYear::findOrFail($fiscalYearId);

            $rows = $this->accountRows(['Assets', 'Liabilities', 'Capital'], $fiscalYearId, excludeClosingEntry: false);

            $assetRows = $rows->filter(fn (array $row) => $row['headName'] === 'Assets');
            $otherRows = $rows->filter(fn (array $row) => $row['headName'] !== 'Assets');

            $totalAssets = round((float) $assetRows->sum('debit') - (float) $assetRows->sum('credit'), 2);
            $totalLiabilitiesAndCapital = round((float) $otherRows->sum('credit') - (float) $otherRows->sum('debit'), 2);

            // While the fiscal year is still open, this year's net
            // profit/loss hasn't been swept into "Profit & Loss" yet (that
            // only happens at FiscalYear::close()) - Assets vs.
            // Liabilities+Capital won't balance without accounting for it,
            // so show it as its own line rather than silently
            // under-reporting equity. Once closed, the real ClosingEntry
            // voucher already carries this into "Profit & Loss" (picked up
            // by $otherRows above), so this virtual line is skipped then.
            if ($fiscalYear->status === FiscalYearStatus::Open) {
                $income = $this->headBalances('Income', $fiscalYearId, creditNormal: true, excludeClosingEntry: true);
                $expenses = $this->headBalances('Expenses', $fiscalYearId, creditNormal: false, excludeClosingEntry: true);
                $currentYearEarnings = round(
                    (float) collect($income)->sum('amount') - (float) collect($expenses)->sum('amount'),
                    2
                );
                $totalLiabilitiesAndCapital = round($totalLiabilitiesAndCapital + $currentYearEarnings, 2);
            }
        }

        return Inertia::render('Tenant/Reports/BalanceSheet', [
            'fiscalYears' => FiscalYear::query()->orderByDesc('start_date')->get(['id', 'name', 'status']),
            'fiscalYearId' => $fiscalYearId,
            'heads' => $this->buildHierarchy($rows),
            'currentYearEarnings' => $currentYearEarnings,
            'totalAssets' => $totalAssets,
            'totalLiabilitiesAndCapital' => $totalLiabilitiesAndCapital,
        ]);
    }

    /**
     * A complete chronological diary of every voucher posted in the date
     * range, lines nested underneath - unlike Trial Balance/Income
     * Statement this is an audit trail, not a balance computation, so
     * ClosingEntry/OpeningBalance vouchers are NOT excluded.
     */
    public function dayBook(Request $request): Response
    {
        [$from, $to] = $this->resolveDateRange($request);

        $vouchers = JournalVoucher::query()
            ->with('lines.account:id,code,name')
            ->whereBetween('date', [$from, $to])
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        return Inertia::render('Tenant/Reports/DayBook', [
            'vouchers' => $vouchers->map(fn (JournalVoucher $voucher) => [
                'date' => $voucher->date->toDateString(),
                'voucherType' => $voucher->voucher_type->value,
                'voucherNumber' => $voucher->voucher_number,
                'narration' => $voucher->narration,
                'lines' => $voucher->lines->map(fn (JournalVoucherLine $line) => [
                    'accountCode' => $line->account->code,
                    'accountName' => $line->account->name,
                    'debit' => (float) $line->debit,
                    'credit' => (float) $line->credit,
                    'narration' => $line->narration,
                ])->values(),
            ])->values(),
            'totalDebit' => (float) $vouchers->flatMap->lines->sum('debit'),
            'totalCredit' => (float) $vouchers->flatMap->lines->sum('credit'),
            'from' => $from,
            'to' => $to,
        ]);
    }

    /**
     * Fixed to the single seeded Cash-In-Hand account (code AS1) - same
     * hardcoded-account-code convention this app already relies on for
     * INI20/LIA20/ASA23/EXE8/CA2 elsewhere.
     */
    public function cashBook(Request $request): Response
    {
        [$from, $to] = $this->resolveDateRange($request);
        $account = Account::where('code', 'AS1')->firstOrFail();

        return Inertia::render('Tenant/Reports/CashBook', array_merge(
            [
                'account' => $account->only(['id', 'code', 'name']),
                'from' => $from,
                'to' => $to,
            ],
            $this->accountBook($account, $from, $to),
        ));
    }

    /**
     * There is no "is this a bank account" flag anywhere on the Account
     * model (confirmed via grep) - `bank_account_id` on Sale/Purchase is
     * just a free choice of any Account the user makes at posting time.
     * Rather than invent new schema/UI to classify accounts as banks, this
     * report is a plain account picker (same pattern as
     * Accounts/Ledger.vue's fiscal-year picker) - the user chooses which
     * non-cash account to view as a "bank book."
     */
    public function bankBook(Request $request): Response
    {
        [$from, $to] = $this->resolveDateRange($request);

        $accounts = Account::where('code', '!=', 'AS1')->orderBy('name')->get(['id', 'code', 'name']);
        $accountId = $request->integer('account_id') ?: $accounts->first()?->id;
        $account = $accountId ? $accounts->firstWhere('id', $accountId) : null;

        return Inertia::render('Tenant/Reports/BankBook', array_merge(
            [
                'accounts' => $accounts,
                'accountId' => $accountId,
                'from' => $from,
                'to' => $to,
            ],
            $account
                ? $this->accountBook($account, $from, $to)
                : ['entries' => [], 'openingBalance' => 0.0, 'closingBalance' => 0.0],
        ));
    }

    /**
     * Defaults to the current open fiscal year's date range when no
     * explicit `from`/`to` query params are given, falling back to
     * month-to-date if no fiscal year exists yet. Identical logic to
     * SalesPurchaseReportController::resolveDateRange() - duplicated
     * rather than shared across controllers, matching this app's existing
     * per-controller-file convention (see mem.md gotcha #5).
     *
     * @return array{0: string, 1: string}
     */
    private function resolveDateRange(Request $request): array
    {
        $from = $request->string('from')->toString();
        $to = $request->string('to')->toString();

        if ($from !== '' && $to !== '') {
            return [$from, $to];
        }

        $fiscalYear = FiscalYear::query()->where('status', FiscalYearStatus::Open)->first();

        if ($fiscalYear) {
            return [$fiscalYear->start_date->toDateString(), $fiscalYear->end_date->toDateString()];
        }

        return [now()->startOfMonth()->toDateString(), now()->toDateString()];
    }

    /**
     * A single account's running book over a date range: an opening
     * balance carried from everything posted strictly before `$from`
     * (all-time cumulative, unlike the fiscal-year-boxed Accounts/Ledger),
     * then every line within the range with a running balance.
     *
     * @return array{entries: array<int, array{date: string, voucherType: string, voucherNumber: int, narration: ?string, debit: float, credit: float, balance: float}>, openingBalance: float, closingBalance: float}
     */
    private function accountBook(Account $account, string $from, string $to): array
    {
        $openingBalance = (float) (JournalVoucherLine::query()
            ->where('account_id', $account->id)
            ->whereHas('journalVoucher', fn ($query) => $query->where('date', '<', $from))
            ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as net')
            ->value('net') ?? 0);

        $lines = JournalVoucherLine::query()
            ->where('account_id', $account->id)
            ->whereHas('journalVoucher', fn ($query) => $query->whereBetween('date', [$from, $to]))
            ->with('journalVoucher')
            ->get()
            ->sortBy([['journalVoucher.date', 'asc'], ['id', 'asc']])
            ->values();

        $runningBalance = $openingBalance;

        $entries = $lines->map(function (JournalVoucherLine $line) use (&$runningBalance) {
            $runningBalance = round($runningBalance + (float) $line->debit - (float) $line->credit, 2);

            return [
                'date' => $line->journalVoucher->date->toDateString(),
                'voucherType' => $line->journalVoucher->voucher_type->value,
                'voucherNumber' => $line->journalVoucher->voucher_number,
                'narration' => $line->narration ?? $line->journalVoucher->narration,
                'debit' => (float) $line->debit,
                'credit' => (float) $line->credit,
                'balance' => $runningBalance,
            ];
        })->values()->all();

        return [
            'entries' => $entries,
            'openingBalance' => round($openingBalance, 2),
            'closingBalance' => round($runningBalance, 2),
        ];
    }

    private function resolveFiscalYearId(Request $request): ?int
    {
        return $request->integer('fiscal_year_id') ?: FiscalYear::query()->where('status', FiscalYearStatus::Open)->value('id');
    }

    /**
     * Flat list of {account, head, group, subgroup, debit, credit} rows,
     * one per account with nonzero net activity in the fiscal year,
     * optionally restricted to a set of head names.
     *
     * @param  array<int, string>|null  $headNames
     * @return Collection<int, array{account: Account, headName: string, groupName: string, subgroupName: ?string, debit: float, credit: float}>
     */
    private function accountRows(?array $headNames, int $fiscalYearId, bool $excludeClosingEntry): Collection
    {
        $accounts = Account::query()
            ->with(['group.accountHead', 'subgroup.accountGroup.accountHead'])
            ->when($headNames !== null, fn ($query) => $query
                ->whereHas('group.accountHead', fn ($q) => $q->whereIn('name', $headNames))
                ->orWhereHas('subgroup.accountGroup.accountHead', fn ($q) => $q->whereIn('name', $headNames)))
            ->get();

        $rows = collect();

        foreach ($accounts as $account) {
            $head = $account->group?->accountHead ?? $account->subgroup?->accountGroup?->accountHead;
            $group = $account->group ?? $account->subgroup?->accountGroup;

            if (! $head || ! $group) {
                continue;
            }

            [$debit, $credit] = $this->netDebitCredit($account->id, $fiscalYearId, $excludeClosingEntry);

            if ($debit === 0.0 && $credit === 0.0) {
                continue;
            }

            $rows->push([
                'account' => $account,
                'headName' => $head->name,
                'groupName' => $group->name,
                'subgroupName' => $account->subgroup?->name,
                'debit' => $debit,
                'credit' => $credit,
            ]);
        }

        return $rows;
    }

    /**
     * @return array<int, array{id: int, code: ?string, name: string, amount: float}>
     */
    private function headBalances(string $headName, int $fiscalYearId, bool $creditNormal, bool $excludeClosingEntry): array
    {
        $head = AccountHead::where('name', $headName)->first();

        if (! $head) {
            return [];
        }

        $accounts = Account::query()
            ->whereHas('group', fn ($q) => $q->where('account_head_id', $head->id))
            ->orWhereHas('subgroup.accountGroup', fn ($q) => $q->where('account_head_id', $head->id))
            ->get();

        $rows = [];

        foreach ($accounts as $account) {
            [$debit, $credit] = $this->netDebitCredit($account->id, $fiscalYearId, $excludeClosingEntry);
            $amount = $creditNormal ? round($credit - $debit, 2) : round($debit - $credit, 2);

            if ($amount === 0.0) {
                continue;
            }

            $rows[] = [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'amount' => $amount,
            ];
        }

        return $rows;
    }

    /**
     * @return array{0: float, 1: float} [debit, credit] - only one is ever
     *                                   nonzero: net>0 shows as a debit balance, net<0 as a credit balance.
     */
    private function netDebitCredit(int $accountId, int $fiscalYearId, bool $excludeClosingEntry): array
    {
        $sums = JournalVoucherLine::query()
            ->where('account_id', $accountId)
            ->whereHas('journalVoucher', function ($query) use ($fiscalYearId, $excludeClosingEntry) {
                $query->where('fiscal_year_id', $fiscalYearId);

                if ($excludeClosingEntry) {
                    $query->where('voucher_type', '!=', VoucherType::ClosingEntry->value);
                }
            })
            ->selectRaw('COALESCE(SUM(debit), 0) as debit, COALESCE(SUM(credit), 0) as credit')
            ->first();

        $net = round((float) $sums->debit - (float) $sums->credit, 2);

        return $net >= 0 ? [$net, 0.0] : [0.0, -$net];
    }

    /**
     * Nests flat account rows into head -> group -> (subgroup, optional) ->
     * accounts, for the hierarchical Trial Balance / Balance Sheet pages.
     *
     * @param  Collection<int, array{account: Account, headName: string, groupName: string, subgroupName: ?string, debit: float, credit: float}>  $rows
     * @return array<int, array{name: string, groups: array<int, array{name: string, accounts: array, subgroups: array}>}>
     */
    private function buildHierarchy(Collection $rows): array
    {
        $heads = [];

        foreach ($rows as $row) {
            $account = $row['account'];
            $accountRow = [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'debit' => $row['debit'],
                'credit' => $row['credit'],
            ];

            $heads[$row['headName']] ??= ['name' => $row['headName'], 'groups' => []];
            $heads[$row['headName']]['groups'][$row['groupName']] ??= ['name' => $row['groupName'], 'accounts' => [], 'subgroups' => []];

            if ($row['subgroupName'] !== null) {
                $heads[$row['headName']]['groups'][$row['groupName']]['subgroups'][$row['subgroupName']] ??= [
                    'name' => $row['subgroupName'],
                    'accounts' => [],
                ];
                $heads[$row['headName']]['groups'][$row['groupName']]['subgroups'][$row['subgroupName']]['accounts'][] = $accountRow;
            } else {
                $heads[$row['headName']]['groups'][$row['groupName']]['accounts'][] = $accountRow;
            }
        }

        return collect($heads)->values()->map(function (array $head) {
            $head['groups'] = collect($head['groups'])->values()->map(function (array $group) {
                $group['subgroups'] = collect($group['subgroups'])->values()->all();

                return $group;
            })->all();

            return $head;
        })->all();
    }
}
