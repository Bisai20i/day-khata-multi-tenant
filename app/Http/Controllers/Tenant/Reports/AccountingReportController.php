<?php

namespace App\Http\Controllers\Tenant\Reports;

use App\Enums\FiscalYearStatus;
use App\Enums\VoucherType;
use App\Exports\AccountBookExport;
use App\Exports\BalanceSheetExport;
use App\Exports\CancelledDocumentsExport;
use App\Exports\DayBookExport;
use App\Exports\IncomeStatementExport;
use App\Exports\TrialBalanceExport;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AccountHead;
use App\Models\CapitalPurchase;
use App\Models\CapitalSale;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\JournalVoucher;
use App\Models\JournalVoucherLine;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Receipt;
use App\Models\Sale;
use App\Models\SalesReturn;
use App\Models\Supplier;
use App\Support\Branches\ActiveBranchSelection;
use App\Support\Branches\BranchContext;
use App\Support\Branches\BranchFilter;
use App\Support\Branches\BranchListFilter;
use App\Support\Branches\BranchReportTotals;
use App\Support\Inventory\StockCosting;
use App\Support\Money\Money;
use App\Support\NepaliCalendar;
use Barryvdh\DomPDF\Facade\Pdf;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;

/**
 * Trial Balance / Income Statement / Balance Sheet / Day Book / Cash Book /
 * Bank Book - read-only aggregations over JournalVoucherLine, no new tables.
 *
 * Three rules run through every method here.
 *
 * **Everything is a Money string.** Props leave this controller as exact
 * 2-decimal strings, never floats: the pages render them with formatMoney()
 * and never do arithmetic of their own (CONTRACTS C1/C8). Sums run on scaled
 * integers inside SQL, because SQLite gives a decimal column REAL affinity
 * and a plain SUM() there reintroduces the float error the whole Phase 1
 * foundation exists to remove.
 *
 * **Every figure is boxed inside ONE fiscal year.** The Cash Book used to
 * carry its opening balance from every line ever posted, including the new
 * year's own Opening Balance voucher, which restates the same closing
 * balances the previous year's lines already produced - so cash that ended
 * FY1 at 600 opened FY2 at 1,200 (audit P0-18). An opening balance is now
 * "this year's Opening Balance voucher plus this year's lines before the
 * window", and nothing ever sums across a year boundary.
 *
 * **Inventory is periodic** (CONTRACTS C10). Stock documents post no
 * journal, so a year that has not been closed yet has no stock in its
 * ledger at all. The statements compute it instead, through StockCosting,
 * and label it as computed. A closed year shows the entries
 * FiscalYear::close() actually posted.
 *
 * **Branches** (plan 12, BR13). Every report takes BranchFilter
 * (`branch`, `branches[]`, `by_branch`). The branch predicate goes straight
 * onto journal_voucher_lines.branch_id, never into the whereHas, and is
 * added only when the filter narrows something the access scope does not
 * already narrow; a tenant that is not multi-branch, or a view of every
 * branch, runs exactly the queries it ran before. Sweep and opening-voucher
 * detection stays voucher based and company-wide; the predicate then
 * narrows the lines. Branch closing stock uses the year-end convention in
 * BranchReportTotals.
 */
class AccountingReportController extends Controller
{
    private const UNCLASSIFIED = 'Unclassified';

    /**
     * @var array<string, list<string>>
     */
    private const REPORT_INPUT_RULES = [
        'fiscal_year_id' => ['nullable', 'integer', 'exists:fiscal_years,id'],
        'from' => ['nullable', 'date'],
        'to' => ['nullable', 'date', 'after_or_equal:from'],
    ];

    public function ledger(Request $request): Response
    {
        $fiscalYear = $this->resolveFiscalYear($request);
        $filter = BranchFilter::fromRequest($request);
        $accountId = $request->integer('account_id') ?: null;
        $accounts = Account::orderBy('name')->get(['id', 'code', 'name', 'type', 'group']);

        $data = $this->emptyAccountBook();
        $account = null;
        [$from, $to] = [null, null];

        if ($fiscalYear !== null) {
            [$from, $to] = $this->resolveWindow($request, $fiscalYear);

            if ($accountId) {
                $account = Account::find($accountId);
                if ($account) {
                    $data = $this->accountBook($account, $fiscalYear, $from, $to, $filter->lineBranchIds());
                }
            }
        }

        return Inertia::render('Tenant/Reports/Ledger', [
            'fiscalYears' => $this->fiscalYearOptions(),
            'fiscalYearId' => $fiscalYear?->id,
            'accounts' => $accounts,
            'accountId' => $accountId,
            'account' => $account ? [
                'id' => $account->id,
                'name' => $account->name,
                'code' => $account->code,
                'type' => $account->type,
                'group' => $account->group,
            ] : null,
            'from' => $from,
            'to' => $to,
            'openingBalance' => $data['openingBalance'],
            'closingBalance' => $data['closingBalance'],
            'entries' => $data['entries'],
            'branchReport' => $filter->payload(),
        ]);
    }

    public function ledgerPdf(Request $request)
    {
        $account = Account::findOrFail($request->integer('account_id'));

        return $this->accountBookPdf($request, $account, 'Ledger');
    }

    public function ledgerExport(Request $request)
    {
        $account = Account::findOrFail($request->integer('account_id'));

        return $this->accountBookExport($request, $account, 'ledger');
    }

    public function trialBalance(Request $request): Response
    {
        $fiscalYear = $this->resolveFiscalYear($request);
        $filter = BranchFilter::fromRequest($request);
        $accountId = $request->integer('account_id') ?: null;

        $heads = [];
        $totals = $this->emptyTrialBalanceTotals();
        $unclassifiedCount = 0;
        [$from, $to] = [null, null];
        $branchReport = $filter->payload(supportsByBranch: true);

        if ($fiscalYear !== null) {
            $data = $this->trialBalanceData($request, $fiscalYear, $filter, $accountId, $filter->byBranch());

            [$from, $to] = [$data['from'], $data['to']];
            $heads = $data['heads'];
            $totals = $data['totals'];
            $unclassifiedCount = $data['unclassifiedCount'];
            $branchReport = $this->withBranchColumns($branchReport, $data);
        }

        return Inertia::render('Tenant/Reports/TrialBalance', array_merge([
            'branchReport' => $branchReport,
            // Accounts with no head or group, shown under "Unclassified"; the
            // page warns when there are any (flags G-08).
            'unclassifiedCount' => $unclassifiedCount,
            'fiscalYears' => $this->fiscalYearOptions(),
            'fiscalYearId' => $fiscalYear?->id,
            // Legacy's `?accno=` single-ledger filter (audit T15-5): narrows
            // the whole report to one account without a separate endpoint.
            'accounts' => Account::orderBy('name')->get(['id', 'code', 'name']),
            'accountId' => $accountId,
            'from' => $from,
            'to' => $to,
            'heads' => $heads,
        ], $totals));
    }

    public function incomeStatement(Request $request): Response
    {
        $fiscalYear = $this->resolveFiscalYear($request);
        $filter = BranchFilter::fromRequest($request);

        $data = [
            'from' => null,
            'to' => null,
            'income' => [],
            'expenses' => [],
            'stock' => ['opening' => '0.00', 'closing' => '0.00', 'posted' => false, 'asOf' => null],
            'totalIncome' => '0.00',
            'totalExpenses' => '0.00',
            'grossProfit' => '0.00',
            'netProfit' => '0.00',
        ];
        $branchReport = $filter->payload(supportsByBranch: true, isStatement: true);

        if ($fiscalYear !== null) {
            $data = $this->incomeStatementData($request, $fiscalYear, $filter, $filter->byBranch());
            $branchReport = $this->withBranchColumns($branchReport, $data);
        }

        return Inertia::render('Tenant/Reports/IncomeStatement', [
            'fiscalYears' => $this->fiscalYearOptions(),
            'fiscalYearId' => $fiscalYear?->id,
            'from' => $data['from'],
            'to' => $data['to'],
            'income' => $data['income'],
            'expenses' => $data['expenses'],
            'stock' => $data['stock'],
            'totalIncome' => $data['totalIncome'],
            'totalExpenses' => $data['totalExpenses'],
            'grossProfit' => $data['grossProfit'],
            'netProfit' => $data['netProfit'],
            'branchReport' => $branchReport,
        ]);
    }

    public function balanceSheet(Request $request): Response
    {
        $fiscalYear = $this->resolveFiscalYear($request);
        $filter = BranchFilter::fromRequest($request);

        $data = [
            'from' => null,
            'to' => null,
            'heads' => [],
            'stock' => ['opening' => '0.00', 'closing' => '0.00', 'posted' => false, 'asOf' => null],
            'currentYearEarnings' => '0.00',
            'totalAssets' => '0.00',
            'totalLiabilitiesAndCapital' => '0.00',
            'balanceWarning' => null,
            'unclassifiedCount' => 0,
        ];
        $branchReport = $filter->payload(supportsByBranch: true, isStatement: true);

        if ($fiscalYear !== null) {
            $data = $this->balanceSheetData($request, $fiscalYear, $filter, $filter->byBranch());
            $branchReport = $this->withBranchColumns($branchReport, $data);
        }

        return Inertia::render('Tenant/Reports/BalanceSheet', [
            'unclassifiedCount' => $data['unclassifiedCount'],
            'fiscalYears' => $this->fiscalYearOptions(),
            'fiscalYearId' => $fiscalYear?->id,
            'from' => $data['from'],
            'to' => $data['to'],
            'heads' => $data['heads'],
            'stock' => $data['stock'],
            'currentYearEarnings' => $data['currentYearEarnings'],
            'totalAssets' => $data['totalAssets'],
            'totalLiabilitiesAndCapital' => $data['totalLiabilitiesAndCapital'],
            'balanceWarning' => $data['balanceWarning'],
            'branchReport' => $branchReport,
        ]);
    }

    /**
     * A complete chronological diary of every voucher posted inside the
     * chosen fiscal year's window, lines nested underneath - unlike Trial
     * Balance/Income Statement this is an audit trail, not a balance
     * computation, so ClosingEntry/OpeningBalance vouchers are NOT excluded.
     */
    public function dayBook(Request $request): Response
    {
        $fiscalYear = $this->resolveFiscalYear($request);
        $filter = BranchFilter::fromRequest($request);
        $voucherType = $request->string('voucher_type')->toString() ?: null;

        $data = $fiscalYear === null
            ? ['from' => null, 'to' => null, 'vouchers' => [], 'totalDebit' => '0.00', 'totalCredit' => '0.00']
            : $this->dayBookData($request, $fiscalYear, $filter);

        return Inertia::render('Tenant/Reports/DayBook', [
            'fiscalYears' => $this->fiscalYearOptions(),
            'fiscalYearId' => $fiscalYear?->id,
            'from' => $data['from'],
            'to' => $data['to'],
            'voucherType' => $voucherType,
            'voucherTypeOptions' => $this->dayBookVoucherTypeOptions($request),
            'vouchers' => $data['vouchers'],
            'totalDebit' => $data['totalDebit'],
            'totalCredit' => $data['totalCredit'],
            'branchReport' => $filter->payload(),
        ]);
    }

    /**
     * The Day Book's voucher-type filter. A branch stock transfer can only be
     * posted between two active branches, so a company that is not
     * multi-branch (module off, or only Main) is not offered it (plan 12,
     * BR22 parity); every other type is listed exactly as before.
     *
     * @return list<string>
     */
    private function dayBookVoucherTypeOptions(Request $request): array
    {
        $multiBranch = ActiveBranchSelection::isMultiBranch($request);

        return array_values(array_map(
            fn (VoucherType $type) => $type->value,
            array_filter(
                VoucherType::cases(),
                fn (VoucherType $type) => $multiBranch || $type !== VoucherType::BranchStockTransfer,
            ),
        ));
    }

    /**
     * Fixed to the single seeded Cash-In-Hand account (code AS1) - same
     * hardcoded-account-code convention this app already relies on for
     * INI20/LIA20/ASA23/EXE8/CA2 elsewhere.
     */
    public function cashBook(Request $request): Response
    {
        $fiscalYear = $this->resolveFiscalYear($request);
        $filter = BranchFilter::fromRequest($request);
        $account = Account::where('code', 'AS1')->firstOrFail();

        [$from, $to] = $fiscalYear === null ? [null, null] : $this->resolveWindow($request, $fiscalYear);

        return Inertia::render('Tenant/Reports/CashBook', array_merge(
            [
                'fiscalYears' => $this->fiscalYearOptions(),
                'fiscalYearId' => $fiscalYear?->id,
                'account' => $account->only(['id', 'code', 'name']),
                'from' => $from,
                'to' => $to,
            ],
            $fiscalYear === null
                ? $this->emptyAccountBook()
                : $this->accountBook($account, $fiscalYear, $from, $to, $filter->lineBranchIds()),
            ['branchReport' => $filter->payload()],
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
        $fiscalYear = $this->resolveFiscalYear($request);
        $filter = BranchFilter::fromRequest($request);

        $accounts = Account::where('code', '!=', 'AS1')->orderBy('name')->get(['id', 'code', 'name']);
        $accountId = $request->integer('account_id') ?: $accounts->first()?->id;
        $account = $accountId ? $accounts->firstWhere('id', $accountId) : null;

        [$from, $to] = $fiscalYear === null ? [null, null] : $this->resolveWindow($request, $fiscalYear);

        return Inertia::render('Tenant/Reports/BankBook', array_merge(
            [
                'fiscalYears' => $this->fiscalYearOptions(),
                'fiscalYearId' => $fiscalYear?->id,
                'accounts' => $accounts,
                'accountId' => $accountId,
                'from' => $from,
                'to' => $to,
            ],
            $account && $fiscalYear
                ? $this->accountBook($account, $fiscalYear, $from, $to, $filter->lineBranchIds())
                : $this->emptyAccountBook(),
            ['branchReport' => $filter->payload()],
        ));
    }

    /**
     * PDF print of the Trial Balance (T14 task 2). Recomputes exactly what
     * trialBalance() shows on screen, from the same private helpers, so the
     * PDF can never disagree with the page it was printed from. Reports have
     * no PrintLog entry (C9's copy-numbering is for a single countable
     * document like an invoice or credit note; a "Trial Balance as of
     * today" has no such identity to count copies of).
     */
    public function trialBalancePdf(Request $request)
    {
        $fiscalYear = $this->resolveFiscalYear($request);
        abort_if($fiscalYear === null, 404, 'No fiscal year to report on.');

        $filter = BranchFilter::fromRequest($request);
        $accountId = $request->integer('account_id') ?: null;

        $data = $this->trialBalanceData($request, $fiscalYear, $filter, $accountId, false);
        $to = $data['to'];

        $pdf = Pdf::loadView('pdf.trial-balance', array_merge([
            'company' => CompanySetting::current(),
            'documentNumber' => "Trial Balance - {$fiscalYear->name}",
            'documentDate' => $to,
            'dateAd' => $to,
            'dateBs' => NepaliCalendar::formatBs($to),
            'fiscalYearName' => $fiscalYear->name,
            'heads' => $data['heads'],
            'branchFilterLabel' => $this->branchFilterLabel($filter),
        ], $data['totals']));

        return $pdf->stream("trial-balance-{$fiscalYear->name}.pdf");
    }

    public function trialBalanceExport(Request $request)
    {
        $fiscalYear = $this->resolveFiscalYear($request);
        abort_if($fiscalYear === null, 404, 'No fiscal year to report on.');

        $filter = BranchFilter::fromRequest($request);
        $accountId = $request->integer('account_id') ?: null;
        $data = $this->trialBalanceData($request, $fiscalYear, $filter, $accountId, false);

        return Excel::download(new TrialBalanceExport($data['heads'], $this->branchFilterLabel($filter)), "trial-balance-{$fiscalYear->name}.xlsx");
    }

    /**
     * Shared by trialBalance()'s page and its PDF/Excel siblings, so all
     * three read one computation.
     *
     * With $withBranchColumns each row also carries `by_branch`: the
     * account's CLOSING net (debit minus credit, signed) per branch, from one
     * grouped query over the same line set as the Opening and Period columns
     * together (this year's Opening Balance voucher plus every line dated up
     * to `to`, sweep left out). Per-branch totals give the closing debit and
     * credit sums and whether they agree, which by invariant I1 they do for
     * every branch whenever the company's do.
     *
     * @return array{from: string, to: string, heads: array, totals: array<string, string|bool>, unclassifiedCount: int, columns: list<array{id: int, name: string, code: string}>|null, branchTotals: array<int, array{debit: string, credit: string, inBalance: bool}>|null}
     */
    private function trialBalanceData(Request $request, FiscalYear $fiscalYear, BranchFilter $filter, ?int $accountId, bool $withBranchColumns): array
    {
        [$from, $to] = $this->resolveWindow($request, $fiscalYear);
        $lineIds = $filter->lineBranchIds();

        $excluded = $this->sweepVoucherIds($fiscalYear);
        $opening = $this->openingBalancesByAccount($fiscalYear, $excluded, $from, $lineIds);
        $period = $this->periodTotalsByAccount($fiscalYear, [...$excluded, ...$this->openingVoucherIds($fiscalYear)], $from, $to, $lineIds);
        $rows = $this->trialBalanceRows($opening, $period, $accountId);

        $columns = null;
        $branchTotals = null;

        if ($withBranchColumns) {
            $nets = BranchReportTotals::netByAccountAndBranch(
                fn (Builder $query) => $query->where('fiscal_year_id', $fiscalYear->id)
                    ->when($excluded !== [], fn (Builder $q) => $q->whereNotIn('id', $excluded))
                    ->where(fn (Builder $q) => $q
                        ->where('voucher_type', VoucherType::OpeningBalance->value)
                        ->orWhereDate('date', '<=', $to)),
                $lineIds,
                $accountId === null ? null : [$accountId],
            );
            $columns = $filter->columns(BranchReportTotals::branchIdsIn($nets));
            $rows = $rows->map(fn (array $row): array => [
                ...$row,
                'by_branch' => $this->amountsByBranch($nets[$row['account']->id] ?? [], $columns),
            ]);

            $branchTotals = [];

            foreach ($columns as $column) {
                $branchNets = $rows->map(fn (array $row): Money => $row['by_branch'][$column['id']]);
                $debit = Money::sum($branchNets->filter(fn (Money $net): bool => $net->isPositive()));
                $credit = Money::sum($branchNets->filter(fn (Money $net): bool => $net->isNegative()))->negated();

                $branchTotals[$column['id']] = [
                    'debit' => $debit->toString(),
                    'credit' => $credit->toString(),
                    'inBalance' => $debit->isEqualTo($credit),
                ];
            }
        }

        return [
            'from' => $from,
            'to' => $to,
            'heads' => $this->buildHierarchy($rows),
            'totals' => $this->trialBalanceTotals($rows),
            'unclassifiedCount' => $this->unclassifiedCount($rows),
            'columns' => $columns,
            'branchTotals' => $branchTotals,
        ];
    }

    /**
     * Adds the by-branch columns and totals to a report's `branchReport`
     * prop when the report computed them; otherwise returns it unchanged.
     *
     * @param  array<string, mixed>|null  $branchReport
     * @param  array{columns: list<array{id: int, name: string, code: string}>|null, branchTotals: array<int, array<string, mixed>>|null}  $data
     * @return array<string, mixed>|null
     */
    private function withBranchColumns(?array $branchReport, array $data): ?array
    {
        if ($branchReport === null || $data['columns'] === null) {
            return $branchReport;
        }

        return [...$branchReport, 'columns' => $data['columns'], 'totals' => $data['branchTotals']];
    }

    /**
     * The branch label for a PDF's data (printed from BR16 on), or null for a
     * tenant that is not multi-branch.
     */
    private function branchFilterLabel(BranchFilter $filter): ?string
    {
        return $filter->isActive() ? $filter->label() : null;
    }

    /**
     * One account's amounts in the given branch columns, zero where the
     * branch has no line on it.
     *
     * @param  array<int, Money>  $byBranch
     * @param  list<array{id: int, name: string, code: string}>  $columns
     * @return array<int, Money>
     */
    private function amountsByBranch(array $byBranch, array $columns): array
    {
        $amounts = [];

        foreach ($columns as $column) {
            $amounts[$column['id']] = $byBranch[$column['id']] ?? Money::zero();
        }

        return $amounts;
    }

    public function incomeStatementPdf(Request $request)
    {
        $fiscalYear = $this->resolveFiscalYear($request);
        abort_if($fiscalYear === null, 404, 'No fiscal year to report on.');

        $filter = BranchFilter::fromRequest($request);
        $data = $this->incomeStatementData($request, $fiscalYear, $filter, false);

        $pdf = Pdf::loadView('pdf.income-statement', array_merge([
            'company' => CompanySetting::current(),
            'documentNumber' => "Income Statement - {$fiscalYear->name}",
            'documentDate' => $data['to'],
            'dateAd' => $data['to'],
            'dateBs' => NepaliCalendar::formatBs($data['to']),
            'fiscalYearName' => $fiscalYear->name,
            'branchFilterLabel' => $this->branchFilterLabel($filter),
        ], $data));

        return $pdf->stream("income-statement-{$fiscalYear->name}.pdf");
    }

    public function incomeStatementExport(Request $request)
    {
        $fiscalYear = $this->resolveFiscalYear($request);
        abort_if($fiscalYear === null, 404, 'No fiscal year to report on.');

        $filter = BranchFilter::fromRequest($request);
        $data = $this->incomeStatementData($request, $fiscalYear, $filter, false);

        return Excel::download(
            new IncomeStatementExport($data['income'], $data['expenses'], $data['grossProfit'], $data['netProfit'], $this->branchFilterLabel($filter)),
            "income-statement-{$fiscalYear->name}.xlsx",
        );
    }

    /**
     * Shared by incomeStatement()'s Inertia page and its PDF/Excel siblings,
     * so all three read from one computation and can never disagree.
     *
     * With $withBranchColumns every row also carries `by_branch`: the
     * amount per branch in the statement's own sign (income positive when
     * earned, expenses positive when spent), from one grouped query; the
     * computed stock rows use each branch's own stock position. A row that
     * nets to zero for the company but not for some branch is kept (as
     * 0.00) so each branch's column still adds up to its own totals.
     *
     * @return array{from: string, to: string, income: array, expenses: array, stock: array, totalIncome: string, totalExpenses: string, grossProfit: string, netProfit: string, columns: list<array{id: int, name: string, code: string}>|null, branchTotals: array<int, array<string, string>>|null}
     */
    private function incomeStatementData(Request $request, FiscalYear $fiscalYear, BranchFilter $filter, bool $withBranchColumns): array
    {
        [$from, $to] = $this->resolveWindow($request, $fiscalYear);
        $lineIds = $filter->lineBranchIds();

        $excluded = $this->sweepVoucherIds($fiscalYear);
        $balances = $this->balancesByAccount($fiscalYear, $excluded, $from, $to, $lineIds);
        $stock = $this->stockPosition($fiscalYear, $to, $filter->effectiveIds(), $lineIds);

        $nets = [];
        $columns = null;
        $branchStock = [];

        if ($withBranchColumns) {
            $nets = BranchReportTotals::netByAccountAndBranch(
                fn (Builder $query) => $query->where('fiscal_year_id', $fiscalYear->id)
                    ->when($excluded !== [], fn (Builder $q) => $q->whereNotIn('id', $excluded))
                    ->whereDate('date', '>=', $from)
                    ->whereDate('date', '<=', $to),
                $lineIds,
            );
            // The stock rows read the whole year, not the window, so a branch
            // whose only figure is an opening stock balance needs a column too.
            $columns = $filter->columns([
                ...BranchReportTotals::branchIdsIn($nets),
                ...BranchReportTotals::branchIdsWithStores(),
                ...$this->stockAccountBranchIds($fiscalYear, $excluded, $lineIds),
            ]);
            $branchStock = $this->stockPositionsByBranch($fiscalYear, $to, array_column($columns, 'id'), $excluded, $stock['posted']);
        }

        $keep = $this->accountIdsWithBranchAmounts($nets);
        $income = $this->headBalances('Income', $balances, creditNormal: true, keepAccountIds: $keep);
        $expenses = $this->headBalances('Expenses', $balances, creditNormal: false, keepAccountIds: $keep);

        // A year whose trading entries have not been posted yet (it is still
        // open, or it was closed before this feature existed) has no stock
        // anywhere in its ledger, so the two sides are shown as computed
        // rows. Once close() has posted them, the real EXE9 / INI22 balances
        // are already in $balances and adding them again would double the
        // stock movement.
        if (! $stock['posted']) {
            $anyBranch = fn (string $side): bool => array_filter($branchStock, fn (array $position): bool => ! $position[$side]->isZero()) !== [];

            $expenses = $this->withVirtualRow($expenses, FiscalYear::OPENING_STOCK_CODE, 'Opening Stock', $stock['opening'], $anyBranch('opening'));
            $income = $this->withVirtualRow($income, FiscalYear::CLOSING_STOCK_CODE, 'Closing Stock', $stock['closing'], $anyBranch('closing'));
        }

        $totalIncome = Money::sum(array_column($income, 'amount'));
        $totalExpenses = Money::sum(array_column($expenses, 'amount'));
        $grossProfit = $this->grossProfit($income, $expenses);

        $branchTotals = null;

        if ($columns !== null) {
            $income = $this->statementRowsByBranch($income, $nets, $columns, $branchStock, creditNormal: true);
            $expenses = $this->statementRowsByBranch($expenses, $nets, $columns, $branchStock, creditNormal: false);
            $tradingCodes = $this->tradingCodes();
            $branchTotals = [];

            foreach ($columns as $column) {
                $branchId = $column['id'];
                $project = fn (array $rows): array => array_map(
                    fn (array $row): array => ['code' => $row['code'], 'amount' => $row['by_branch'][$branchId]],
                    $rows,
                );
                $branchIncome = Money::sum(array_column($project($income), 'amount'));
                $branchExpenses = Money::sum(array_column($project($expenses), 'amount'));

                $branchTotals[$branchId] = [
                    'totalIncome' => $branchIncome->toString(),
                    'totalExpenses' => $branchExpenses->toString(),
                    'grossProfit' => $this->grossProfit($project($income), $project($expenses), $tradingCodes)->toString(),
                    'netProfit' => $branchIncome->minus($branchExpenses)->toString(),
                    'openingStock' => $branchStock[$branchId]['opening']->toString(),
                    'closingStock' => $branchStock[$branchId]['closing']->toString(),
                ];
            }
        }

        return [
            'from' => $from,
            'to' => $to,
            'income' => $income,
            'expenses' => $expenses,
            'stock' => $stock,
            'totalIncome' => $totalIncome->toString(),
            'totalExpenses' => $totalExpenses->toString(),
            'grossProfit' => $grossProfit->toString(),
            'netProfit' => $totalIncome->minus($totalExpenses)->toString(),
            'columns' => $columns,
            'branchTotals' => $branchTotals,
        ];
    }

    /**
     * The income statement split by branch, for the branch comparison report
     * (plan 12, BR16, BranchComparisonReportController). Nothing is computed
     * here that the income statement does not already compute: `statement`
     * is incomeStatementData() with the by-branch columns on, so a branch's
     * figures in the comparison are exactly its column on the income
     * statement. `tradingCodes` comes along so the caller splits the trading
     * section the same way grossProfit() does.
     *
     * Validates the request (fiscal year, window) like every report action
     * here, through resolveFiscalYear(). `statement` is null when no fiscal
     * year exists yet. No existing page calls this.
     *
     * @return array{fiscalYears: Collection<int, array{id: int, name: string, status: string, startDate: string, endDate: string}>, fiscalYear: FiscalYear|null, statement: array<string, mixed>|null, tradingCodes: array{sales: list<string>, purchases: list<string>}}
     */
    public function branchStatements(Request $request, BranchFilter $filter): array
    {
        $fiscalYear = $this->resolveFiscalYear($request);

        return [
            'fiscalYears' => $this->fiscalYearOptions(),
            'fiscalYear' => $fiscalYear,
            'statement' => $fiscalYear === null ? null : $this->incomeStatementData($request, $fiscalYear, $filter, true),
            'tradingCodes' => $this->tradingCodes(),
        ];
    }

    /**
     * Adds `by_branch` (decimal strings in the statement's sign) to income
     * statement rows. A computed stock row (no account id) takes each
     * branch's own opening or closing stock.
     *
     * @param  array<int, array{id: ?int, code: ?string, name: string, amount: string, computed: bool}>  $rows
     * @param  array<int, array<int, Money>>  $nets
     * @param  list<array{id: int, name: string, code: string}>  $columns
     * @param  array<int, array{opening: Money, closing: Money}>  $branchStock
     * @return array<int, array<string, mixed>>
     */
    private function statementRowsByBranch(array $rows, array $nets, array $columns, array $branchStock, bool $creditNormal): array
    {
        return array_map(function (array $row) use ($nets, $columns, $branchStock, $creditNormal): array {
            $byBranch = [];

            foreach ($columns as $column) {
                $branchId = $column['id'];

                if ($row['id'] === null) {
                    $amount = $branchStock[$branchId][$row['code'] === FiscalYear::OPENING_STOCK_CODE ? 'opening' : 'closing'];
                } else {
                    $net = $nets[$row['id']][$branchId] ?? Money::zero();
                    $amount = $creditNormal ? $net->negated() : $net;
                }

                $byBranch[$branchId] = $amount->toString();
            }

            return [...$row, 'by_branch' => $byBranch];
        }, $rows);
    }

    /**
     * Ids of the accounts that are not zero in at least one branch.
     *
     * @param  array<int, array<int, Money>>  $nets
     * @return list<int>
     */
    private function accountIdsWithBranchAmounts(array $nets): array
    {
        return array_keys(array_filter(
            $nets,
            fn (array $byBranch): bool => array_filter($byBranch, fn (Money $net): bool => ! $net->isZero()) !== [],
        ));
    }

    /**
     * Branches with a Stock in Hand or Opening Stock balance anywhere in the
     * fiscal year (sweep vouchers excluded), for the by-branch columns.
     *
     * @param  array<int, int>  $excludedVoucherIds  sweepVoucherIds()
     * @param  list<int>|null  $lineBranchIds  BranchFilter::lineBranchIds()
     * @return list<int>
     */
    private function stockAccountBranchIds(FiscalYear $fiscalYear, array $excludedVoucherIds, ?array $lineBranchIds): array
    {
        $accountIds = Account::whereIn('code', [FiscalYear::STOCK_IN_HAND_CODE, FiscalYear::OPENING_STOCK_CODE])->pluck('id')->map(fn ($id): int => (int) $id)->all();

        if ($accountIds === []) {
            return [];
        }

        $nets = BranchReportTotals::netByAccountAndBranch(
            fn (Builder $query) => $query->where('fiscal_year_id', $fiscalYear->id)
                ->when($excludedVoucherIds !== [], fn (Builder $q) => $q->whereNotIn('id', $excludedVoucherIds))
                ->whereDate('date', '<=', $fiscalYear->end_date->toDateString()),
            $lineBranchIds,
            $accountIds,
        );

        return BranchReportTotals::branchIdsIn($nets);
    }

    /**
     * Opening and closing trading stock of each branch on its own, the
     * by-branch counterpart of stockPosition() (same rules, same "posted"
     * answer, which is a fact about the company's year-end vouchers).
     *
     * One grouped read serves every branch: sweep vouchers never touch Stock
     * in Hand (that is how sweepVoucherIds() tells them apart), so the
     * sweep-free nets give both the opening and the posted closing figure.
     * Not-posted closing stock comes from BranchReportTotals with the
     * year-end Main-remainder convention, so the columns add up to the
     * company's computed closing stock exactly.
     *
     * @param  list<int>  $branchIds
     * @param  array<int, int>  $excludedVoucherIds  sweepVoucherIds()
     * @return array<int, array{opening: Money, closing: Money}>
     */
    private function stockPositionsByBranch(FiscalYear $fiscalYear, string $to, array $branchIds, array $excludedVoucherIds, bool $posted): array
    {
        $stockInHandId = Account::where('code', FiscalYear::STOCK_IN_HAND_CODE)->value('id');
        $positions = array_fill_keys($branchIds, ['opening' => Money::zero(), 'closing' => Money::zero()]);

        if ($stockInHandId === null || $branchIds === []) {
            return $positions;
        }

        $stockInHandId = (int) $stockInHandId;
        $openingStockId = Account::where('code', FiscalYear::OPENING_STOCK_CODE)->value('id');
        $openingStockId = $openingStockId === null ? null : (int) $openingStockId;
        $endDate = $fiscalYear->end_date->toDateString();

        $nets = BranchReportTotals::netByAccountAndBranch(
            fn (Builder $query) => $query->where('fiscal_year_id', $fiscalYear->id)
                ->when($excludedVoucherIds !== [], fn (Builder $q) => $q->whereNotIn('id', $excludedVoucherIds))
                ->whereDate('date', '<=', $endDate),
            $branchIds,
            array_values(array_filter([$stockInHandId, $openingStockId])),
        );

        $closingValues = $posted ? [] : BranchReportTotals::periodicClosingValuesByBranch($to, $branchIds);

        foreach ($branchIds as $branchId) {
            $stockInHand = $nets[$stockInHandId][$branchId] ?? Money::zero();

            $positions[$branchId] = $posted
                ? [
                    'opening' => $openingStockId === null ? Money::zero() : ($nets[$openingStockId][$branchId] ?? Money::zero()),
                    'closing' => $stockInHand,
                ]
                : ['opening' => $stockInHand, 'closing' => $closingValues[$branchId]];
        }

        return $positions;
    }

    public function balanceSheetPdf(Request $request)
    {
        $fiscalYear = $this->resolveFiscalYear($request);
        abort_if($fiscalYear === null, 404, 'No fiscal year to report on.');

        $filter = BranchFilter::fromRequest($request);
        $data = $this->balanceSheetData($request, $fiscalYear, $filter, false);

        $pdf = Pdf::loadView('pdf.balance-sheet', array_merge([
            'company' => CompanySetting::current(),
            'documentNumber' => "Balance Sheet - {$fiscalYear->name}",
            'documentDate' => $data['to'],
            'dateAd' => $data['to'],
            'dateBs' => NepaliCalendar::formatBs($data['to']),
            'fiscalYearName' => $fiscalYear->name,
            'branchFilterLabel' => $this->branchFilterLabel($filter),
        ], $data));

        return $pdf->stream("balance-sheet-{$fiscalYear->name}.pdf");
    }

    public function balanceSheetExport(Request $request)
    {
        $fiscalYear = $this->resolveFiscalYear($request);
        abort_if($fiscalYear === null, 404, 'No fiscal year to report on.');

        $filter = BranchFilter::fromRequest($request);
        $data = $this->balanceSheetData($request, $fiscalYear, $filter, false);

        return Excel::download(
            new BalanceSheetExport($data['heads'], $data['totalAssets'], $data['totalLiabilitiesAndCapital'], $this->branchFilterLabel($filter)),
            "balance-sheet-{$fiscalYear->name}.xlsx",
        );
    }

    /**
     * Shared by balanceSheet()'s Inertia page and its PDF/Excel siblings.
     *
     * A balance sheet is a position "as at", so it always reads the whole
     * year up to $to - the window's `from` only moves the Trial Balance's
     * opening column and the Income Statement's period, never a closing
     * position.
     *
     * Nothing is excluded here, unlike the other two reports: the sweep
     * retargets profit-and-loss accounts (which a balance sheet never shows)
     * onto "Profit & Loss", a Capital account whose balance this report
     * absolutely does need.
     *
     * Unswept profit is read for EVERY year, not only an open one. A closed
     * year stays Closed after being reopened for a correction, and that
     * correction lands on P&L accounts the sweep had already zeroed - so a
     * status check hid exactly the case that unbalances the report (audit
     * P0-19). The formula is zero by construction for a cleanly closed year,
     * and relock() re-sweeps whatever a correction left behind.
     *
     * Stock the ledger does not know about yet (see stockPosition()) is an
     * asset AND the same amount of profit, so it is added to both sides,
     * keeping the sheet balanced instead of tipping it.
     *
     * Branches: a branch, or any set of branches, balances on its own,
     * because every voucher balances per branch (invariant I1) and the
     * Inter-Branch Clearing account is an ordinary balance-sheet row here.
     * With $withBranchColumns every row also carries `by_branch` in the
     * statement's sign (Assets: debit minus credit; every other head,
     * Unclassified included: credit minus debit, the way the totals add
     * them), with each branch's own computed stock on Stock in Hand, and the
     * per-branch totals say whether each branch balances. A per-branch
     * mismatch is reported there, never thrown.
     *
     * @return array{from: string, to: string, heads: array, stock: array, currentYearEarnings: string, totalAssets: string, totalLiabilitiesAndCapital: string, balanceWarning: ?string, unclassifiedCount: int, columns: list<array{id: int, name: string, code: string}>|null, branchTotals: array<int, array<string, string|bool>>|null}
     */
    private function balanceSheetData(Request $request, FiscalYear $fiscalYear, BranchFilter $filter, bool $withBranchColumns): array
    {
        [$from, $to] = $this->resolveWindow($request, $fiscalYear);
        $lineIds = $filter->lineBranchIds();

        $balances = $this->balancesByAccount($fiscalYear, [], null, $to, $lineIds);
        $rows = $this->accountRows($balances, ['Assets', 'Liabilities', 'Capital']);
        $stock = $this->stockPosition($fiscalYear, $to, $filter->effectiveIds(), $lineIds);
        $currentYearEarnings = $this->unsweptProfitAndLoss($fiscalYear, $to, $lineIds);
        $stockAdjustment = Money::of($stock['closing'])->minus(Money::of($stock['opening']));

        if (! $stock['posted'] && ! $stockAdjustment->isZero()) {
            $rows = $this->withStockInHandRow($rows, $stockAdjustment);
            $currentYearEarnings = $currentYearEarnings->plus($stockAdjustment);
        }

        $assetRows = $rows->filter(fn (array $row) => $row['headName'] === 'Assets');
        $otherRows = $rows->filter(fn (array $row) => $row['headName'] !== 'Assets');

        $totalAssets = Money::sum($assetRows->pluck('debit'))->minus(Money::sum($assetRows->pluck('credit')));
        $totalLiabilitiesAndCapital = Money::sum($otherRows->pluck('credit'))
            ->minus(Money::sum($otherRows->pluck('debit')))
            ->plus($currentYearEarnings);

        $columns = null;
        $branchTotals = null;

        if ($withBranchColumns) {
            [$rows, $columns, $branchTotals] = $this->balanceSheetByBranch($rows, $fiscalYear, $filter, $to, $stock['posted']);
        }

        return [
            'from' => $from,
            'to' => $to,
            'heads' => $this->buildHierarchy($rows),
            'stock' => $stock,
            'currentYearEarnings' => $currentYearEarnings->toString(),
            'totalAssets' => $totalAssets->toString(),
            'totalLiabilitiesAndCapital' => $totalLiabilitiesAndCapital->toString(),
            'balanceWarning' => $this->assertBalanced($fiscalYear, $totalAssets, $totalLiabilitiesAndCapital),
            'unclassifiedCount' => $this->unclassifiedCount($rows),
            'columns' => $columns,
            'branchTotals' => $branchTotals,
        ];
    }

    /**
     * The by-branch half of balanceSheetData(): one grouped query for every
     * (account, branch) net up to $to, each branch's own stock position and
     * unswept profit, and per-branch totals.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array{0: Collection<int, array<string, mixed>>, 1: list<array{id: int, name: string, code: string}>, 2: array<int, array{totalAssets: string, totalLiabilitiesAndCapital: string, currentYearEarnings: string, openingStock: string, closingStock: string, balanced: bool}>}
     */
    private function balanceSheetByBranch(Collection $rows, FiscalYear $fiscalYear, BranchFilter $filter, string $to, bool $stockPosted): array
    {
        $nets = BranchReportTotals::netByAccountAndBranch(
            fn (Builder $query) => $query->where('fiscal_year_id', $fiscalYear->id)->whereDate('date', '<=', $to),
            $filter->lineBranchIds(),
        );
        $columns = $filter->columns([...BranchReportTotals::branchIdsIn($nets), ...BranchReportTotals::branchIdsWithStores()]);
        $branchStock = $this->stockPositionsByBranch($fiscalYear, $to, array_column($columns, 'id'), $this->sweepVoucherIds($fiscalYear), $stockPosted);
        $profitAndLossAccountIds = $this->profitAndLossAccountIds();
        $stockInHandId = Account::where('code', FiscalYear::STOCK_IN_HAND_CODE)->value('id');

        $adjustments = [];

        foreach ($columns as $column) {
            $position = $branchStock[$column['id']];
            $adjustments[$column['id']] = $stockPosted ? Money::zero() : $position['closing']->minus($position['opening']);
        }

        $hasStockRow = $stockInHandId !== null && $rows->contains(fn (array $row): bool => $row['account']->id === (int) $stockInHandId);
        $anyAdjustment = array_filter($adjustments, fn (Money $adjustment): bool => ! $adjustment->isZero()) !== [];

        if (! $hasStockRow && $anyAdjustment) {
            $rows = $this->withStockInHandRow($rows, Money::zero());
        }

        $rows = $rows->map(function (array $row) use ($nets, $columns, $adjustments, $stockInHandId): array {
            $byBranch = [];

            foreach ($this->amountsByBranch($nets[$row['account']->id] ?? [], $columns) as $branchId => $net) {
                if ($stockInHandId !== null && $row['account']->id === (int) $stockInHandId) {
                    $net = $net->plus($adjustments[$branchId]);
                }

                $byBranch[$branchId] = $row['headName'] === 'Assets' ? $net : $net->negated();
            }

            return [...$row, 'by_branch' => $byBranch];
        });

        $branchTotals = [];

        foreach ($columns as $column) {
            $branchId = $column['id'];
            $profitAndLossNet = Money::sum($profitAndLossAccountIds->map(fn ($accountId): Money => $nets[(int) $accountId][$branchId] ?? Money::zero()));
            $earnings = $profitAndLossNet->negated()->plus($adjustments[$branchId]);
            $assets = Money::sum($rows->filter(fn (array $row) => $row['headName'] === 'Assets')->map(fn (array $row): Money => $row['by_branch'][$branchId]));
            $liabilitiesAndCapital = Money::sum($rows->filter(fn (array $row) => $row['headName'] !== 'Assets')->map(fn (array $row): Money => $row['by_branch'][$branchId]))
                ->plus($earnings);

            $branchTotals[$branchId] = [
                'totalAssets' => $assets->toString(),
                'totalLiabilitiesAndCapital' => $liabilitiesAndCapital->toString(),
                'currentYearEarnings' => $earnings->toString(),
                'openingStock' => $branchStock[$branchId]['opening']->toString(),
                'closingStock' => $branchStock[$branchId]['closing']->toString(),
                'balanced' => $assets->isEqualTo($liabilitiesAndCapital),
            ];
        }

        return [$rows, $columns, $branchTotals];
    }

    public function dayBookPdf(Request $request)
    {
        $fiscalYear = $this->resolveFiscalYear($request);
        abort_if($fiscalYear === null, 404, 'No fiscal year to report on.');

        $filter = BranchFilter::fromRequest($request);
        $data = $this->dayBookData($request, $fiscalYear, $filter);

        $pdf = Pdf::loadView('pdf.day-book', array_merge([
            'company' => CompanySetting::current(),
            'documentNumber' => "Day Book - {$fiscalYear->name}",
            'documentDate' => $data['to'],
            'dateAd' => $data['to'],
            'dateBs' => NepaliCalendar::formatBs($data['to']),
            'fiscalYearName' => $fiscalYear->name,
            'branchFilterLabel' => $this->branchFilterLabel($filter),
        ], $data));

        return $pdf->stream("day-book-{$fiscalYear->name}.pdf");
    }

    public function dayBookExport(Request $request)
    {
        $fiscalYear = $this->resolveFiscalYear($request);
        abort_if($fiscalYear === null, 404, 'No fiscal year to report on.');

        $filter = BranchFilter::fromRequest($request);
        $data = $this->dayBookData($request, $fiscalYear, $filter);

        return Excel::download(new DayBookExport($data['vouchers'], $this->branchFilterLabel($filter)), "day-book-{$fiscalYear->name}.xlsx");
    }

    /**
     * Shared by dayBook()'s page and its PDF/Excel siblings.
     *
     * Branches: narrowed, a voucher is listed when ANY of its lines is in
     * the branch set (an EXISTS on journal_voucher_lines.branch_id), and
     * only those lines are shown and totalled, the same way a restricted
     * user already sees a cross-branch voucher through BranchAccessScope.
     * For a multi-branch tenant each voucher also names its header (origin)
     * branch, taken from the list the filter already loaded, so it costs no
     * query.
     *
     * @return array{from: string, to: string, vouchers: array, totalDebit: string, totalCredit: string}
     */
    private function dayBookData(Request $request, FiscalYear $fiscalYear, BranchFilter $filter): array
    {
        $voucherType = $request->string('voucher_type')->toString() ?: null;
        [$from, $to] = $this->resolveWindow($request, $fiscalYear);
        // effectiveIds(), not lineBranchIds(): a restricted user viewing their
        // whole allowed set must still only see vouchers with a line in it.
        $lineIds = $filter->effectiveIds();
        $withBranch = $filter->isActive();

        $vouchers = JournalVoucher::query()
            ->with($lineIds === null ? 'lines.account:id,code,name' : [
                // Before the nested key, so this constraint is the one kept.
                'lines' => fn (HasMany $lines) => BranchFilter::narrowLines($lines->getQuery(), $lineIds),
                'lines.account:id,code,name',
            ])
            ->where('fiscal_year_id', $fiscalYear->id)
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->when($voucherType !== null, fn (Builder $query) => $query->where('voucher_type', $voucherType))
            ->when($lineIds !== null, fn (Builder $query) => $query->whereExists(fn (QueryBuilder $lines) => BranchFilter::narrowLines(
                $lines->selectRaw('1')
                    ->from('journal_voucher_lines')
                    ->whereColumn('journal_voucher_lines.journal_voucher_id', 'journal_vouchers.id'),
                $lineIds,
            )))
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        $lines = $vouchers->flatMap->lines;

        // Branch-series numbers (BR20) print with their code; no query when
        // every voucher is in the shared series.
        JournalVoucher::loadSeriesBranches($vouchers);

        return [
            'from' => $from,
            'to' => $to,
            'vouchers' => $vouchers->map(fn (JournalVoucher $voucher) => [
                'date' => $voucher->date->toDateString(),
                'voucherType' => $voucher->voucher_type->value,
                'voucherNumber' => $voucher->displayNumber(),
                'narration' => $voucher->narration,
                'lines' => $voucher->lines->map(fn (JournalVoucherLine $line) => [
                    'accountCode' => $line->account->code,
                    'accountName' => $line->account->name,
                    'debit' => Money::of($line->debit)->toString(),
                    'credit' => Money::of($line->credit)->toString(),
                    'narration' => $line->narration,
                ])->values()->all(),
                ...($withBranch ? ['branch' => $filter->branchName($voucher->branch_id === null ? null : (int) $voucher->branch_id)] : []),
            ])->values()->all(),
            'totalDebit' => Money::sum($lines->map(fn (JournalVoucherLine $line) => Money::of($line->debit)))->toString(),
            'totalCredit' => Money::sum($lines->map(fn (JournalVoucherLine $line) => Money::of($line->credit)))->toString(),
        ];
    }

    public function cashBookPdf(Request $request)
    {
        return $this->accountBookPdf($request, Account::where('code', 'AS1')->firstOrFail(), 'Cash Book');
    }

    public function cashBookExport(Request $request)
    {
        return $this->accountBookExport($request, Account::where('code', 'AS1')->firstOrFail(), 'cash-book');
    }

    public function bankBookPdf(Request $request)
    {
        $account = Account::findOrFail($request->integer('account_id'));

        return $this->accountBookPdf($request, $account, 'Bank Book');
    }

    public function bankBookExport(Request $request)
    {
        $account = Account::findOrFail($request->integer('account_id'));

        return $this->accountBookExport($request, $account, 'bank-book');
    }

    private function accountBookPdf(Request $request, Account $account, string $title)
    {
        $fiscalYear = $this->resolveFiscalYear($request);
        abort_if($fiscalYear === null, 404, 'No fiscal year to report on.');

        $filter = BranchFilter::fromRequest($request);
        [$from, $to] = $this->resolveWindow($request, $fiscalYear);
        $data = $this->accountBook($account, $fiscalYear, $from, $to, $filter->lineBranchIds());

        $pdf = Pdf::loadView('pdf.account-book', array_merge([
            'title' => "{$title} - {$account->name}",
            'company' => CompanySetting::current(),
            'documentNumber' => $account->code ? "{$account->code} - {$account->name}" : $account->name,
            'documentDate' => $to,
            'dateAd' => $to,
            'dateBs' => NepaliCalendar::formatBs($to),
            'fiscalYearName' => $fiscalYear->name,
            'from' => $from,
            'to' => $to,
            'branchFilterLabel' => $this->branchFilterLabel($filter),
        ], $data));

        return $pdf->stream(Str::slug($title).'-'.$account->id.'.pdf');
    }

    private function accountBookExport(Request $request, Account $account, string $filenamePrefix)
    {
        $fiscalYear = $this->resolveFiscalYear($request);
        abort_if($fiscalYear === null, 404, 'No fiscal year to report on.');

        [$from, $to] = $this->resolveWindow($request, $fiscalYear);
        $filter = BranchFilter::fromRequest($request);
        $data = $this->accountBook($account, $fiscalYear, $from, $to, $filter->lineBranchIds());

        return Excel::download(
            new AccountBookExport($data['entries'], $data['openingBalance'], $data['closingBalance'], $this->branchFilterLabel($filter)),
            "{$filenamePrefix}-{$account->id}.xlsx",
        );
    }

    /**
     * Cancelled Documents report (T14 task 4): every cancelled sale,
     * purchase, return, receipt, payment, capital document and journal
     * voucher in one list - number, date, cancel date, who and reason
     * (CONTRACTS C5's four columns on each module table). A cash/bank
     * voucher (CONTRACTS/T14) has no owning module row, so it surfaces here
     * too, labelled by its own voucher type, through the Journal Vouchers
     * bucket below - JournalVoucher::sourceRecordLabel() is what keeps a
     * module-owned voucher (a cancelled Sale's underlying voucher, say) from
     * being listed a second time here as if it were a standalone one.
     *
     * Audit T15-14: legacy's two narrower cancelled-document views had a
     * date range and a party filter with server-side pagination; this one
     * unions everything with no request params at all. `from`/`to` and
     * customer_id/supplier_id are optional here for exactly that reason -
     * every one of them defaults to "no filter", so a bare hit on this
     * route still returns the full unfiltered list exactly as before.
     *
     * Branches (plan 12, BR22): every bucket is narrowed to the top-bar
     * switcher's branch (BranchListFilter, a no-op for a company that is not
     * multi-branch), and each row names its branch only when several
     * branches can appear (BranchListFilter::columnVisible()).
     */
    public function cancelledDocuments(Request $request): Response
    {
        [$from, $to] = $this->resolveCancelledDocumentsDateRange($request);
        $customerId = $request->integer('customer_id') ?: null;
        $supplierId = $request->integer('supplier_id') ?: null;

        return Inertia::render('Tenant/Reports/CancelledDocuments', [
            'rows' => $this->cancelledDocumentRows($request, $from, $to, $customerId, $supplierId),
            'customers' => Customer::query()->orderBy('name')->get(['id', 'name']),
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name']),
            'from' => $from,
            'to' => $to,
            'customerId' => $customerId,
            'supplierId' => $supplierId,
        ]);
    }

    public function cancelledDocumentsExport(Request $request)
    {
        // Still unfiltered by date and party, as before; only the switcher's
        // branch narrows it, the same as the page (a no-op for a company that
        // is not multi-branch, which also gets no Branch column).
        return Excel::download(
            new CancelledDocumentsExport($this->cancelledDocumentRows($request), BranchListFilter::columnVisible($request)),
            'cancelled-documents.xlsx',
        );
    }

    /**
     * Unlike resolveWindow(), there is no fiscal year to clamp this report
     * into - it has always spanned every fiscal year ever posted. So a
     * missing from/to here means "no date filter" (null), not "this
     * year", which is what keeps the unfiltered response identical to
     * before this filter existed.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function resolveCancelledDocumentsDateRange(Request $request): array
    {
        $from = $request->string('from')->toString();
        $to = $request->string('to')->toString();

        return [$from !== '' ? $from : null, $to !== '' ? $to : null];
    }

    /**
     * Each row carries `branch` ({ id, name }, or null) only when
     * BranchListFilter::columnVisible() holds, so a company that is not
     * multi-branch gets exactly the rows it got before.
     *
     * @return array<int, array{type: string, number: string, date: string, cancelledAt: ?string, cancelledBy: ?string, reason: ?string, party: ?string, branch?: ?array{id: int, name: string}}>
     */
    private function cancelledDocumentRows(Request $request, ?string $from = null, ?string $to = null, ?int $customerId = null, ?int $supplierId = null): array
    {
        $rows = collect();
        $showBranch = BranchListFilter::columnVisible($request);
        $branchOf = fn ($record): ?array => $record->branch === null
            ? null
            : ['id' => (int) $record->branch->id, 'name' => $record->branch->name];

        $modules = [
            ['type' => 'Sale', 'model' => Sale::class, 'partyType' => 'customer', 'number' => fn (Sale $m) => $m->invoice_number ?? "#{$m->id}"],
            ['type' => 'Purchase', 'model' => Purchase::class, 'partyType' => 'supplier', 'number' => fn (Purchase $m) => $m->bill_number ?: "#{$m->id}"],
            ['type' => 'Sales Return', 'model' => SalesReturn::class, 'partyType' => 'customer', 'number' => fn (SalesReturn $m) => $m->documentNumber()],
            ['type' => 'Purchase Return', 'model' => PurchaseReturn::class, 'partyType' => 'supplier', 'number' => fn (PurchaseReturn $m) => $m->documentNumber()],
            ['type' => 'Receipt', 'model' => Receipt::class, 'partyType' => 'customer', 'number' => fn (Receipt $m) => "#{$m->id}"],
            ['type' => 'Payment', 'model' => Payment::class, 'partyType' => 'supplier', 'number' => fn (Payment $m) => "#{$m->id}"],
            ['type' => 'Capital Sale', 'model' => CapitalSale::class, 'partyType' => 'customer', 'number' => fn (CapitalSale $m) => $m->documentNumber()],
            ['type' => 'Capital Purchase', 'model' => CapitalPurchase::class, 'partyType' => 'supplier', 'number' => fn (CapitalPurchase $m) => $m->bill_number ?: "#{$m->id}"],
        ];

        foreach ($modules as $module) {
            // A customer filter has nothing to say about a supplier-owned
            // module (and vice versa) - such a module contributes no rows
            // at all rather than being silently searched on the wrong
            // column.
            if ($customerId !== null && $module['partyType'] !== 'customer') {
                continue;
            }

            if ($supplierId !== null && $module['partyType'] !== 'supplier') {
                continue;
            }

            $model = $module['model'];
            $partyColumn = $module['partyType'] === 'customer' ? 'customer_id' : 'supplier_id';
            $partyId = $module['partyType'] === 'customer' ? $customerId : $supplierId;

            $query = $model::query()
                ->where('status', 'cancelled')
                ->when($from !== null, fn (Builder $q) => $q->whereDate('date', '>=', $from))
                ->when($to !== null, fn (Builder $q) => $q->whereDate('date', '<=', $to))
                ->when($partyId !== null, fn (Builder $q) => $q->where($partyColumn, $partyId))
                ->with(['canceller:id,name', $module['partyType'].':id,name'])
                ->when($showBranch, fn (Builder $q) => $q->with('branch:id,name'));

            BranchListFilter::apply($query, $request)
                ->get()
                ->each(function ($record) use ($module, $rows, $showBranch, $branchOf) {
                    $row = [
                        'type' => $module['type'],
                        'number' => ($module['number'])($record),
                        'date' => $record->date?->toDateString() ?? '',
                        'cancelledAt' => $record->cancelled_at?->toDateTimeString(),
                        'cancelledBy' => $record->canceller?->name,
                        'reason' => $record->cancel_reason,
                        'party' => $record->{$module['partyType']}?->name,
                    ];

                    if ($showBranch) {
                        $row['branch'] = $branchOf($record);
                    }

                    $rows->push($row);
                });
        }

        // Journal Vouchers and cash/bank vouchers: only the ones with no
        // owning module record (see sourceRecordLabel()'s docblock) - every
        // other cancelled voucher_type belongs to one of the modules above
        // and is already listed under its own record. A standalone voucher
        // has no customer_id/supplier_id of its own, so a party filter
        // excludes this bucket entirely rather than matching it by accident.
        if ($customerId === null && $supplierId === null) {
            // Narrowed by the voucher's header (origin) branch, the branch the
            // Journal Vouchers list names for it.
            $cancelledVouchers = BranchListFilter::apply(
                JournalVoucher::query()
                    ->where('status', 'cancelled')
                    ->whereIn('voucher_type', array_map(fn (VoucherType $t) => $t->value, VoucherType::manuallyCancellableTypes()))
                    ->when($from !== null, fn (Builder $q) => $q->whereDate('date', '>=', $from))
                    ->when($to !== null, fn (Builder $q) => $q->whereDate('date', '<=', $to))
                    ->with('reversal.creator:id,name')
                    ->when($showBranch, fn (Builder $q) => $q->with('branch:id,name')),
                $request,
            )->get();

            // One query for the branch-series codes printedNumber() reads
            // (none when every voucher is in the shared series, BR20).
            JournalVoucher::loadSeriesBranches($cancelledVouchers);

            $cancelledVouchers
                ->each(function (JournalVoucher $voucher) use ($rows, $showBranch, $branchOf) {
                    if ($voucher->sourceRecordLabel() !== null) {
                        return;
                    }

                    $reversal = $voucher->reversal;
                    $reason = $reversal?->narration;
                    // Strip the "Cancellation of ... : " prefix cancel() writes,
                    // leaving just the reason the user typed.
                    if ($reason !== null && str_contains($reason, ': ')) {
                        $reason = substr($reason, strrpos($reason, ': ') + 2);
                    }

                    $row = [
                        'type' => ucwords(str_replace('_', ' ', $voucher->voucher_type->value)),
                        'number' => $voucher->printedNumber($voucher->voucher_type->value),
                        'date' => $voucher->date->toDateString(),
                        'cancelledAt' => $reversal?->date?->toDateString(),
                        'cancelledBy' => $reversal?->creator?->name,
                        'reason' => $reason,
                        'party' => null,
                    ];

                    if ($showBranch) {
                        $row['branch'] = $branchOf($voucher);
                    }

                    $rows->push($row);
                });
        }

        return $rows->sortByDesc('cancelledAt')->values()->all();
    }

    /**
     * @return Collection<int, array{id: int, name: string, status: string, startDate: string, endDate: string}>
     */
    private function fiscalYearOptions(): Collection
    {
        return FiscalYear::query()
            ->orderByDesc('start_date')
            ->get(['id', 'name', 'status', 'start_date', 'end_date'])
            ->map(fn (FiscalYear $fiscalYear) => [
                'id' => $fiscalYear->id,
                'name' => $fiscalYear->name,
                'status' => $fiscalYear->status->value,
                'startDate' => $fiscalYear->start_date->toDateString(),
                'endDate' => $fiscalYear->end_date->toDateString(),
            ]);
    }

    private function resolveFiscalYear(Request $request): ?FiscalYear
    {
        // Every report action starts here, so a bad year or date window is a
        // validation error for all of them, rather than a silent fallback
        // to another year that the page then labels as the one asked for
        // (flags G-21).
        $request->validate(self::REPORT_INPUT_RULES);

        $requested = $request->integer('fiscal_year_id');

        if ($requested) {
            return FiscalYear::findOrFail($requested);
        }

        return FiscalYear::query()->where('status', FiscalYearStatus::Open)->first()
            ?? FiscalYear::query()->orderByDesc('start_date')->first();
    }

    /**
     * The optional date window, always clamped inside the chosen fiscal
     * year. A report can no longer span two years (which is what let the
     * Cash Book double-count an opening balance), and a nonsensical window
     * falls back to the whole year rather than rendering an empty page.
     *
     * @return array{0: string, 1: string}
     */
    private function resolveWindow(Request $request, FiscalYear $fiscalYear): array
    {
        $start = $fiscalYear->start_date->toDateString();
        $end = $fiscalYear->end_date->toDateString();

        $from = $request->string('from')->toString();
        $to = $request->string('to')->toString();

        $from = $from !== '' ? max($from, $start) : $start;
        $to = $to !== '' ? min($to, $end) : $end;

        return $from > $to ? [$start, $end] : [$from, $to];
    }

    /**
     * Ids of this year's P&L SWEEP vouchers, which Trial Balance and Income
     * Statement must leave out.
     *
     * FiscalYear::close() posts its sweep INTO the closing year itself,
     * zeroing every profit-and-loss account within that year's own line
     * set - so summing everything would make a closed year's trial balance
     * and income statement always report zero activity, which defeats the
     * point of either report.
     *
     * It is identified structurally rather than by voucher type, because
     * close() now posts THREE ClosingEntry vouchers and only one of them is
     * the sweep. By construction the sweep touches nothing but
     * profit-and-loss accounts plus "Profit & Loss" itself, while each of
     * the two trading-stock vouchers always pairs a stock account with the
     * balance-sheet account "Stock in Hand" - so "a ClosingEntry voucher
     * with no Stock in Hand line" is exactly the sweep, and stays exactly
     * the sweep even in the edge case where the year's net profit is zero
     * and the sweep carries no "Profit & Loss" line at all.
     *
     * @return array<int, int>
     */
    private function sweepVoucherIds(FiscalYear $fiscalYear): array
    {
        $stockInHandId = Account::where('code', FiscalYear::STOCK_IN_HAND_CODE)->value('id');

        // Detection is unrestricted (plan 12, section 5.7): which vouchers
        // are the sweep is a fact about the whole voucher, not the branches
        // the viewer may see. The ledger lines themselves stay scoped.
        return BranchContext::unrestricted(fn (): array => JournalVoucher::query()
            ->where('fiscal_year_id', $fiscalYear->id)
            ->where('voucher_type', VoucherType::ClosingEntry->value)
            ->when(
                $stockInHandId !== null,
                fn (Builder $query) => $query->whereDoesntHave('lines', fn (Builder $lines) => $lines->where('account_id', $stockInHandId)),
            )
            ->pluck('id')
            ->all());
    }

    /**
     * Net (debit - credit) balance per account inside one fiscal year.
     *
     * @param  array<int, int>  $excludeVoucherIds
     * @param  list<int>|null  $branchIds  BranchFilter::lineBranchIds(); null adds no predicate.
     * @return array<int, Money>
     */
    private function balancesByAccount(FiscalYear $fiscalYear, array $excludeVoucherIds, ?string $from, ?string $to, ?array $branchIds = null): array
    {
        return array_map(
            fn (array $totals) => $totals['debit']->minus($totals['credit']),
            $this->periodTotalsByAccount($fiscalYear, $excludeVoucherIds, $from, $to, $branchIds),
        );
    }

    /**
     * Gross debit and gross credit per account inside one fiscal year, in a
     * single grouped query. The Trial Balance's Period columns show these
     * two sums side by side (cash that took in 1,000 and paid out 400 shows
     * 1,000 Dr and 400 Cr, not a net 600 Dr), which is what a six-column
     * trial balance means; balancesByAccount() nets them for every report
     * that only needs the position.
     *
     * Scaled-integer SUM for the same reason FiscalYear::netBalance() and
     * StockCosting use it: SQLite gives a decimal column REAL affinity, so a
     * plain SUM() there comes back as a float. whereDate() rather than
     * whereBetween() because a `date`-cast column is stored as a full
     * "Y-m-d H:i:s" string on SQLite, which sorts AFTER the bare "Y-m-d" a
     * filter sends - so the last day of any range silently fell out of it.
     *
     * The branch predicate (plan 12, BR13) goes on the lines table, outside
     * the whereHas; null adds nothing, so the query is byte-identical to the
     * one before branches.
     *
     * @param  array<int, int>  $excludeVoucherIds
     * @param  list<int>|null  $branchIds
     * @return array<int, array{debit: Money, credit: Money}>
     */
    private function periodTotalsByAccount(FiscalYear $fiscalYear, array $excludeVoucherIds, ?string $from, ?string $to, ?array $branchIds = null): array
    {
        $cast = JournalVoucherLine::query()->getConnection()->getDriverName() === 'sqlite' ? 'INTEGER' : 'SIGNED';

        $rows = JournalVoucherLine::query()
            ->whereHas('journalVoucher', function (Builder $query) use ($fiscalYear, $excludeVoucherIds, $from, $to) {
                $query->where('fiscal_year_id', $fiscalYear->id)
                    ->when($excludeVoucherIds !== [], fn (Builder $q) => $q->whereNotIn('id', $excludeVoucherIds))
                    ->when($from !== null, fn (Builder $q) => $q->whereDate('date', '>=', $from))
                    ->when($to !== null, fn (Builder $q) => $q->whereDate('date', '<=', $to));
            })
            ->tap(fn (Builder $lines) => BranchFilter::narrowLines($lines, $branchIds))
            ->selectRaw(
                "account_id,
                 COALESCE(SUM(CAST(ROUND(debit * 100) AS {$cast})), 0) as debit_scaled,
                 COALESCE(SUM(CAST(ROUND(credit * 100) AS {$cast})), 0) as credit_scaled"
            )
            ->groupBy('account_id')
            ->get();

        $fromScaled = fn ($scaled) => Money::of(BigDecimal::of((int) $scaled)->dividedBy(100, 2, RoundingMode::Unnecessary));

        $totals = [];

        foreach ($rows as $row) {
            $totals[(int) $row->account_id] = [
                'debit' => $fromScaled($row->debit_scaled),
                'credit' => $fromScaled($row->credit_scaled),
            ];
        }

        return $totals;
    }

    /**
     * Ids of this fiscal year's Opening Balance vouchers. Trial Balance's
     * Period bucket must never include them (see openingBalancesByAccount()
     * below), matching accountBook()'s own `voucher_type != OpeningBalance`
     * period filter - otherwise the carried-forward balance would count
     * twice: once as Opening, once as Period.
     *
     * @return array<int, int>
     */
    private function openingVoucherIds(FiscalYear $fiscalYear): array
    {
        // Unrestricted: only identifies the vouchers to exclude.
        return BranchContext::unrestricted(fn (): array => JournalVoucher::query()
            ->where('fiscal_year_id', $fiscalYear->id)
            ->where('voucher_type', VoucherType::OpeningBalance->value)
            ->pluck('id')
            ->all());
    }

    /**
     * Trial Balance's Opening column: this year's Opening Balance voucher
     * (whatever date it is posted on) PLUS this year's lines dated strictly
     * before `$from`. This is the same rule accountBook() already uses (see
     * its docblock at ~line 1290) - not a plain `date <= dayBefore($from)`
     * comparison, which used to render Opening as 0.00 for the default view
     * of every year after the first: FiscalYear::postOpeningBalances() dates
     * the Opening Balance voucher at exactly $fiscalYear->start_date, and
     * resolveWindow() defaults $from to that same start_date, so
     * dayBefore($from) landed one day before the fiscal year even starts and
     * the carry-forward silently fell into the Period bucket instead
     * (audit T15-1).
     *
     * $excludeVoucherIds is the sweepVoucherIds() exclusion, orthogonal to
     * the OpeningBalance question - a ClosingEntry sweep voucher must never
     * appear in Opening either.
     *
     * @param  array<int, int>  $excludeVoucherIds
     * @param  list<int>|null  $branchIds  BranchFilter::lineBranchIds(); null adds no predicate.
     * @return array<int, Money>
     */
    private function openingBalancesByAccount(FiscalYear $fiscalYear, array $excludeVoucherIds, string $from, ?array $branchIds = null): array
    {
        $cast = JournalVoucherLine::query()->getConnection()->getDriverName() === 'sqlite' ? 'INTEGER' : 'SIGNED';

        $rows = JournalVoucherLine::query()
            ->whereHas('journalVoucher', function (Builder $query) use ($fiscalYear, $excludeVoucherIds, $from) {
                $query->where('fiscal_year_id', $fiscalYear->id)
                    ->when($excludeVoucherIds !== [], fn (Builder $q) => $q->whereNotIn('id', $excludeVoucherIds))
                    ->where(fn (Builder $q) => $q
                        ->where('voucher_type', VoucherType::OpeningBalance->value)
                        ->orWhereDate('date', '<', $from));
            })
            ->tap(fn (Builder $lines) => BranchFilter::narrowLines($lines, $branchIds))
            ->selectRaw(
                "account_id,
                 COALESCE(SUM(CAST(ROUND(debit * 100) AS {$cast})), 0) - COALESCE(SUM(CAST(ROUND(credit * 100) AS {$cast})), 0) as net_scaled"
            )
            ->groupBy('account_id')
            ->pluck('net_scaled', 'account_id');

        $balances = [];

        foreach ($rows as $accountId => $netScaled) {
            $balances[(int) $accountId] = Money::of(
                BigDecimal::of((int) $netScaled)->dividedBy(100, 2, RoundingMode::Unnecessary)
            );
        }

        return $balances;
    }

    /**
     * Unswept profit-and-loss for this year: every P&L account's balance
     * INCLUDING the closing entries. Zero for a cleanly closed year (that is
     * what the sweep achieved), the year's profit for an open one, and the
     * leftover effect of a correction for a reopened one.
     *
     * $branchIds narrows the lines (BranchFilter::lineBranchIds()), giving
     * the branch set's own unswept profit.
     *
     * @param  list<int>|null  $branchIds
     */
    private function unsweptProfitAndLoss(FiscalYear $fiscalYear, string $to, ?array $branchIds = null): Money
    {
        $balances = $this->balancesByAccount($fiscalYear, [], null, $to, $branchIds);

        $profitAndLossAccountIds = $this->profitAndLossAccountIds();

        $net = Money::zero();

        foreach ($profitAndLossAccountIds as $accountId) {
            $net = $net->plus($balances[(int) $accountId] ?? Money::zero());
        }

        // net is debit - credit across income and expenses, so profit is its
        // negation: income sits on the credit side.
        return $net->negated();
    }

    /**
     * @return Collection<int, mixed>
     */
    private function profitAndLossAccountIds(): Collection
    {
        return Account::query()
            ->whereHas('group.accountHead', fn (Builder $query) => $query->where('is_profit_and_loss', true))
            ->orWhereHas('subgroup.accountGroup.accountHead', fn (Builder $query) => $query->where('is_profit_and_loss', true))
            ->pluck('id');
    }

    /**
     * This year's opening and closing stock, and whether the ledger already
     * holds them.
     *
     * "Posted" means FiscalYear::close() has run its trading pair for this
     * year - detected by the presence of a ClosingEntry voucher touching
     * Stock in Hand, the same structural marker sweepVoucherIds() keys off.
     *
     * Not posted yet:
     * - opening stock is the Stock in Hand balance this year was opened
     *   with, which is every non-closing-entry line on that account (only
     *   the opening-balance carry-forward and a manual opening-stock import
     *   ever touch it, since inventory is periodic);
     * - closing stock is computed live by StockCosting as at the report
     *   date, which is exactly what close() will post.
     *
     * Trading stock only, in both branches: closing is
     * totalPeriodicClosingValue(), which leaves out Raw Material and
     * Finished Good items. Their value already sits in AS12/AS14 (and WIP in
     * AS13) as ordinary ledger balances that every statement reads directly,
     * so adding them here too would show the same stock twice (MC9).
     *
     * Branches (plan 12, BR13). $stockBranchIds null is the company, the
     * code path unchanged. Otherwise the ledger figures read only the lines
     * of $lineBranchIds (null when the access scope already narrows them to
     * exactly that set) and the computed closing stock is the set's own
     * stores' (BranchReportTotals, year-end convention), so a branch's stock
     * is never the company's. "Posted" stays the company-wide voucher fact:
     * close() writes every branch's pair inside the same two vouchers, so a
     * posted year with no pair for some branch means that branch's stock was
     * zero on both sides, which the branch-scoped Stock in Hand read then
     * gives correctly.
     *
     * @param  list<int>|null  $stockBranchIds  BranchFilter::effectiveIds()
     * @param  list<int>|null  $lineBranchIds  BranchFilter::lineBranchIds()
     * @return array{opening: string, closing: string, posted: bool, asOf: string}
     */
    private function stockPosition(FiscalYear $fiscalYear, string $to, ?array $stockBranchIds = null, ?array $lineBranchIds = null): array
    {
        $stockInHand = Account::where('code', FiscalYear::STOCK_IN_HAND_CODE)->first();

        if (! $stockInHand) {
            return ['opening' => '0.00', 'closing' => '0.00', 'posted' => false, 'asOf' => $to];
        }

        // Unrestricted: "has the year-end stock pair been posted" is a fact
        // about the company, not about the branches the viewer may see.
        $posted = BranchContext::unrestricted(fn (): bool => JournalVoucher::query()
            ->where('fiscal_year_id', $fiscalYear->id)
            ->where('voucher_type', VoucherType::ClosingEntry->value)
            ->whereHas('lines', fn (Builder $lines) => $lines->where('account_id', $stockInHand->id))
            ->exists());

        $excluded = $this->sweepVoucherIds($fiscalYear);
        $withoutSweep = $this->balancesByAccount($fiscalYear, $excluded, null, $fiscalYear->end_date->toDateString(), $lineBranchIds);

        if ($posted) {
            // After the pair, Stock in Hand nets to the posted closing value
            // for the year and the opening value is the trading debit that
            // moved out of it.
            $all = $this->balancesByAccount($fiscalYear, [], null, $fiscalYear->end_date->toDateString(), $lineBranchIds);
            $openingStockAccountId = Account::where('code', FiscalYear::OPENING_STOCK_CODE)->value('id');

            $opening = $openingStockAccountId === null
                ? Money::zero()
                : ($withoutSweep[(int) $openingStockAccountId] ?? Money::zero());

            return [
                'opening' => $opening->toString(),
                'closing' => ($all[$stockInHand->id] ?? Money::zero())->toString(),
                'posted' => true,
                'asOf' => $fiscalYear->end_date->toDateString(),
            ];
        }

        return [
            'opening' => ($withoutSweep[$stockInHand->id] ?? Money::zero())->toString(),
            'closing' => ($stockBranchIds === null
                ? StockCosting::totalPeriodicClosingValue($to)
                : BranchReportTotals::periodicClosingValue($to, $stockBranchIds))->toString(),
            'posted' => false,
            'asOf' => $to,
        ];
    }

    /**
     * Flat list of {account, head, group, subgroup, debit, credit} rows, one
     * per account within scope, optionally restricted to a set of head
     * names. Only one of debit/credit is ever nonzero: a positive net shows
     * as a debit balance, a negative one as a credit balance.
     *
     * Every account in scope is listed, including one with a zero balance
     * (audit T15-4) - legacy's Balance Sheet enumerates from the full chart
     * of accounts via a LEFT JOIN and only ever hides a zero row behind an
     * explicit "Hide Zero" toggle, so a brand-new ledger account with no
     * postings yet, or one that nets to exactly zero for the period, is
     * still real information (e.g. "this account exists and is currently
     * settled"), not something to silently drop.
     *
     * @param  array<int, Money>  $balances
     * @param  array<int, string>|null  $headNames
     * @return Collection<int, array{account: Account, headName: string, groupName: string, subgroupName: ?string, debit: Money, credit: Money}>
     */
    private function accountRows(array $balances, ?array $headNames = null): Collection
    {
        $accounts = Account::query()
            ->with(['group.accountHead', 'subgroup.accountGroup.accountHead'])
            // Accounts whose head cannot be resolved are kept too, as
            // "Unclassified", so their balance is visible instead of
            // silently missing from the report (flags G-08).
            ->when($headNames !== null, fn (Builder $query) => $query->where(fn (Builder $scope) => $scope
                ->whereHas('group.accountHead', fn (Builder $q) => $q->whereIn('name', $headNames))
                ->orWhereHas('subgroup.accountGroup.accountHead', fn (Builder $q) => $q->whereIn('name', $headNames))
                ->orWhere(fn (Builder $orphan) => $orphan
                    ->whereDoesntHave('group.accountHead')
                    ->whereDoesntHave('subgroup.accountGroup.accountHead'))))
            ->get();

        $rows = collect();

        foreach ($accounts as $account) {
            $net = $balances[$account->id] ?? Money::zero();

            $rows->push([
                'account' => $account,
                ...$this->classification($account),
                'debit' => $net->isPositive() ? $net : Money::zero(),
                'credit' => $net->isNegative() ? $net->negated() : Money::zero(),
            ]);
        }

        return $rows;
    }

    /**
     * Adds the not-yet-posted stock movement onto the Balance Sheet's Stock
     * in Hand row, creating the row when the account has no balance of its
     * own yet (a tenant's very first year).
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function withStockInHandRow(Collection $rows, Money $adjustment): Collection
    {
        $stockInHand = Account::with(['group.accountHead', 'subgroup.accountGroup.accountHead'])
            ->where('code', FiscalYear::STOCK_IN_HAND_CODE)
            ->first();

        if (! $stockInHand) {
            return $rows;
        }

        $index = $rows->search(fn (array $row) => $row['account']->id === $stockInHand->id);

        if ($index !== false) {
            $row = $rows[$index];
            $net = $row['debit']->minus($row['credit'])->plus($adjustment);
            $row['debit'] = $net->isPositive() ? $net : Money::zero();
            $row['credit'] = $net->isNegative() ? $net->negated() : Money::zero();

            return $rows->replace([$index => $row]);
        }

        return $rows->push([
            'account' => $stockInHand,
            ...$this->classification($stockInHand),
            'debit' => $adjustment->isPositive() ? $adjustment : Money::zero(),
            'credit' => $adjustment->isNegative() ? $adjustment->negated() : Money::zero(),
        ]);
    }

    /**
     * Every account in the chart of accounts, one row each - not just the
     * ones with a nonzero opening or period balance (audit T15-4). Legacy's
     * `trailbalance()` runs a LEFT JOIN from `mainaccount` (the full chart),
     * so a brand-new ledger account with no postings yet, or one whose
     * period activity happens to net to exactly zero (e.g. a customer fully
     * settled during the period), still shows a 0.00 row rather than
     * disappearing; legacy only ever hides it behind an explicit "Hide Zero"
     * toggle, which is not on by default and has no equivalent here yet.
     *
     * $accountId is legacy's `?accno=` single-ledger filter (audit T15-5):
     * when given, every other account is left out and the report narrows to
     * that one ledger's opening/period/closing figures.
     *
     * @param  array<int, Money>  $opening
     * @param  array<int, array{debit: Money, credit: Money}>  $period
     * @return Collection<int, array<string, mixed>>
     */
    private function trialBalanceRows(array $opening, array $period, ?int $accountId = null): Collection
    {
        $accounts = Account::query()
            ->with(['group.accountHead', 'subgroup.accountGroup.accountHead'])
            ->when($accountId !== null, fn (Builder $query) => $query->where('id', $accountId))
            ->get();

        $rows = collect();

        foreach ($accounts as $account) {
            $openingNet = $opening[$account->id] ?? Money::zero();
            $periodDebit = $period[$account->id]['debit'] ?? Money::zero();
            $periodCredit = $period[$account->id]['credit'] ?? Money::zero();
            $closingNet = $openingNet->plus($periodDebit)->minus($periodCredit);

            $rows->push([
                'account' => $account,
                ...$this->classification($account),
                'openingDebit' => $openingNet->isPositive() ? $openingNet : Money::zero(),
                'openingCredit' => $openingNet->isNegative() ? $openingNet->negated() : Money::zero(),
                'periodDebit' => $periodDebit,
                'periodCredit' => $periodCredit,
                'closingDebit' => $closingNet->isPositive() ? $closingNet : Money::zero(),
                'closingCredit' => $closingNet->isNegative() ? $closingNet->negated() : Money::zero(),
                // The two columns the old report showed, kept so the page's
                // "Debit / Credit" pair still means the closing position.
                'debit' => $closingNet->isPositive() ? $closingNet : Money::zero(),
                'credit' => $closingNet->isNegative() ? $closingNet->negated() : Money::zero(),
            ]);
        }

        return $rows;
    }

    /**
     * @return array<string, string|bool>
     */
    private function emptyTrialBalanceTotals(): array
    {
        return [
            'totalOpeningDebit' => '0.00',
            'totalOpeningCredit' => '0.00',
            'totalPeriodDebit' => '0.00',
            'totalPeriodCredit' => '0.00',
            'totalDebit' => '0.00',
            'totalCredit' => '0.00',
            'inBalance' => true,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, string|bool>
     */
    private function trialBalanceTotals(Collection $rows): array
    {
        $sum = fn (string $key) => Money::sum($rows->pluck($key));

        $closingDebit = $sum('closingDebit');
        $closingCredit = $sum('closingCredit');

        return [
            'totalOpeningDebit' => $sum('openingDebit')->toString(),
            'totalOpeningCredit' => $sum('openingCredit')->toString(),
            'totalPeriodDebit' => $sum('periodDebit')->toString(),
            'totalPeriodCredit' => $sum('periodCredit')->toString(),
            'totalDebit' => $closingDebit->toString(),
            'totalCredit' => $closingCredit->toString(),
            'inBalance' => $closingDebit->isEqualTo($closingCredit),
        ];
    }

    /**
     * $keepAccountIds keeps those accounts even at a zero company amount (the
     * by-branch view, where some branch is not zero).
     *
     * @param  array<int, Money>  $balances
     * @param  list<int>  $keepAccountIds
     * @return array<int, array{id: ?int, code: ?string, name: string, amount: string, computed: bool}>
     */
    private function headBalances(string $headName, array $balances, bool $creditNormal, array $keepAccountIds = []): array
    {
        $head = AccountHead::where('name', $headName)->first();

        if (! $head) {
            return [];
        }

        $accounts = Account::query()
            ->whereHas('group', fn (Builder $query) => $query->where('account_head_id', $head->id))
            ->orWhereHas('subgroup.accountGroup', fn (Builder $query) => $query->where('account_head_id', $head->id))
            ->get();

        $rows = [];

        foreach ($accounts as $account) {
            $net = $balances[$account->id] ?? Money::zero();
            $amount = $creditNormal ? $net->negated() : $net;

            if ($amount->isZero() && ! in_array($account->id, $keepAccountIds, true)) {
                continue;
            }

            $rows[] = [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'amount' => $amount->toString(),
                'computed' => false,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<int, array{id: ?int, code: ?string, name: string, amount: string, computed: bool}>  $rows
     * @return array<int, array{id: ?int, code: ?string, name: string, amount: string, computed: bool}>
     */
    private function withVirtualRow(array $rows, string $code, string $name, string $amount, bool $keepZero = false): array
    {
        if (Money::of($amount)->isZero() && ! $keepZero) {
            return $rows;
        }

        $rows[] = [
            'id' => null,
            'code' => $code,
            'name' => $name,
            'amount' => Money::of($amount)->toString(),
            'computed' => true,
        ];

        return $rows;
    }

    /**
     * Trading-account gross profit: sales plus closing stock, less purchases
     * and opening stock. Only the two trading groups take part - indirect
     * income and indirect expenses belong below the gross profit line.
     *
     * @param  array<int, array{code: ?string, amount: string}>  $income
     * @param  array<int, array{code: ?string, amount: string}>  $expenses
     * @param  array{sales: list<string>, purchases: list<string>}|null  $tradingCodes  tradingCodes(), read once by a caller computing many columns.
     */
    private function grossProfit(array $income, array $expenses, ?array $tradingCodes = null): Money
    {
        $tradingCodes ??= $this->tradingCodes();
        $salesCodes = $tradingCodes['sales'];
        $purchaseCodes = $tradingCodes['purchases'];

        $total = function (array $rows, array $codes): Money {
            $matching = array_filter($rows, fn (array $row) => $row['code'] !== null && in_array($row['code'], $codes, true));

            return Money::sum(array_column($matching, 'amount'));
        };

        return $total($income, $salesCodes)->minus($total($expenses, $purchaseCodes));
    }

    /**
     * Account codes on each side of the trading account: sales plus closing
     * stock, purchases plus opening stock.
     *
     * @return array{sales: list<string>, purchases: list<string>}
     */
    private function tradingCodes(): array
    {
        $codesOf = fn (string $groupName) => Account::query()
            ->whereHas('group', fn (Builder $query) => $query->where('name', $groupName))
            ->pluck('code')
            ->filter()
            ->all();

        return [
            'sales' => [...$codesOf('Sales Accounts'), FiscalYear::CLOSING_STOCK_CODE],
            'purchases' => [...$codesOf('Purchase Accounts'), FiscalYear::OPENING_STOCK_CODE],
        ];
    }

    /**
     * Assets must equal Liabilities + Capital, always. A mismatch is a real
     * bookkeeping defect (a half-posted close, a correction that never got
     * swept), so it fails loudly under test and shows the operator a visible
     * warning row in production rather than a silently wrong statement.
     */
    private function assertBalanced(FiscalYear $fiscalYear, Money $assets, Money $liabilitiesAndCapital): ?string
    {
        if ($assets->isEqualTo($liabilitiesAndCapital)) {
            return null;
        }

        $difference = $assets->minus($liabilitiesAndCapital);

        $message = "This balance sheet does not balance: assets {$assets->toString()} against liabilities and capital {$liabilitiesAndCapital->toString()}, a difference of {$difference->toString()}. Check \"{$fiscalYear->name}\" for a closing entry that did not complete.";

        if (app()->runningUnitTests()) {
            throw new RuntimeException($message);
        }

        return $message;
    }

    /**
     * @return array{entries: array<int, array<string, mixed>>, openingBalance: string, closingBalance: string}
     */
    private function emptyAccountBook(): array
    {
        return ['entries' => [], 'openingBalance' => '0.00', 'closingBalance' => '0.00'];
    }

    /**
     * A single account's running book over a window INSIDE one fiscal year.
     *
     * Opening balance = that year's Opening Balance voucher lines, plus that
     * year's lines dated before `$from`. Nothing is ever summed across a
     * year boundary: the old all-time cumulative opening counted the new
     * year's Opening Balance voucher on top of the previous year's own
     * lines, so a cash balance of 600 at the end of FY1 opened FY2 at 1,200
     * (audit P0-18).
     *
     * The two halves are deliberately disjoint - the entries list leaves out
     * Opening Balance vouchers entirely - so a window starting on the year's
     * first day cannot count the carry-forward twice.
     *
     * $branchIds (BranchFilter::lineBranchIds()) narrows both halves to the
     * lines of those branches: a branch's cash book is "what was posted
     * under that branch" (plan 12, section 5.4). Null adds nothing.
     *
     * @param  list<int>|null  $branchIds
     * @return array{entries: array<int, array<string, mixed>>, openingBalance: string, closingBalance: string}
     */
    private function accountBook(Account $account, FiscalYear $fiscalYear, string $from, string $to, ?array $branchIds = null): array
    {
        $cast = JournalVoucherLine::query()->getConnection()->getDriverName() === 'sqlite' ? 'INTEGER' : 'SIGNED';

        $openingScaled = JournalVoucherLine::query()
            ->where('account_id', $account->id)
            ->whereHas('journalVoucher', function (Builder $query) use ($fiscalYear, $from) {
                $query->where('fiscal_year_id', $fiscalYear->id)
                    ->where(fn (Builder $q) => $q
                        ->where('voucher_type', VoucherType::OpeningBalance->value)
                        ->orWhereDate('date', '<', $from));
            })
            ->tap(fn (Builder $lines) => BranchFilter::narrowLines($lines, $branchIds))
            ->selectRaw(
                "COALESCE(SUM(CAST(ROUND(debit * 100) AS {$cast})), 0) - COALESCE(SUM(CAST(ROUND(credit * 100) AS {$cast})), 0) as net_scaled"
            )
            ->value('net_scaled');

        $openingBalance = Money::of(BigDecimal::of((int) $openingScaled)->dividedBy(100, 2, RoundingMode::Unnecessary));

        $lines = JournalVoucherLine::query()
            ->where('account_id', $account->id)
            ->whereHas('journalVoucher', function (Builder $query) use ($fiscalYear, $from, $to) {
                $query->where('fiscal_year_id', $fiscalYear->id)
                    ->where('voucher_type', '!=', VoucherType::OpeningBalance->value)
                    ->whereDate('date', '>=', $from)
                    ->whereDate('date', '<=', $to);
            })
            ->tap(fn (Builder $lines) => BranchFilter::narrowLines($lines, $branchIds))
            ->with('journalVoucher')
            ->get()
            ->sortBy([['journalVoucher.date', 'asc'], ['id', 'asc']])
            ->values();

        JournalVoucher::loadSeriesBranches($lines->pluck('journalVoucher'));

        $runningBalance = $openingBalance;

        $entries = $lines->map(function (JournalVoucherLine $line) use (&$runningBalance) {
            $debit = Money::of($line->debit);
            $credit = Money::of($line->credit);
            $runningBalance = $runningBalance->plus($debit)->minus($credit);

            return [
                'date' => $line->journalVoucher->date->toDateString(),
                'voucherType' => $line->journalVoucher->voucher_type->value,
                'voucherNumber' => $line->journalVoucher->displayNumber(),
                'narration' => $line->narration ?? $line->journalVoucher->narration,
                'debit' => $debit->toString(),
                'credit' => $credit->toString(),
                'balance' => $runningBalance->toString(),
            ];
        })->values()->all();

        return [
            'entries' => $entries,
            // Money never renders "-0.00" (CONTRACTS C1), so a zero balance
            // always prints as 0.00 whichever side it arrived from.
            'openingBalance' => $openingBalance->toString(),
            'closingBalance' => $runningBalance->toString(),
        ];
    }

    /**
     * Where an account sits in the report tree. An account whose group or
     * head cannot be resolved (a deleted group, a broken import) goes under
     * "Unclassified" rather than being dropped: the trial balance used to
     * `continue` past it, so its balance vanished and the totals no longer
     * matched the ledger with nothing on screen to say why (flags G-08).
     *
     * @return array{headName: string, groupName: string, subgroupName: ?string}
     */
    private function classification(Account $account): array
    {
        $head = $account->group?->accountHead ?? $account->subgroup?->accountGroup?->accountHead;
        $group = $account->group ?? $account->subgroup?->accountGroup;

        if (! $head || ! $group) {
            return ['headName' => self::UNCLASSIFIED, 'groupName' => self::UNCLASSIFIED, 'subgroupName' => null];
        }

        return ['headName' => $head->name, 'groupName' => $group->name, 'subgroupName' => $account->subgroup?->name];
    }

    /**
     * How many report rows sit under "Unclassified", for the page's warning.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    private function unclassifiedCount(Collection $rows): int
    {
        return $rows->where('headName', self::UNCLASSIFIED)->count();
    }

    /**
     * Nests flat account rows into head -> group -> (subgroup, optional) ->
     * accounts, for the hierarchical Trial Balance / Balance Sheet pages.
     * Every Money is rendered to its exact string here, at the last possible
     * moment before the props leave the controller.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<int, array{name: string, groups: array<int, array{name: string, accounts: array, subgroups: array}>}>
     */
    private function buildHierarchy(Collection $rows): array
    {
        $heads = [];
        $moneyKeys = [
            'debit', 'credit',
            'openingDebit', 'openingCredit', 'periodDebit', 'periodCredit', 'closingDebit', 'closingCredit',
        ];

        foreach ($rows as $row) {
            $account = $row['account'];
            $accountRow = [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
            ];

            foreach ($moneyKeys as $key) {
                if (isset($row[$key])) {
                    $accountRow[$key] = $row[$key]->toString();
                }
            }

            // Only in the by-branch view: branch id => decimal string.
            if (isset($row['by_branch'])) {
                $accountRow['by_branch'] = array_map(fn (Money $amount): string => $amount->toString(), $row['by_branch']);
            }

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
