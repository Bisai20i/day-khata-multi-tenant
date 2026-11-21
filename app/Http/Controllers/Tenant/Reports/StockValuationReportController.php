<?php

namespace App\Http\Controllers\Tenant\Reports;

use App\Exports\StockValuationExport;
use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Support\AsOfDate;
use App\Support\Branches\BranchFilter;
use App\Support\Branches\BranchStock;
use App\Support\Money\Money;
use App\Support\Reports\StockItemFilters;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Point-in-time stock valuation snapshot: on-hand quantity and value per
 * stockable item as of one date, sorted by value descending.
 *
 * Every figure comes from App\Support\Inventory\StockCosting (CONTRACTS
 * C10), which is the one place stock is valued. This controller used to run
 * its own weighted average off ItemStockMovement - rounding the average to
 * 4 decimals and only then multiplying, which the audit measured valuing
 * 100,000 units at Rs 1 plus 200,000 at Rs 2 as Rs 500,010.00 instead of Rs
 * 500,000.00 (P0-17) - and it counted transfer-in rows as fresh purchases,
 * so moving stock between your own stores inflated the valuation. Both the
 * Balance Sheet and the year-end closing entry read StockCosting too, so
 * this screen now agrees with the books by construction rather than by
 * coincidence.
 *
 * Branches (plan 12, BR15): `branch` / `branches[]` narrow the report to
 * the covered branches' stores, valued by StockCosting::
 * branchValuationRows() (the year-end convention, so a branch's trading
 * stock equals its statements' closing stock); `by_branch=1` adds each
 * row's quantity and value per branch (`byBranch`, keyed by branch id) and
 * `branchReport.columns` / `branchReport.totals` (value per branch). A
 * `store_id` outside the covered branches is dropped. Not multi-branch:
 * the same queries and payload as before, plus `branchReport: null`.
 */
class StockValuationReportController extends Controller
{
    public function index(Request $request): Response
    {
        $report = $this->report($request);
        $filter = $report['filter'];
        $filters = $report['filters'];

        return Inertia::render('Tenant/Reports/StockValuation', [
            'asOf' => $report['asOf'],
            'rows' => $report['rows'],
            'grandTotalValuation' => $report['grandTotalValuation'],
            'stores' => BranchStock::storeOptions($filter, Store::where('is_active', true))->orderBy('name')->get(['id', 'name']),
            'storeId' => $report['storeId'],
            'stockStatus' => $report['stockStatus'],
            'includeEmpty' => $report['includeEmpty'],
            'itemCategoryId' => $filters['item_category_id'] ?? null,
            'itemSubcategoryId' => $filters['item_subcategory_id'] ?? null,
            'brandId' => $filters['brand_id'] ?? null,
            'search' => $filters['search'] ?? '',
            'filterOptions' => StockItemFilters::options(),
            'branchReport' => $report['branchReport'],
        ]);
    }

    /**
     * Same rows and totals as index() for the same query string, streamed as
     * a real .xlsx.
     */
    public function export(Request $request)
    {
        $report = $this->report($request);
        $filter = $report['filter'];
        $branchReport = $report['branchReport'];

        return Excel::download(
            new StockValuationExport(
                $report['rows']->all(),
                $report['grandTotalValuation'],
                $report['asOf'],
                $filter->isActive() ? $filter->label() : null,
                $branchReport['columns'] ?? [],
                $branchReport['totals'] ?? [],
            ),
            "stock-valuation-{$report['asOf']}.xlsx",
        );
    }

    /**
     * The one place the report is built, so the page and the Excel download
     * can never disagree.
     *
     * @return array{asOf: string, rows: Collection<int, array<string, mixed>>, grandTotalValuation: string, filter: BranchFilter, storeId: int|null, stockStatus: string, includeEmpty: bool, filters: array<string, int|string>, branchReport: array<string, mixed>|null}
     */
    private function report(Request $request): array
    {
        // Strict Y-m-d: a malformed date is a validation error the page shows
        // rather than a silent fall back to today. An absent as_of still
        // means today (AsOfDate).
        $request->validate([
            'as_of' => ['nullable', 'date_format:Y-m-d'],
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'stock_status' => ['nullable', Rule::in(['all', 'positive', 'negative'])],
            'include_empty' => ['nullable', 'boolean'],
            ...StockItemFilters::rules(),
        ]);

        $asOf = $this->resolveAsOf($request);
        $filter = BranchFilter::fromRequest($request);
        $storeId = $filter->allowedStoreId($request->integer('store_id') ?: null);
        $stockStatus = $this->resolveStockStatus($request);
        $includeEmpty = $request->boolean('include_empty');
        $filters = StockItemFilters::fromRequest($request);

        // By-branch columns only for the branch view: one store is one
        // branch already.
        $withBranches = $filter->byBranch() && $storeId === null;
        $columns = $withBranches ? BranchStock::columns($filter) : null;

        $valuationRows = BranchStock::valuationRows(
            $filter,
            $asOf,
            $storeId,
            [...$filters, 'stock_status' => $stockStatus, 'include_empty' => $includeEmpty],
            $withBranches,
        );

        $rows = $valuationRows
            ->map(fn (array $row) => [
                'itemId' => $row['item_id'],
                'name' => $row['name'],
                'unit' => $row['unit'],
                'hsCode' => $row['hs_code'],
                'quantity' => $row['quantity']->toString(),
                'avgCost' => $row['average_cost'],
                'valuation' => $row['value']->toString(),
                ...($columns === null ? [] : ['byBranch' => BranchStock::presentRow($row['by_branch'] ?? [], $columns)]),
            ])
            // Exact Money comparison, never a float sort key: two valuations
            // a paisa apart must order deterministically.
            ->sort(fn (array $a, array $b) => Money::of($b['valuation'])->compareTo(Money::of($a['valuation'])))
            ->values();

        $branchReport = $filter->payload(supportsByBranch: $storeId === null);

        if ($branchReport !== null && $columns !== null) {
            $branchReport = [...$branchReport, 'columns' => $columns, 'totals' => BranchStock::valueTotals($valuationRows, $columns)];
        }

        return [
            'asOf' => $asOf,
            'rows' => $rows,
            'grandTotalValuation' => Money::sum($rows->map(fn (array $row) => Money::of($row['valuation'])))->toString(),
            'filter' => $filter,
            'storeId' => $storeId,
            'stockStatus' => $stockStatus,
            'includeEmpty' => $includeEmpty,
            'filters' => $filters,
            'branchReport' => $branchReport,
        ];
    }

    private function resolveAsOf(Request $request): string
    {
        return AsOfDate::resolve($request->input('as_of'));
    }

    /**
     * Mirrors legacy's `stockValuationReport()` `stock_status` param
     * (`positive` / `negative` / default `<> 0`), which real users used to
     * hunt down negative-stock data-entry errors. `'all'` is the default
     * here and applies no sign filter, preserving the report's existing
     * behaviour (every non-zero-quantity-and-non-zero-value row) for anyone
     * who has not touched the new filter. report() validates the value
     * (all / positive / negative), so anything else is a 422, not a silent
     * fallback.
     */
    private function resolveStockStatus(Request $request): string
    {
        $status = $request->string('stock_status')->toString();

        return in_array($status, ['positive', 'negative'], true) ? $status : 'all';
    }
}
