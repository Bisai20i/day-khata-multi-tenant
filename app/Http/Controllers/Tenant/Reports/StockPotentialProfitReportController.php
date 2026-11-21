<?php

namespace App\Http\Controllers\Tenant\Reports;

use App\Exports\StockPotentialProfitExport;
use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\Store;
use App\Support\AsOfDate;
use App\Support\Branches\BranchFilter;
use App\Support\Branches\BranchStock;
use App\Support\Money\Money;
use App\Support\Money\Quantity;
use App\Support\Reports\StockItemFilters;
use Brick\Math\RoundingMode;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Stock with potential profit (legacy `newStockReportSearch` /
 * `stockValuationReport`): every stockable item's on-hand quantity valued at
 * cost and at its selling rate, and the difference.
 *
 * Cost is StockCosting's weighted average (CONTRACTS C10) through the same
 * BranchStock::valuationRows() call StockValuationReportController makes,
 * so for the same date, store, branch and item filters (stock status "All")
 * the cost total here equals the Stock Valuation total by construction.
 * Legacy used the latest buy rate; this report deliberately does not.
 *
 * The selling rate is the item's own `sale_rate` (per base unit, before
 * VAT: the rate a sale line starts with). An item with no selling rate (null
 * or zero) gets no sell value, profit or margin rather than a zero rate, is
 * left out of the sell-value and profit totals, and is counted in
 * `unpricedCount`. Sell value is quantity x rate rounded once with
 * Money::round(); profit is sell value less cost value, both exact Money.
 */
class StockPotentialProfitReportController extends Controller
{
    /** @var list<string> */
    private const SORTS = ['profit', 'margin', 'cost_value', 'sell_value', 'name'];

    public function index(Request $request): Response
    {
        $report = $this->report($request);
        $filters = $report['filters'];

        return Inertia::render('Tenant/Reports/StockPotentialProfit', [
            'asOf' => $report['asOf'],
            'rows' => $report['rows'],
            'totals' => $report['totals'],
            'unpricedCount' => $report['unpricedCount'],
            'stores' => BranchStock::storeOptions($report['filter'], Store::where('is_active', true))->orderBy('name')->get(['id', 'name']),
            'storeId' => $report['storeId'],
            'includeEmpty' => $report['includeEmpty'],
            'sort' => $report['sort'],
            'itemCategoryId' => $filters['item_category_id'] ?? null,
            'itemSubcategoryId' => $filters['item_subcategory_id'] ?? null,
            'brandId' => $filters['brand_id'] ?? null,
            'search' => $filters['search'] ?? '',
            'filterOptions' => StockItemFilters::options(),
            'branchReport' => $report['branchReport'],
        ]);
    }

    /**
     * Same rows, order and totals as index() for the same query string, as a
     * real .xlsx.
     */
    public function export(Request $request): BinaryFileResponse
    {
        $report = $this->report($request);
        $filter = $report['filter'];

        return Excel::download(
            new StockPotentialProfitExport(
                $report['rows']->all(),
                $report['totals'],
                $report['unpricedCount'],
                $report['asOf'],
                $filter->isActive() ? $filter->label() : null,
            ),
            "stock-potential-profit-{$report['asOf']}.xlsx",
        );
    }

    /**
     * The one place the report is built, so the page and the Excel download
     * can never disagree.
     *
     * @return array{asOf: string, rows: Collection<int, array<string, mixed>>, totals: array{costValue: string, pricedCostValue: string, sellValue: string, profit: string, marginPercent: string|null}, unpricedCount: int, filter: BranchFilter, storeId: int|null, includeEmpty: bool, sort: string, filters: array<string, int|string>, branchReport: array<string, mixed>|null}
     */
    private function report(Request $request): array
    {
        // Strict Y-m-d: a malformed date is a 422 the page shows, never a
        // silent fall back to today. An absent as_of still means today.
        $request->validate([
            'as_of' => ['nullable', 'date_format:Y-m-d'],
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'include_empty' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'string', 'in:'.implode(',', self::SORTS)],
            ...StockItemFilters::rules(),
        ]);

        $asOf = AsOfDate::resolve($request->input('as_of'));
        $filter = BranchFilter::fromRequest($request);
        $storeId = $filter->allowedStoreId($request->integer('store_id') ?: null);
        $includeEmpty = $request->boolean('include_empty');
        $sort = $request->string('sort')->toString() ?: 'profit';
        $filters = StockItemFilters::fromRequest($request);

        // The exact call StockValuationReportController makes with stock
        // status "All" and no by-branch columns, so the cost figures match it.
        $valuationRows = BranchStock::valuationRows(
            $filter,
            $asOf,
            $storeId,
            [...$filters, 'stock_status' => 'all', 'include_empty' => $includeEmpty],
            false,
        );

        $saleRates = $this->saleRates($valuationRows->pluck('item_id')->all());

        $rows = $valuationRows
            ->map(fn (array $row) => $this->presentRow($row, $saleRates[$row['item_id']] ?? null))
            ->pipe(fn (Collection $rows) => $this->sortRows($rows, $sort))
            ->values();

        $priced = $rows->filter(fn (array $row) => $row['sellValue'] !== null);
        $sellValue = Money::sum($priced->map(fn (array $row) => Money::of($row['sellValue'])));
        $profit = Money::sum($priced->map(fn (array $row) => Money::of($row['profit'])));

        return [
            'asOf' => $asOf,
            'rows' => $rows,
            'totals' => [
                'costValue' => Money::sum($rows->map(fn (array $row) => Money::of($row['costValue'])))->toString(),
                'pricedCostValue' => Money::sum($priced->map(fn (array $row) => Money::of($row['costValue'])))->toString(),
                'sellValue' => $sellValue->toString(),
                'profit' => $profit->toString(),
                'marginPercent' => $this->marginPercent($profit, $sellValue),
            ],
            // Only unpriced items that hold stock: a zero-stock row listed by
            // "Show zero-stock items" leaves nothing out of the totals.
            'unpricedCount' => $rows
                ->filter(fn (array $row) => $row['sellValue'] === null && ! Quantity::of($row['quantity'])->isZero())
                ->count(),
            'filter' => $filter,
            'storeId' => $storeId,
            'includeEmpty' => $includeEmpty,
            'sort' => $sort,
            'filters' => $filters,
            'branchReport' => $filter->payload(supportsByBranch: false),
        ];
    }

    /**
     * Item id => selling rate per base unit, only for items that have a
     * non-zero one. One query for the whole report.
     *
     * @param  list<int>  $itemIds
     * @return array<int, Quantity>
     */
    private function saleRates(array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        $rates = [];

        foreach (Item::query()->whereIn('id', $itemIds)->get(['id', 'sale_rate']) as $item) {
            $rate = Quantity::ofNullable($item->sale_rate);

            if ($rate !== null && $rate->isPositive()) {
                $rates[$item->getKey()] = $rate;
            }
        }

        return $rates;
    }

    /**
     * @param  array{item_id: int, name: string, unit: string, quantity: Quantity, average_cost: string, value: Money}  $row  one StockCosting valuation row
     * @return array{itemId: int, name: string, unit: string, quantity: string, costRate: string, sellRate: string|null, costValue: string, sellValue: string|null, profit: string|null, marginPercent: string|null}
     */
    private function presentRow(array $row, ?Quantity $saleRate): array
    {
        $sellValue = $saleRate === null
            ? null
            : Money::round($row['quantity']->toBigDecimal()->multipliedBy($saleRate->toBigDecimal()));
        $profit = $sellValue?->minus($row['value']);

        return [
            'itemId' => $row['item_id'],
            'name' => $row['name'],
            'unit' => $row['unit'],
            'quantity' => $row['quantity']->toString(),
            'costRate' => $row['average_cost'],
            'sellRate' => $saleRate?->toString(),
            'costValue' => $row['value']->toString(),
            'sellValue' => $sellValue?->toString(),
            'profit' => $profit?->toString(),
            'marginPercent' => $sellValue === null || $profit === null ? null : $this->marginPercent($profit, $sellValue),
        ];
    }

    /**
     * Profit as a percentage of sell value, exact to 2 decimals (HalfUp,
     * one rounding); null when the sell value is not positive.
     */
    private function marginPercent(Money $profit, Money $sellValue): ?string
    {
        // A zero or negative sell value (negative stock) has no meaningful margin.
        if (! $sellValue->isPositive()) {
            return null;
        }

        return $profit->toBigDecimal()
            ->multipliedBy(100)
            ->dividedBy($sellValue->toBigDecimal(), 2, RoundingMode::HalfUp)
            ->toString();
    }

    /**
     * Exact decimal comparisons, never a float sort key. Items without a
     * selling rate always sort after the priced ones (they have no profit or
     * margin to rank); ties fall back to the item name so the order is
     * deterministic.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function sortRows(Collection $rows, string $sort): Collection
    {
        if ($sort === 'name') {
            return $rows->sort(fn (array $a, array $b) => strnatcasecmp($a['name'], $b['name']));
        }

        $key = match ($sort) {
            'margin' => 'marginPercent',
            'cost_value' => 'costValue',
            'sell_value' => 'sellValue',
            default => 'profit',
        };

        return $rows->sort(function (array $a, array $b) use ($key): int {
            if ($a[$key] === null || $b[$key] === null) {
                $byPresence = ($a[$key] === null) <=> ($b[$key] === null);

                return $byPresence !== 0 ? $byPresence : strnatcasecmp($a['name'], $b['name']);
            }

            // Every sort key is a 2-decimal string (money or margin percent).
            $byValue = Money::of($b[$key])->compareTo(Money::of($a[$key]));

            return $byValue !== 0 ? $byValue : strnatcasecmp($a['name'], $b['name']);
        });
    }
}
