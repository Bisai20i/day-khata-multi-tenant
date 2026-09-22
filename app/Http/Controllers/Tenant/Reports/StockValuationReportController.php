<?php

namespace App\Http\Controllers\Tenant\Reports;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Support\Inventory\StockCosting;
use App\Support\Money\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
 */
class StockValuationReportController extends Controller
{
    public function index(Request $request): Response
    {
        $asOf = $this->resolveAsOf($request);
        $storeId = $request->integer('store_id') ?: null;
        $stockStatus = $this->resolveStockStatus($request);

        $rows = StockCosting::valuationRows($asOf, $storeId, ['stock_status' => $stockStatus])
            ->map(fn (array $row) => [
                'itemId' => $row['item_id'],
                'name' => $row['name'],
                'unit' => $row['unit'],
                'hsCode' => $row['hs_code'],
                'quantity' => $row['quantity']->toString(),
                'avgCost' => $row['average_cost'],
                'valuation' => $row['value']->toString(),
            ])
            // Exact Money comparison, never a float sort key: two valuations
            // a paisa apart must order deterministically.
            ->sort(fn (array $a, array $b) => Money::of($b['valuation'])->compareTo(Money::of($a['valuation'])))
            ->values();

        return Inertia::render('Tenant/Reports/StockValuation', [
            'asOf' => $asOf,
            'rows' => $rows,
            'grandTotalValuation' => Money::sum($rows->map(fn (array $row) => Money::of($row['valuation'])))->toString(),
            'stores' => Store::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'storeId' => $storeId,
            'stockStatus' => $stockStatus,
        ]);
    }

    private function resolveAsOf(Request $request): string
    {
        $asOf = $request->string('as_of')->toString();

        return $asOf !== '' ? Carbon::parse($asOf)->toDateString() : Carbon::now()->toDateString();
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
