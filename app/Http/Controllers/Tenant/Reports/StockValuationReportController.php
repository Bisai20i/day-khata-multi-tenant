<?php

namespace App\Http\Controllers\Tenant\Reports;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Support\AsOfDate;
use App\Support\Branches\BranchFilter;
use App\Support\Branches\BranchStock;
use App\Support\Money\Money;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

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
        $asOf = $this->resolveAsOf($request);
        $filter = BranchFilter::fromRequest($request);
        $storeId = $filter->allowedStoreId($request->integer('store_id') ?: null);
        $stockStatus = $this->resolveStockStatus($request);

        // By-branch columns only for the branch view: one store is one
        // branch already.
        $withBranches = $filter->byBranch() && $storeId === null;
        $columns = $withBranches ? BranchStock::columns($filter) : null;

        $valuationRows = BranchStock::valuationRows($filter, $asOf, $storeId, ['stock_status' => $stockStatus], $withBranches);

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

        return Inertia::render('Tenant/Reports/StockValuation', [
            'asOf' => $asOf,
            'rows' => $rows,
            'grandTotalValuation' => Money::sum($rows->map(fn (array $row) => Money::of($row['valuation'])))->toString(),
            'stores' => BranchStock::storeOptions($filter, Store::where('is_active', true))->orderBy('name')->get(['id', 'name']),
            'storeId' => $storeId,
            'stockStatus' => $stockStatus,
            'branchReport' => $branchReport,
        ]);
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
     * who has not touched the new filter. Any other value falls back to
     * `'all'` rather than erroring on a stray query string.
     */
    private function resolveStockStatus(Request $request): string
    {
        $status = $request->string('stock_status')->toString();

        return in_array($status, ['positive', 'negative'], true) ? $status : 'all';
    }
}
