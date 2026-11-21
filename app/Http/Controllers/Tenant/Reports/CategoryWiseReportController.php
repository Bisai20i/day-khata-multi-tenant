<?php

namespace App\Http\Controllers\Tenant\Reports;

use App\Enums\FiscalYearStatus;
use App\Exports\PurchaseByCategoryExport;
use App\Exports\SalesByCategoryExport;
use App\Exports\StockByCategoryExport;
use App\Http\Controllers\Concerns\AllocatesDocumentDiscount;
use App\Http\Controllers\Controller;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemSubcategory;
use App\Models\Store;
use App\Support\Branches\BranchFilter;
use App\Support\Branches\BranchStock;
use App\Support\Money\Money;
use App\Support\Money\Quantity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Category-wise rollups over sales, purchases and stock - siblings of the
 * per-item registers and the Stock Summary, grouped by ItemCategory (with an
 * ItemSubcategory breakdown nested inside each category row).
 *
 * Two rules from the 2026-09-11 audit shape every figure here:
 *
 * - **Values come from stored decimals and StockCosting, never a float.**
 *   Stock value is App\Support\Inventory\StockCosting (C10), the one place
 *   stock is valued; the old per-category weighted average rounded the cost
 *   to 4 decimals before multiplying and counted transfer-in rows as fresh
 *   stock, so a category total disagreed with the Balance Sheet.
 * - **Quantities are never added across units.** Summing 12 Boxes and 30
 *   Kilograms into "42" is not a number anyone can use, so every quantity
 *   total is a per-base-unit breakdown: line quantities are converted to
 *   base units with the line's own `unit_conversion_factor` and grouped by
 *   the item's base unit.
 */
class CategoryWiseReportController extends Controller
{
    use AllocatesDocumentDiscount;

    /**
     * Documents read per query when the sales side splits each invoice's
     * document discount back onto its lines (the same chunk size the
     * Item-wise reports use).
     */
    private const DOCUMENT_CHUNK = 500;

    /**
     * Table names behind the two document-based reports. Sales and purchases
     * have the same shape (document, lines, posted returns with their own
     * lines), so one set of private methods serves both.
     *
     * Every value on both sides is on ONE basis, the one Item-wise Sales and
     * Item-wise Purchase use: the line's value after its own discount AND its
     * share of the document discount, before VAT (R02 review):
     * - a sale line: the sale's stored taxable / non-taxable amounts split
     *   back across its lines with splitDocument() (ItemWiseSales'
     *   allocateSale());
     * - a purchase line: its stored `net_value` (old rows fall back to
     *   `line_total`, as ItemWisePurchase does);
     * - a linked credit note line: its stored `net_amount`; an unlinked
     *   credit note is split across its item lines with splitDocument();
     * - a debit note line: its stored `net_value` (fallback `line_total`).
     * So Net (value minus returns) ties to Item-wise's net total for the
     * same dates, store and branch.
     *
     * @var array<string, array<string, string>>
     */
    private const DOCUMENT_SIDES = [
        'sales' => [
            'documents' => 'sales',
            'lines' => 'sale_lines',
            'line_key' => 'sale_id',
            'returns' => 'sales_returns',
            'return_lines' => 'sale_return_lines',
            'return_key' => 'sales_return_id',
            'return_link' => 'sale_line_id',
        ],
        'purchases' => [
            'documents' => 'purchases',
            'lines' => 'purchase_lines',
            'line_key' => 'purchase_id',
            'returns' => 'purchase_returns',
            'return_lines' => 'purchase_return_lines',
            'return_key' => 'purchase_return_id',
            'return_link' => 'purchase_line_id',
        ],
    ];

    /**
     * Every ItemCategory (and its subcategories) is always represented, even
     * with zero activity, so a category with no sales in the range still
     * shows as a zero row rather than silently vanishing.
     */
    public function salesByCategory(Request $request): Response
    {
        return $this->documentCategoryPage('sales', 'Tenant/Reports/SalesByCategory', $request);
    }

    /**
     * Exact mirror of salesByCategory() over purchases and debit notes.
     */
    public function purchaseByCategory(Request $request): Response
    {
        return $this->documentCategoryPage('purchases', 'Tenant/Reports/PurchaseByCategory', $request);
    }

    /**
     * Same rows and grand total as salesByCategory(), streamed as a real
     * .xlsx.
     */
    public function salesByCategoryExport(Request $request): BinaryFileResponse
    {
        return $this->documentCategoryExport('sales', $request);
    }

    public function purchaseByCategoryExport(Request $request): BinaryFileResponse
    {
        return $this->documentCategoryExport('purchases', $request);
    }

    /**
     * The items behind one Sales by Category row. `category_id` is a
     * category id or `none`; the optional `subcategory_id` is a subcategory id
     * or `none` (the "Uncategorized" row), exactly like
     * stockByCategoryItems(). A missing record is a 404.
     *
     * Rows come from the same line and return queries the parent uses, only
     * narrower, so the totals here equal the parent row's value and
     * returns for the same dates, store and branch.
     */
    public function salesByCategoryItems(Request $request): Response
    {
        return $this->documentCategoryItemsPage('sales', 'Tenant/Reports/SalesByCategoryItems', $request);
    }

    public function purchaseByCategoryItems(Request $request): Response
    {
        return $this->documentCategoryItemsPage('purchases', 'Tenant/Reports/PurchaseByCategoryItems', $request);
    }

    /**
     * @return array{from: string, to: string, storeId: int|null, rows: array<int, array<string, mixed>>, grandTotal: array<string, mixed>}
     */
    private function documentCategoryReport(string $side, Request $request, BranchFilter $branchFilter): array
    {
        ['from' => $from, 'to' => $to, 'storeId' => $storeId] = $this->resolveDocumentQuery($request, $branchFilter);

        $categories = ItemCategory::query()->with('subcategories')->orderBy('name')->get();
        $aggregates = $this->documentCategoryAggregates($side, $from, $to, $storeId, $branchFilter);

        ['rows' => $rows, 'grandTotal' => $grandTotal] = $this->buildCategoryValueRows($categories, $aggregates);

        foreach ($rows as $index => $row) {
            $row = $this->withNet($row);
            $row['subcategories'] = array_map(fn (array $subcategory): array => $this->withNet($subcategory), $row['subcategories']);
            $rows[$index] = $row;
        }

        return [
            'from' => $from,
            'to' => $to,
            'storeId' => $storeId,
            'rows' => $rows,
            'grandTotal' => $this->withNet($grandTotal),
        ];
    }

    /**
     * Adds `net` = value - returns. Both are on the same basis (after line
     * and document discounts, before VAT), so the difference is meaningful
     * and equals Item-wise's net for the same group of items. Done here, not
     * in buildCategoryValueRows(), so the stock report's payload is unchanged.
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function withNet(array $entry): array
    {
        $entry['net'] = Money::of($entry['value'])->minus(Money::of($entry['returns'] ?? '0'))->toString();

        return $entry;
    }

    private function documentCategoryPage(string $side, string $component, Request $request): Response
    {
        $branchFilter = BranchFilter::fromRequest($request);
        $report = $this->documentCategoryReport($side, $request, $branchFilter);

        return Inertia::render($component, [
            'from' => $report['from'],
            'to' => $report['to'],
            'rows' => $report['rows'],
            'grandTotal' => $report['grandTotal'],
            'stores' => BranchStock::storeOptions($branchFilter, Store::where('is_active', true))->orderBy('name')->get(['id', 'name']),
            'storeId' => $report['storeId'],
            'branchReport' => $branchFilter->payload(),
        ]);
    }

    private function documentCategoryExport(string $side, Request $request): BinaryFileResponse
    {
        $branchFilter = BranchFilter::fromRequest($request);
        $report = $this->documentCategoryReport($side, $request, $branchFilter);

        $arguments = [
            $report['rows'],
            $report['grandTotal'],
            $report['from'],
            $report['to'],
            $branchFilter->isActive() ? $branchFilter->label() : null,
            $report['storeId'] !== null ? Store::query()->whereKey($report['storeId'])->value('name') : null,
        ];

        $export = $side === 'sales' ? new SalesByCategoryExport(...$arguments) : new PurchaseByCategoryExport(...$arguments);
        $slug = $side === 'sales' ? 'sales-by-category' : 'purchase-by-category';

        return Excel::download($export, "{$slug}-{$report['from']}-to-{$report['to']}.xlsx");
    }

    private function documentCategoryItemsPage(string $side, string $component, Request $request): Response
    {
        $request->validate([
            'category_id' => ['required', 'regex:/^(none|[1-9][0-9]*)$/'],
            'subcategory_id' => ['nullable', 'regex:/^(none|[1-9][0-9]*)$/'],
        ]);

        $branchFilter = BranchFilter::fromRequest($request);
        ['from' => $from, 'to' => $to, 'storeId' => $storeId] = $this->resolveDocumentQuery($request, $branchFilter);

        $categoryParam = $request->string('category_id')->toString();
        $subcategoryParam = $request->string('subcategory_id')->toString();

        $category = $categoryParam === 'none' ? null : ItemCategory::query()->with('subcategories')->findOrFail((int) $categoryParam);
        $subcategory = null;

        if ($subcategoryParam !== '' && $subcategoryParam !== 'none') {
            $subcategory = ItemSubcategory::query()
                ->whereKey((int) $subcategoryParam)
                ->where('item_category_id', $category?->id)
                ->firstOrFail();
        }

        $allowedSubcategoryIds = array_map('intval', $category?->subcategories->modelKeys() ?? []);

        // The parent only ever shows categories that exist, and a null-category
        // item is schema-impossible, so `none` simply lists nothing.
        $matches = function (array $row) use ($category, $subcategory, $subcategoryParam, $allowedSubcategoryIds): bool {
            if ($category === null || (int) $row['category_id'] !== $category->id) {
                return false;
            }

            if ($subcategory !== null) {
                return (int) $row['subcategory_id'] === $subcategory->id;
            }

            if ($subcategoryParam === 'none') {
                return $row['subcategory_id'] === null;
            }

            // Category-level list: exactly the buckets the category row sums.
            return $row['subcategory_id'] === null || in_array((int) $row['subcategory_id'], $allowedSubcategoryIds, true);
        };

        $items = [];

        // The very rows the parent buckets (already split onto lines, so the
        // document discount is allocated before narrowing), only filtered.
        foreach ($this->documentLineRows($side, $from, $to, $storeId, $branchFilter)->filter($matches) as $row) {
            $items[$row['item_id']] ??= $this->emptyItemRow($row);
            $items[$row['item_id']]['value'] = $items[$row['item_id']]['value']->plus($row['value']);
            $items[$row['item_id']]['quantity'] = $items[$row['item_id']]['quantity']->plus($row['base_quantity']);
        }

        foreach ($this->documentReturnRows($side, $from, $to, $storeId, $branchFilter)->filter($matches) as $row) {
            $items[$row['item_id']] ??= $this->emptyItemRow($row);
            $items[$row['item_id']]['returns'] = $items[$row['item_id']]['returns']->plus($row['value']);
        }

        $total = Money::zero();
        $totalReturns = Money::zero();
        $presented = [];

        foreach ($items as $itemId => $item) {
            $total = $total->plus($item['value']);
            $totalReturns = $totalReturns->plus($item['returns']);

            $presented[] = [
                'itemId' => $itemId,
                'itemName' => $item['name'],
                'unit' => $item['unit'],
                'quantity' => $item['quantity']->toString(),
                'value' => $item['value']->toString(),
                'returns' => $item['returns']->toString(),
                'net' => $item['value']->minus($item['returns'])->toString(),
            ];
        }

        usort($presented, fn (array $a, array $b): int => strcasecmp($a['itemName'], $b['itemName']));

        $groupName = $category?->name ?? 'Uncategorized';

        if ($subcategory !== null) {
            $groupName .= ' / '.$subcategory->name;
        } elseif ($subcategoryParam === 'none') {
            $groupName .= ' / Uncategorized';
        }

        return Inertia::render($component, [
            'from' => $from,
            'to' => $to,
            'storeId' => $storeId,
            'storeName' => $storeId !== null ? Store::query()->whereKey($storeId)->value('name') : null,
            'categoryId' => $category?->id,
            'subcategoryId' => $subcategory?->id,
            'groupName' => $groupName,
            'rows' => $presented,
            'totals' => [
                'value' => $total->toString(),
                'returns' => $totalReturns->toString(),
                'net' => $total->minus($totalReturns)->toString(),
            ],
            'branchReport' => $branchFilter->payload(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{name: string, unit: string, quantity: Quantity, value: Money, returns: Money}
     */
    private function emptyItemRow(array $row): array
    {
        return [
            'name' => (string) $row['item_name'],
            'unit' => (string) ($row['unit'] ?? ''),
            'quantity' => Quantity::zero(),
            'value' => Money::zero(),
            'returns' => Money::zero(),
        ];
    }

    /**
     * A quantity in base units: `quantity x unit_conversion_factor`, rounded
     * once at 4 decimals, exactly what the stock movement recorded. Callers
     * pass paid plus bonus units, as Item-wise does.
     */
    private function baseQuantity(Quantity $quantity, mixed $conversionFactor): Quantity
    {
        return Quantity::round(
            $quantity->toBigDecimal()->multipliedBy(Quantity::of($conversionFactor ?? '1')->toBigDecimal())
        );
    }

    /**
     * On-hand quantity and value as of a single date, grouped by
     * category/subcategory - StockCosting::valuationRows() summed into
     * buckets instead of listed per item.
     */
    public function stockByCategory(Request $request): Response
    {
        $branchFilter = BranchFilter::fromRequest($request);
        $report = $this->stockByCategoryReport($request, $branchFilter);

        return Inertia::render('Tenant/Reports/StockByCategory', [
            'asOf' => $report['asOf'],
            'rows' => $report['rows'],
            'grandTotal' => $report['grandTotal'],
            'grandTotalValuation' => $report['grandTotal']['value'],
            'stores' => BranchStock::storeOptions($branchFilter, Store::where('is_active', true))->orderBy('name')->get(['id', 'name']),
            'storeId' => $report['storeId'],
            'branchReport' => $branchFilter->payload(),
        ]);
    }

    /**
     * Same rows and grand total as stockByCategory(), streamed as a real
     * .xlsx.
     */
    public function stockByCategoryExport(Request $request): BinaryFileResponse
    {
        $branchFilter = BranchFilter::fromRequest($request);
        $report = $this->stockByCategoryReport($request, $branchFilter);

        return Excel::download(
            new StockByCategoryExport(
                $report['rows'],
                $report['grandTotal'],
                $report['asOf'],
                $branchFilter->isActive() ? $branchFilter->label() : null,
                $report['storeId'] !== null ? Store::query()->whereKey($report['storeId'])->value('name') : null,
            ),
            "stock-by-category-as-of-{$report['asOf']}.xlsx",
        );
    }

    /**
     * The items behind one Stock by Category row (legacy's groups ->
     * sub-groups -> items drill-down). `category_id` is a category id or
     * `none`; the optional `subcategory_id` is a subcategory id or `none`
     * for the "Uncategorized" row (items in the category with no
     * subcategory). Without `subcategory_id` the list is exactly what the
     * category row adds up: items with no subcategory or one of the
     * category's own subcategories.
     *
     * Rows are StockCosting's own valuation rows (C10), so the total here
     * equals the parent row's value for the same as-of/store/branch query.
     * Only the value is totalled; quantities are per item in its own unit.
     */
    public function stockByCategoryItems(Request $request): Response
    {
        $request->validate([
            'category_id' => ['required', 'regex:/^(none|[1-9][0-9]*)$/'],
            'subcategory_id' => ['nullable', 'regex:/^(none|[1-9][0-9]*)$/'],
        ]);

        $branchFilter = BranchFilter::fromRequest($request);
        ['asOf' => $asOf, 'storeId' => $storeId] = $this->resolveStockQuery($request, $branchFilter);

        $categoryParam = $request->string('category_id')->toString();
        $subcategoryParam = $request->string('subcategory_id')->toString();

        $category = $categoryParam === 'none' ? null : ItemCategory::query()->with('subcategories')->findOrFail((int) $categoryParam);
        $subcategory = null;

        if ($subcategoryParam !== '' && $subcategoryParam !== 'none') {
            $subcategory = ItemSubcategory::query()
                ->whereKey((int) $subcategoryParam)
                ->where('item_category_id', $category?->id)
                ->firstOrFail();
        }

        $filters = array_filter([
            'item_category_id' => $category?->id,
            'item_subcategory_id' => $subcategory?->id,
        ], fn ($value) => $value !== null);

        $items = Item::query()
            ->where('is_stockable', true)
            ->when($category !== null, fn (Builder $query) => $query->where('item_category_id', $category->id))
            ->get(['id', 'item_category_id', 'item_subcategory_id'])
            ->keyBy('id');

        $allowedSubcategoryIds = array_map('intval', $category?->subcategories->modelKeys() ?? []);

        $rows = BranchStock::valuationRows($branchFilter, $asOf, $storeId, $filters)
            ->filter(function (array $row) use ($items, $category, $subcategory, $subcategoryParam, $allowedSubcategoryIds): bool {
                $item = $items->get($row['item_id']);

                if ($item === null || ($category === null && $item->item_category_id !== null)) {
                    return false;
                }

                if ($subcategory !== null) {
                    return true;
                }

                if ($subcategoryParam === 'none') {
                    return $item->item_subcategory_id === null;
                }

                // Category-level list: exactly the buckets the category row sums.
                return $item->item_subcategory_id === null
                    || in_array((int) $item->item_subcategory_id, $allowedSubcategoryIds, true);
            })
            ->values();

        $groupName = $category?->name ?? 'Uncategorized';

        if ($subcategory !== null) {
            $groupName .= ' / '.$subcategory->name;
        } elseif ($subcategoryParam === 'none') {
            $groupName .= ' / Uncategorized';
        }

        return Inertia::render('Tenant/Reports/StockByCategoryItems', [
            'asOf' => $asOf,
            'storeId' => $storeId,
            'storeName' => $storeId !== null ? Store::query()->whereKey($storeId)->value('name') : null,
            'categoryId' => $category?->id,
            'subcategoryId' => $subcategory?->id,
            'groupName' => $groupName,
            'rows' => $this->presentItemRows($rows),
            'totalValue' => Money::sum($rows->pluck('value'))->toString(),
            'branchReport' => $branchFilter->payload(),
        ]);
    }

    /**
     * The one source of Stock by Category's rows and grand total, shared by
     * the page and its Excel export so both always agree.
     *
     * @return array{asOf: string, storeId: int|null, rows: array<int, array<string, mixed>>, grandTotal: array{value: string, quantities: array<int, array{unit: string, quantity: string}>}}
     */
    private function stockByCategoryReport(Request $request, BranchFilter $branchFilter): array
    {
        ['asOf' => $asOf, 'storeId' => $storeId] = $this->resolveStockQuery($request, $branchFilter);

        $categories = ItemCategory::query()->with('subcategories')->orderBy('name')->get();
        $aggregates = $this->categoryAggregatesFromStock($asOf, $storeId, $branchFilter);

        ['rows' => $rows, 'grandTotal' => $grandTotal] = $this->buildCategoryValueRows($categories, $aggregates);

        return [
            'asOf' => $asOf,
            'storeId' => $storeId,
            'rows' => $rows,
            'grandTotal' => $grandTotal,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows  StockCosting valuation rows
     * @return list<array{itemId: int, itemName: string, unit: string, quantity: string, averageCost: string, value: string}>
     */
    private function presentItemRows(Collection $rows): array
    {
        return $rows->map(fn (array $row): array => [
            'itemId' => $row['item_id'],
            'itemName' => $row['name'],
            'unit' => (string) ($row['unit'] ?? ''),
            'quantity' => $row['quantity']->toString(),
            'averageCost' => $row['average_cost'],
            'value' => $row['value']->toString(),
        ])->values()->all();
    }

    /**
     * Posted line values and posted returns grouped by category/subcategory
     * within the date range, with sold quantities (paid plus bonus) in base
     * units keyed by unit. A cancelled document drops out whole (it is not
     * counted in its own month and then reversed in the cancel month, as the
     * VAT books do), and a pending or rejected return counts for nothing.
     *
     * @return array<string, array{value: Money, returns: Money, quantities: array<string, Quantity>}> keyed "{categoryId}:{subcategoryId}"
     */
    private function documentCategoryAggregates(string $side, string $from, string $to, ?int $storeId, BranchFilter $branchFilter): array
    {
        $aggregates = $this->bucket($this->documentLineRows($side, $from, $to, $storeId, $branchFilter));

        foreach ($this->documentReturnRows($side, $from, $to, $storeId, $branchFilter) as $row) {
            $key = "{$row['category_id']}:{$row['subcategory_id']}";

            $aggregates[$key] ??= ['value' => Money::zero(), 'returns' => Money::zero(), 'quantities' => []];
            $aggregates[$key]['returns'] = $aggregates[$key]['returns']->plus($row['value']);
        }

        return $aggregates;
    }

    /**
     * One row per posted document line in range: its item, category,
     * subcategory and unit, its base quantity (`(quantity + bonus_quantity)
     * x unit_conversion_factor`, what the stock movement recorded) and its
     * value after the line AND document discounts, before VAT (see
     * DOCUMENT_SIDES). Narrowed on the document's own branch_id (BR14); line
     * tables carry no access scope, so BranchFilter::applyToDocuments() also
     * keeps a restricted user to their own branches here.
     *
     * @return Collection<int, array{item_id: int, item_name: string, category_id: mixed, subcategory_id: mixed, unit: string|null, base_quantity: Quantity, value: Money}>
     */
    private function documentLineRows(string $side, string $from, string $to, ?int $storeId, BranchFilter $branchFilter): Collection
    {
        $tables = self::DOCUMENT_SIDES[$side];

        $scope = fn () => DB::table($tables['lines'])
            ->join($tables['documents'], "{$tables['documents']}.id", '=', "{$tables['lines']}.{$tables['line_key']}")
            ->join('items', 'items.id', '=', "{$tables['lines']}.item_id")
            ->where("{$tables['documents']}.status", 'posted')
            ->whereDate("{$tables['documents']}.date", '>=', $from)
            ->whereDate("{$tables['documents']}.date", '<=', $to)
            ->when($storeId !== null, fn ($query) => $query->where("{$tables['documents']}.store_id", $storeId))
            ->tap(fn ($query) => $branchFilter->applyToDocuments($query, "{$tables['documents']}.branch_id"));

        $lineColumns = [
            ...$this->itemColumns(),
            "{$tables['lines']}.id as id",
            "{$tables['lines']}.{$tables['line_key']} as document_id",
            "{$tables['lines']}.quantity as quantity",
            "{$tables['lines']}.bonus_quantity as bonus_quantity",
            "{$tables['lines']}.unit_conversion_factor as unit_conversion_factor",
            "{$tables['lines']}.line_total as line_total",
        ];

        $baseQuantity = fn (object $line): Quantity => $this->baseQuantity(
            Quantity::of($line->quantity)->plus(Quantity::of($line->bonus_quantity ?? '0')),
            $line->unit_conversion_factor,
        );

        if ($side === 'purchases') {
            // Each purchase line stores its own share of the bill (C6), as
            // ItemWisePurchaseReportController reads it.
            return $scope()
                ->get([...$lineColumns, 'purchase_lines.net_value as net_value'])
                ->map(fn (object $line): array => $this->categoryRow($line, Money::of($line->net_value ?? $line->line_total), $baseQuantity($line)))
                ->values();
        }

        // A sale line stores only its own value; the document discount lives
        // on the sale. Every sale is split across ALL its lines (a sale line
        // always carries an item) exactly as ItemWiseSalesReportController's
        // allocateSale() does, a chunk of whole sales at a time.
        $documentIds = $scope()->distinct()->orderBy('sales.id')->pluck('sales.id');
        $rows = collect();

        foreach ($documentIds->chunk(self::DOCUMENT_CHUNK) as $chunk) {
            $lines = $scope()
                ->whereIn('sales.id', $chunk->all())
                ->orderBy('sales.id')
                ->orderBy('sale_lines.id')
                ->get([
                    ...$lineColumns,
                    'sale_lines.vatable as vatable',
                    'sales.taxable_amount as document_taxable',
                    'sales.nontaxable_amount as document_nontaxable',
                    'sales.vat_amount as document_vat',
                    'sales.round_off as document_round_off',
                ]);

            foreach ($lines->groupBy('document_id') as $documentLines) {
                $header = $documentLines->first();
                $components = $this->splitDocument(
                    $documentLines->mapWithKeys(fn (object $line) => [
                        (int) $line->id => ['line_total' => Money::of($line->line_total), 'vatable' => (bool) $line->vatable],
                    ])->all(),
                    Money::of($header->document_taxable ?? '0'),
                    Money::of($header->document_nontaxable ?? '0'),
                    Money::of($header->document_vat ?? '0'),
                    Money::of($header->document_round_off ?? '0'),
                );

                foreach ($documentLines as $line) {
                    $rows->push($this->categoryRow($line, $components[(int) $line->id]['net'], $baseQuantity($line)));
                }
            }
        }

        return $rows;
    }

    /**
     * One row per returned line of a posted credit or debit note dated in
     * range, with the same item and category columns and the value the
     * return credited, after line and document discounts, before VAT (see
     * DOCUMENT_SIDES). The item is the return line's own, falling back to the
     * original document line's, as Item-wise does; a line with no item is
     * dropped (inner join) BEFORE an unlinked credit note is split, so its
     * item lines absorb the whole stored document, again as Item-wise does.
     * Dated by the return's own date, not the original document's.
     *
     * @return Collection<int, array{item_id: int, item_name: string, category_id: mixed, subcategory_id: mixed, unit: string|null, base_quantity: Quantity, value: Money}>
     */
    private function documentReturnRows(string $side, string $from, string $to, ?int $storeId, BranchFilter $branchFilter): Collection
    {
        $tables = self::DOCUMENT_SIDES[$side];

        $scope = fn () => DB::table("{$tables['return_lines']} as return_lines")
            ->join("{$tables['returns']} as doc_returns", 'doc_returns.id', '=', "return_lines.{$tables['return_key']}")
            ->leftJoin("{$tables['lines']} as document_lines", 'document_lines.id', '=', "return_lines.{$tables['return_link']}")
            ->join('items', fn ($join) => $join->whereRaw('items.id = COALESCE(return_lines.item_id, document_lines.item_id)'))
            ->where('doc_returns.status', 'posted')
            ->whereDate('doc_returns.date', '>=', $from)
            ->whereDate('doc_returns.date', '<=', $to)
            ->when($storeId !== null, fn ($query) => $query->where('doc_returns.store_id', $storeId))
            ->tap(fn ($query) => $branchFilter->applyToDocuments($query, 'doc_returns.branch_id'));

        if ($side === 'purchases') {
            // Every debit note line stores its own net (C6).
            return $scope()
                ->get([...$this->itemColumns(), 'return_lines.net_value as net_value', 'return_lines.line_total as line_total'])
                ->map(fn (object $line): array => $this->categoryRow($line, Money::of($line->net_value ?? $line->line_total), Quantity::zero()))
                ->values();
        }

        $documentIds = $scope()->distinct()->orderBy('doc_returns.id')->pluck('doc_returns.id');
        $rows = collect();

        foreach ($documentIds->chunk(self::DOCUMENT_CHUNK) as $chunk) {
            $lines = $scope()
                ->whereIn('doc_returns.id', $chunk->all())
                ->orderBy('doc_returns.id')
                ->orderBy('return_lines.id')
                ->get([
                    ...$this->itemColumns(),
                    'return_lines.id as id',
                    'return_lines.sales_return_id as document_id',
                    'return_lines.line_total as line_total',
                    'return_lines.net_amount as net_amount',
                    'return_lines.vatable as return_vatable',
                    'document_lines.item_id as sale_item_id',
                    'document_lines.vatable as sale_vatable',
                    'doc_returns.sale_id as sale_id',
                    'doc_returns.is_unlinked as is_unlinked',
                    'doc_returns.taxable_amount as document_taxable',
                    'doc_returns.nontaxable_amount as document_nontaxable',
                    'doc_returns.vat_amount as document_vat',
                    'doc_returns.round_off as document_round_off',
                ]);

            foreach ($lines->groupBy('document_id') as $documentLines) {
                $header = $documentLines->first();

                // Mirrors ItemWiseSalesReportController::returnRows(): a linked
                // credit note line stores its own net (C6); an unlinked one is
                // split across the credit note's item lines.
                if (! (bool) $header->is_unlinked && $header->sale_id !== null) {
                    foreach ($documentLines as $line) {
                        $rows->push($this->categoryRow($line, Money::of($line->net_amount ?? $line->line_total), Quantity::zero()));
                    }

                    continue;
                }

                $components = $this->splitDocument(
                    $documentLines->mapWithKeys(fn (object $line) => [
                        (int) $line->id => [
                            'line_total' => Money::of($line->line_total),
                            'vatable' => (bool) ($line->sale_item_id !== null ? $line->sale_vatable : $line->return_vatable),
                        ],
                    ])->all(),
                    Money::of($header->document_taxable ?? '0'),
                    Money::of($header->document_nontaxable ?? '0'),
                    Money::of($header->document_vat ?? '0'),
                    Money::of($header->document_round_off ?? '0'),
                );

                foreach ($documentLines as $line) {
                    $rows->push($this->categoryRow($line, $components[(int) $line->id]['net'], Quantity::zero()));
                }
            }
        }

        return $rows;
    }

    /**
     * The item and category columns every document and return row carries.
     *
     * @return list<string>
     */
    private function itemColumns(): array
    {
        return [
            'items.id as item_id',
            'items.name as item_name',
            'items.item_category_id as category_id',
            'items.item_subcategory_id as subcategory_id',
            'items.unit as unit',
        ];
    }

    /**
     * @return array{item_id: int, item_name: string, category_id: mixed, subcategory_id: mixed, unit: string|null, base_quantity: Quantity, value: Money}
     */
    private function categoryRow(object $line, Money $value, Quantity $baseQuantity): array
    {
        return [
            'item_id' => (int) $line->item_id,
            'item_name' => (string) $line->item_name,
            'category_id' => $line->category_id,
            'subcategory_id' => $line->subcategory_id,
            'unit' => $line->unit,
            'base_quantity' => $baseQuantity,
            'value' => $value,
        ];
    }

    /**
     * Sums line rows into category/subcategory buckets. Quantities arrive in
     * base units and are kept per unit so two different units never land in
     * one number.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, array{value: Money, returns: Money, quantities: array<string, Quantity>}>
     */
    private function bucket(Collection $rows): array
    {
        $aggregates = [];

        foreach ($rows as $row) {
            $key = "{$row['category_id']}:{$row['subcategory_id']}";
            $unit = (string) ($row['unit'] ?? '');

            $aggregates[$key] ??= ['value' => Money::zero(), 'returns' => Money::zero(), 'quantities' => []];
            $aggregates[$key]['quantities'][$unit] ??= Quantity::zero();

            $aggregates[$key]['value'] = $aggregates[$key]['value']->plus($row['value']);
            $aggregates[$key]['quantities'][$unit] = $aggregates[$key]['quantities'][$unit]->plus($row['base_quantity']);
        }

        return $aggregates;
    }

    /**
     * Closing stock quantity and value per category/subcategory, read through
     * StockCosting (C10) so a category total can never disagree with the
     * Balance Sheet's Stock in Hand. Narrowed to the covered branches'
     * stores through BranchStock (BR15); one store or the whole company
     * exactly as before otherwise.
     *
     * @return array<string, array{value: Money, quantities: array<string, Quantity>}>
     */
    private function categoryAggregatesFromStock(string $asOf, ?int $storeId, BranchFilter $branchFilter): array
    {
        $items = Item::query()
            ->where('is_stockable', true)
            ->get(['id', 'item_category_id', 'item_subcategory_id', 'unit'])
            ->keyBy('id');

        $aggregates = [];

        foreach (BranchStock::valuationRows($branchFilter, $asOf, $storeId) as $row) {
            $item = $items->get($row['item_id']);

            if ($item === null) {
                continue;
            }

            $key = "{$item->item_category_id}:{$item->item_subcategory_id}";
            $unit = (string) ($item->unit ?? '');

            $aggregates[$key] ??= ['value' => Money::zero(), 'quantities' => []];
            $aggregates[$key]['quantities'][$unit] ??= Quantity::zero();

            $aggregates[$key]['value'] = $aggregates[$key]['value']->plus($row['value']);
            $aggregates[$key]['quantities'][$unit] = $aggregates[$key]['quantities'][$unit]->plus($row['quantity']);
        }

        return $aggregates;
    }

    /**
     * Nests every ItemCategory/ItemSubcategory into rows carrying a value and
     * a per-unit quantity breakdown, defaulting to zero wherever the
     * aggregates map has no entry. Items without a subcategory roll into the
     * category total and surface as an "Uncategorized" row only when they
     * actually contributed something, so the grand total always equals the
     * sum of every shown row.
     *
     * Sales and purchase aggregates also carry a `returns` Money (credit or
     * debit notes); every row then reports `returns` beside `value`. There is
     * deliberately no net figure: a line's value is before the invoice
     * discount while a return credits after it, so subtracting one from the
     * other is wrong on any discounted invoice. Stock aggregates carry no
     * returns, so their `returns` is zero, which the stock pages and export
     * never read.
     *
     * @param  Collection<int, ItemCategory>  $categories
     * @param  array<string, array{value: Money, returns?: Money, quantities: array<string, Quantity>}>  $aggregates
     * @return array{rows: array<int, array<string, mixed>>, grandTotal: array{value: string, returns: string, quantities: array<int, array{unit: string, quantity: string}>}}
     */
    private function buildCategoryValueRows(Collection $categories, array $aggregates): array
    {
        $rows = [];
        $grandValue = Money::zero();
        $grandReturns = Money::zero();
        $grandQuantities = [];

        foreach ($categories as $category) {
            $categoryValue = Money::zero();
            $categoryReturns = Money::zero();
            $categoryQuantities = [];
            $subcategoryRows = [];

            foreach ($category->subcategories as $subcategory) {
                $aggregate = $aggregates["{$category->id}:{$subcategory->id}"] ?? null;
                $value = $aggregate['value'] ?? Money::zero();
                $returns = $aggregate['returns'] ?? Money::zero();

                $subcategoryRows[] = [
                    'subcategoryId' => $subcategory->id,
                    'subcategoryName' => $subcategory->name,
                    'value' => $value->toString(),
                    'returns' => $returns->toString(),
                    'quantities' => $this->presentQuantities($aggregate['quantities'] ?? []),
                ];

                if ($aggregate !== null) {
                    $categoryValue = $categoryValue->plus($value);
                    $categoryReturns = $categoryReturns->plus($returns);
                    $categoryQuantities = $this->mergeQuantities($categoryQuantities, $aggregate['quantities']);
                }
            }

            $unassigned = $aggregates["{$category->id}:"] ?? null;

            if ($unassigned !== null && ! $this->isEmptyAggregate($unassigned)) {
                $value = $unassigned['value'];
                $returns = $unassigned['returns'] ?? Money::zero();

                $categoryValue = $categoryValue->plus($value);
                $categoryReturns = $categoryReturns->plus($returns);
                $categoryQuantities = $this->mergeQuantities($categoryQuantities, $unassigned['quantities']);

                $subcategoryRows[] = [
                    'subcategoryId' => null,
                    'subcategoryName' => 'Uncategorized',
                    'value' => $value->toString(),
                    'returns' => $returns->toString(),
                    'quantities' => $this->presentQuantities($unassigned['quantities']),
                ];
            }

            $rows[] = [
                'categoryId' => $category->id,
                'categoryName' => $category->name,
                'value' => $categoryValue->toString(),
                'returns' => $categoryReturns->toString(),
                'quantities' => $this->presentQuantities($categoryQuantities),
                'subcategories' => $subcategoryRows,
            ];

            $grandValue = $grandValue->plus($categoryValue);
            $grandReturns = $grandReturns->plus($categoryReturns);
            $grandQuantities = $this->mergeQuantities($grandQuantities, $categoryQuantities);
        }

        return [
            'rows' => $rows,
            'grandTotal' => [
                'value' => $grandValue->toString(),
                'returns' => $grandReturns->toString(),
                'quantities' => $this->presentQuantities($grandQuantities),
            ],
        ];
    }

    /**
     * @param  array<string, Quantity>  $into
     * @param  array<string, Quantity>  $from
     * @return array<string, Quantity>
     */
    private function mergeQuantities(array $into, array $from): array
    {
        foreach ($from as $unit => $quantity) {
            $into[$unit] = ($into[$unit] ?? Quantity::zero())->plus($quantity);
        }

        return $into;
    }

    /**
     * @param  array<string, Quantity>  $quantities
     * @return array<int, array{unit: string, quantity: string}>
     */
    private function presentQuantities(array $quantities): array
    {
        $presented = [];

        foreach ($quantities as $unit => $quantity) {
            if ($quantity->isZero()) {
                continue;
            }

            $presented[] = ['unit' => $unit, 'quantity' => $quantity->toString()];
        }

        usort($presented, fn (array $a, array $b) => strcasecmp($a['unit'], $b['unit']));

        return $presented;
    }

    /**
     * @param  array{value: Money, returns?: Money, quantities: array<string, Quantity>}  $aggregate
     */
    private function isEmptyAggregate(array $aggregate): bool
    {
        if (! $aggregate['value']->isZero() || ! ($aggregate['returns'] ?? Money::zero())->isZero()) {
            return false;
        }

        foreach ($aggregate['quantities'] as $quantity) {
            if (! $quantity->isZero()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Strict as-of/store validation for the stock report, its drill-down
     * and its export: a malformed date is a validation error rather than a
     * Carbon::parse() 500, and the download name only ever holds a clean
     * Y-m-d. A store outside the chosen branches is dropped (BR15).
     *
     * @return array{asOf: string, storeId: int|null}
     */
    private function resolveStockQuery(Request $request, BranchFilter $branchFilter): array
    {
        $request->validate([
            'as_of' => ['nullable', 'date_format:Y-m-d'],
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
        ]);

        $asOf = $request->string('as_of')->toString();

        return [
            'asOf' => $asOf !== '' ? $asOf : Carbon::now()->toDateString(),
            'storeId' => $branchFilter->allowedStoreId($request->integer('store_id') ?: null),
        ];
    }

    /**
     * Strict date and store validation for the sales and purchase category
     * reports, their drill-downs and exports. A malformed date, or a `to`
     * before `from`, is a 422 validation error rather than a silent fallback.
     * An absent date defaults to the open fiscal year's start (`from`) or end
     * (`to`), and a store outside the chosen branches is dropped (BR15).
     *
     * @return array{from: string, to: string, storeId: int|null}
     */
    private function resolveDocumentQuery(Request $request, BranchFilter $branchFilter): array
    {
        $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d', 'regex:/^\d{4}-\d{2}-\d{2}$/'],
            'to' => array_merge(['nullable', 'date_format:Y-m-d', 'regex:/^\d{4}-\d{2}-\d{2}$/'], $request->filled('from') ? ['after_or_equal:from'] : []),
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
        ]);

        [$defaultFrom, $defaultTo] = $this->defaultDateRange();

        $from = $request->string('from')->toString();
        $to = $request->string('to')->toString();

        $from = $from !== '' ? $from : $defaultFrom;
        $to = $to !== '' ? $to : $defaultTo;

        if ($from > $to) {
            throw ValidationException::withMessages(['to' => 'The to date must be on or after the from date.']);
        }

        return [
            'from' => $from,
            'to' => $to,
            'storeId' => $branchFilter->allowedStoreId($request->integer('store_id') ?: null),
        ];
    }

    /**
     * The current open fiscal year's range, falling back to month-to-date if
     * no fiscal year exists yet.
     *
     * @return array{0: string, 1: string}
     */
    private function defaultDateRange(): array
    {
        $fiscalYear = FiscalYear::query()->where('status', FiscalYearStatus::Open)->first();

        if ($fiscalYear) {
            return [$fiscalYear->start_date->toDateString(), $fiscalYear->end_date->toDateString()];
        }

        return [now()->startOfMonth()->toDateString(), now()->toDateString()];
    }
}
