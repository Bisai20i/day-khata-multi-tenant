<?php

namespace App\Http\Controllers\Tenant\Reports;

use App\Exports\BranchComparisonExport;
use App\Http\Controllers\Controller;
use App\Models\FiscalYear;
use App\Support\Branches\ActiveBranchSelection;
use App\Support\Branches\BranchAccounts;
use App\Support\Branches\BranchFilter;
use App\Support\Money\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Branch comparison (plan 12, section 5.9, BR16): one row per branch with
 * its sales, purchases, gross profit, expenses and net profit for a fiscal
 * year window. A management report: shared costs sit in the branch they were
 * posted to until an allocation journal moves them.
 *
 * **Same numbers as the income statement.** Every figure comes from
 * AccountingReportController::branchStatements(), i.e. the income statement's
 * own `by_branch` columns (ledger lines grouped by branch in one query, plus
 * each branch's own stockPositionsByBranch()). So a branch's net profit here
 * is its "Net Profit" column on the income statement with "Show by branch"
 * ticked, and its gross profit is that column's gross profit, to the paisa.
 * Nothing is recomputed from documents.
 *
 * **Definitions** (the income statement's trading account, grossProfit()):
 *
 * - Sales: the Income rows of the "Sales Accounts" group except Closing Stock
 *   (INI22) and Stock Transferred to Branches (INI23), net: Sales Account less
 *   Sales Return, plus any custom account the company added to that group.
 * - Purchases: the Expense rows of the "Purchase Accounts" group except
 *   Opening Stock (EXE9) and Stock Received from Branches (EXE11), net:
 *   Purchases Account less Purchase Return, plus Cost of Goods Sold (EXE10,
 *   manufactured goods sold) and any custom account in that group.
 * - Net stock transfers: Stock Transferred (INI23) less Stock Received
 *   (EXE11). Inter-branch stock transfers are neither a sale of the sending
 *   branch nor a purchase of the receiving one, so they are kept out of
 *   Sales and Purchases and shown on their own. Company-wide they cancel to
 *   zero.
 * - Stock change: Closing Stock less Opening Stock (trading items).
 * - Gross profit: Sales + Net stock transfers + Stock change - Purchases
 *   (exactly grossProfit(), which includes INI23 and EXE11 in its totals).
 * - Other income: Total income less the trading income rows.
 * - Expenses: Total expenses less the trading expense rows (the indirect
 *   expenses below the gross profit line).
 * - Net profit: Total income - Total expenses
 *   (= Gross profit + Other income - Expenses).
 * - Net margin: Net profit / Sales x 100, one decimal, exact BigDecimal
 *   division; null when sales are not positive.
 *
 * **Rows.** Without an explicit choice the report covers every branch the
 * user may see, not the top-bar switcher's one branch: a comparison of a
 * single branch compares nothing. `branches[]` narrows it (intersected with
 * the user's branches by BranchFilter, never widened). Unnarrowed, the rows
 * are the income statement's columns (every active branch plus inactive
 * ones with figures) minus inactive branches whose every figure is zero;
 * Main is listed first.
 *
 * **Totals.** The sum of the rows, and only for a user who may see every
 * branch: a user limited to some branches never sees a company-wide figure,
 * the page tells them totals are limited instead.
 */
class BranchComparisonReportController extends Controller
{
    /**
     * Figure keys of a row, in display (and Excel column) order.
     *
     * @var list<string>
     */
    public const FIGURES = ['sales', 'purchases', 'netTransfers', 'stockChange', 'grossProfit', 'otherIncome', 'expenses', 'netProfit'];

    public function __construct(private readonly AccountingReportController $statements) {}

    public function index(Request $request): Response
    {
        $report = $this->report($request);

        return Inertia::render('Tenant/Reports/BranchComparison', [
            'fiscalYears' => $report['fiscalYears'],
            'fiscalYearId' => $report['fiscalYear']?->id,
            'from' => $report['from'],
            'to' => $report['to'],
            'rows' => $report['rows'],
            'totals' => $report['totals'],
            'totalsScope' => $report['totalsScope'],
            'branchReport' => $report['branchReport'],
        ]);
    }

    public function export(Request $request)
    {
        $report = $this->report($request);
        abort_if($report['fiscalYear'] === null, 404, 'No fiscal year to report on.');
        abort_if($report['branchReport'] === null, 404, 'The branch comparison needs more than one branch.');

        $fiscalYear = $report['fiscalYear'];

        return Excel::download(
            new BranchComparisonExport(
                $report['rows'],
                $report['totals'],
                $report['branchReport']['label'],
                "{$fiscalYear->name}: {$report['from']} to {$report['to']}",
                ActiveBranchSelection::label(),
            ),
            "branch-comparison-{$fiscalYear->name}.xlsx",
        );
    }

    /**
     * Shared by the page and the Excel export, so both read one computation.
     *
     * @return array{fiscalYears: mixed, fiscalYear: FiscalYear|null, from: string|null, to: string|null, rows: list<array<string, mixed>>, totals: array<string, string|null>|null, totalsScope: string|null, branchReport: array<string, mixed>|null}
     */
    private function report(Request $request): array
    {
        // A comparison defaults to every branch the user may see, whatever
        // the top-bar switcher says (see the class docblock).
        if (! $request->has('branches') && ! $request->has('branch')) {
            $request->merge(['branches' => [ActiveBranchSelection::ALL]]);
        }

        $filter = BranchFilter::fromRequest($request);

        $empty = [
            'fiscalYears' => [],
            'fiscalYear' => null,
            'from' => null,
            'to' => null,
            'rows' => [],
            'totals' => null,
            'totalsScope' => null,
            'branchReport' => null,
        ];

        // Not multi-branch (module off, or only Main): nothing to compare.
        if (! $filter->isActive()) {
            return $empty;
        }

        $branchReport = [...$filter->payload(), 'management' => true];
        $data = $this->statements->branchStatements($request, $filter);
        $statement = $data['statement'];

        if ($statement === null) {
            return [...$empty, 'fiscalYears' => $data['fiscalYears'], 'branchReport' => $branchReport];
        }

        $rows = $this->rows($statement, $data['tradingCodes'], $filter);
        $restricted = $branchReport['restricted'];

        return [
            'fiscalYears' => $data['fiscalYears'],
            'fiscalYear' => $data['fiscalYear'],
            'from' => $statement['from'],
            'to' => $statement['to'],
            'rows' => $rows,
            'totals' => $restricted || $rows === [] ? null : $this->totals($rows),
            'totalsScope' => $restricted ? null : ($filter->isNarrowed() ? 'selected' : 'company'),
            'branchReport' => $branchReport,
        ];
    }

    /**
     * One row per branch column of the income statement, Main first.
     *
     * @param  array<string, mixed>  $statement  AccountingReportController::incomeStatementData() with branch columns
     * @param  array{sales: list<string>, purchases: list<string>}  $tradingCodes
     * @return list<array<string, mixed>>
     */
    private function rows(array $statement, array $tradingCodes, BranchFilter $filter): array
    {
        $branches = [];

        foreach ($filter->options() as $option) {
            $branches[$option['id']] = $option;
        }

        $salesCodes = array_values(array_diff($tradingCodes['sales'], [FiscalYear::CLOSING_STOCK_CODE, BranchAccounts::STOCK_TRANSFERRED_CODE]));
        $purchaseCodes = array_values(array_diff($tradingCodes['purchases'], [FiscalYear::OPENING_STOCK_CODE, BranchAccounts::STOCK_RECEIVED_CODE]));

        $rows = [];

        foreach ($statement['columns'] ?? [] as $column) {
            $branchId = $column['id'];
            $branch = $branches[$branchId] ?? ['is_main' => false, 'is_active' => true];
            $totals = $statement['branchTotals'][$branchId];

            $sum = fn (array $statementRows, array $codes): Money => Money::sum(array_map(
                fn (array $row): string => $row['by_branch'][$branchId],
                array_filter($statementRows, fn (array $row): bool => $row['code'] !== null && in_array($row['code'], $codes, true)),
            ));

            $sales = $sum($statement['income'], $salesCodes);
            $closingStock = $sum($statement['income'], [FiscalYear::CLOSING_STOCK_CODE]);
            $purchases = $sum($statement['expenses'], $purchaseCodes);
            $openingStock = $sum($statement['expenses'], [FiscalYear::OPENING_STOCK_CODE]);
            $stockTransferred = $sum($statement['income'], [BranchAccounts::STOCK_TRANSFERRED_CODE]);
            $stockReceived = $sum($statement['expenses'], [BranchAccounts::STOCK_RECEIVED_CODE]);

            $tradingIncome = $sales->plus($closingStock)->plus($stockTransferred);
            $tradingExpenses = $purchases->plus($openingStock)->plus($stockReceived);

            $figures = [
                'sales' => $sales,
                'purchases' => $purchases,
                'netTransfers' => $stockTransferred->minus($stockReceived),
                'stockChange' => $closingStock->minus($openingStock),
                // The income statement's own column figures, never re-derived.
                'grossProfit' => Money::of($totals['grossProfit']),
                'otherIncome' => Money::of($totals['totalIncome'])->minus($tradingIncome),
                'expenses' => Money::of($totals['totalExpenses'])->minus($tradingExpenses),
                'netProfit' => Money::of($totals['netProfit']),
            ];

            $isActive = (bool) $branch['is_active'];

            if (! $isActive && array_filter($figures, fn (Money $figure): bool => ! $figure->isZero()) === []) {
                continue;
            }

            $rows[] = [
                'id' => $branchId,
                'name' => $column['name'],
                'code' => $column['code'],
                'isMain' => (bool) $branch['is_main'],
                'isActive' => $isActive,
                ...array_map(fn (Money $figure): string => $figure->toString(), $figures),
                'margin' => $this->margin($figures['netProfit'], $figures['sales']),
            ];
        }

        // Stable: keeps the branches' own order after Main.
        usort($rows, fn (array $left, array $right): int => (int) $right['isMain'] <=> (int) $left['isMain']);

        return $this->withBars($rows);
    }

    /**
     * Sum of the rows, figure by figure, with the margin recomputed from the
     * summed figures (never an average of margins).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, string|null>
     */
    private function totals(array $rows): array
    {
        $totals = [];

        foreach (self::FIGURES as $key) {
            $totals[$key] = Money::sum(array_column($rows, $key))->toString();
        }

        $totals['margin'] = $this->margin(Money::of($totals['netProfit']), Money::of($totals['sales']));

        return $totals;
    }

    /**
     * Net profit as a percentage of sales, one decimal ("12.5"), exact
     * decimal division; null when there are no positive sales to divide by.
     */
    private function margin(Money $netProfit, Money $sales): ?string
    {
        if (! $sales->isPositive()) {
            return null;
        }

        return $netProfit->toBigDecimal()
            ->multipliedBy(100)
            ->dividedBy($sales->toBigDecimal(), 1, RoundingMode::HalfUp)
            ->toString();
    }

    /**
     * Adds `bar`: the row's absolute net profit as a whole percentage of the
     * largest absolute net profit (0 to 100), for the page's CSS bars. Done
     * here with exact decimals so the page never does arithmetic on money.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function withBars(array $rows): array
    {
        $largest = BigDecimal::zero();

        foreach ($rows as $row) {
            $largest = BigDecimal::max($largest, Money::of($row['netProfit'])->abs()->toBigDecimal());
        }

        return array_map(function (array $row) use ($largest): array {
            $bar = $largest->isZero()
                ? 0
                : Money::of($row['netProfit'])->abs()->toBigDecimal()
                    ->multipliedBy(100)
                    ->dividedBy($largest, 0, RoundingMode::HalfUp)
                    ->toInt();

            return [...$row, 'bar' => $bar];
        }, $rows);
    }
}
