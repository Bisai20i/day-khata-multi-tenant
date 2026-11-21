<?php

namespace App\Http\Controllers\Tenant\Reports;

use App\Enums\FiscalYearStatus;
use App\Exports\ItemWiseSalesExport;
use App\Http\Controllers\Concerns\AggregatesItemWiseRows;
use App\Http\Controllers\Concerns\AllocatesDocumentDiscount;
use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemSubcategory;
use App\Models\SaleLine;
use App\Models\SaleReturnLine;
use App\Models\Store;
use App\Support\Branches\BranchFilter;
use App\Support\Branches\BranchStock;
use App\Support\Money\Money;
use App\Support\Money\Quantity;
use App\Support\Reports\StockItemFilters;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Item-level counterpart to the invoice-level Sales Register: every SaleLine
 * of every posted Sale in a date range, aggregated by item, so a user can
 * see what is actually moving rather than one row per invoice. Cancelled and
 * out-of-range sales are excluded entirely, matching the register above it.
 *
 * Quantities are in BASE units (`(quantity + bonus_quantity) x
 * unit_conversion_factor`, the same figure Sale::post() and SalesReturn
 * move stock by, so free units count on both sides), not
 * whatever unit each line happened to be entered in. Selling 2 Boxes of 12
 * and 3 loose pieces is 27 pieces, not "5" (audit P1: alternate units were
 * being added to base units). For the same reason the grand total is a
 * per-unit breakdown rather than one number - adding Kilograms to Pieces
 * produces a figure nobody can use.
 *
 * Money (R01). A sale line stores only its own value after its own discount
 * (`line_total`); the document discount, VAT and Round Off live on the sale.
 * So every posted sale in scope is split back across ALL of its lines first
 * (see allocateSale(), the same weights SalesReturn uses for a return), and
 * only then are the item filters applied. Per item that gives:
 * - `total_value`: value after line and document discounts, before VAT;
 * - `vat_amount`: the item's share of the invoices' VAT;
 * - `gross_amount`: value + VAT + Round Off share.
 * With no item filter the grand totals equal the Sales Register's Subtotal,
 * VAT and Total for the same dates, store and branch, by construction.
 *
 * Returns (R01). Posted sales returns dated in the range are a separate set
 * of columns (`returned_*`) and `net_*` = sold - returned, never silently
 * netted into the sold figures, so "sold" still ties to the Sales Register
 * and "returned" to the Sales Return Register (which additionally shows a
 * cancellation of an older return as a negative row; this report does not).
 *
 * Audit T15-6: legacy's viewSalesReportItemWise() requires a specific item
 * and shows nothing but a per-line transaction ledger (date, invoice
 * number, customer, rate, quantity, VAT, discount, total) - it never
 * aggregates. This report keeps its own all-items aggregate as the default
 * view and, when an `item_id` is picked, also returns that item's rows (sale
 * lines and return lines) from the very same allocated set, so the detail
 * total equals the item's row in the summary.
 *
 * Audit T15-11: `category_id`, `subcategory_id`, `brand_id` and `search`
 * (item name) narrow both the aggregate and the item picker to a group of
 * items (validated with StockItemFilters' rules, kept under this report's
 * existing query names so links from Sales by Category keep working).
 */
class ItemWiseSalesReportController extends Controller
{
    use AggregatesItemWiseRows;
    use AllocatesDocumentDiscount;

    public function index(Request $request): Response
    {
        $branchFilter = BranchFilter::fromRequest($request);
        $report = $this->report($request, $branchFilter);

        return Inertia::render('Tenant/Reports/ItemWiseSales', [
            ...$report,
            ...StockItemFilters::options(),
            'stores' => BranchStock::storeOptions($branchFilter, Store::where('is_active', true))->orderBy('name')->get(['id', 'name']),
            'branchReport' => $branchFilter->payload(),
        ]);
    }

    /**
     * Same rows and totals as index(), as a real .xlsx: the item summary,
     * then the selected item's lines when one is picked.
     */
    public function export(Request $request): BinaryFileResponse
    {
        $branchFilter = BranchFilter::fromRequest($request);
        $report = $this->report($request, $branchFilter);

        return Excel::download(
            new ItemWiseSalesExport(
                $report['items']->all(),
                $report['totals'],
                $report['lines']->all(),
                $report['lineTotals'],
                $report['from'],
                $report['to'],
                $report['selectedItemName'],
                $branchFilter->isActive(),
                $branchFilter->isActive() ? $branchFilter->label() : null,
                $this->filterLabels($report),
            ),
            "item-wise-sales-{$report['from']}-to-{$report['to']}.xlsx",
        );
    }

    /**
     * The applied store and item filters as export preamble labels, so a
     * filtered file never reads as company totals.
     *
     * @param  array<string, mixed>  $report
     * @return list<string>
     */
    private function filterLabels(array $report): array
    {
        $labels = [];
        $named = [
            'Store' => [Store::class, $report['storeId']],
            'Category' => [ItemCategory::class, $report['categoryId']],
            'Subcategory' => [ItemSubcategory::class, $report['subcategoryId']],
            'Brand' => [Brand::class, $report['brandId']],
        ];

        foreach ($named as $label => [$model, $id]) {
            if ($id !== null) {
                $labels[] = $label.': '.$model::query()->whereKey($id)->value('name');
            }
        }

        if ($report['search'] !== '') {
            $labels[] = 'Item name contains: '.$report['search'];
        }

        if ($report['selectedItemName'] !== null) {
            $labels[] = 'Item: '.$report['selectedItemName'];
        }

        return $labels;
    }

    /**
     * The one builder both the screen and the export use, so they can never
     * disagree.
     *
     * @return array<string, mixed>
     */
    private function report(Request $request, BranchFilter $branchFilter): array
    {
        $itemFilterRules = StockItemFilters::rules();

        // Strict input: a malformed date, a reversed range or an id that does
        // not exist is a 422 the page shows, never a silent fallback.
        $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d', 'required_with:to'],
            'to' => ['nullable', 'date_format:Y-m-d', 'required_with:from', 'after_or_equal:from'],
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'item_id' => ['nullable', 'integer', 'exists:items,id'],
            'category_id' => $itemFilterRules['item_category_id'],
            'subcategory_id' => $itemFilterRules['item_subcategory_id'],
            'brand_id' => $itemFilterRules['brand_id'],
            'search' => $itemFilterRules['search'],
        ]);

        [$from, $to] = $this->resolveDateRange($request);
        $storeId = $branchFilter->allowedStoreId($request->integer('store_id') ?: null);
        $itemId = $request->integer('item_id') ?: null;
        $categoryId = $request->integer('category_id') ?: null;
        $subcategoryId = $request->integer('subcategory_id') ?: null;
        $brandId = $request->integer('brand_id') ?: null;
        $search = trim($request->string('search')->toString());

        $itemsList = Item::query()
            ->when($categoryId, fn (Builder $query) => $query->where('item_category_id', $categoryId))
            ->when($subcategoryId, fn (Builder $query) => $query->where('item_subcategory_id', $subcategoryId))
            ->when($brandId, fn (Builder $query) => $query->where('brand_id', $brandId))
            ->when($search !== '', fn (Builder $query) => $query->where('name', 'like', '%'.$search.'%'))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Item $item) => ['id' => $item->id, 'name' => $item->name])
            ->values();

        $hasItemFilter = $categoryId !== null || $subcategoryId !== null || $brandId !== null || $search !== '';
        $allowedItemIds = $hasItemFilter ? array_flip($itemsList->pluck('id')->all()) : null;
        $keep = fn (array $row): bool => $allowedItemIds === null || isset($allowedItemIds[$row['item_id']]);

        // An item outside the category / brand / search filter has no rows
        // here, so the drill-down is dropped rather than shown empty.
        if ($itemId !== null && $allowedItemIds !== null && ! isset($allowedItemIds[$itemId])) {
            $itemId = null;
        }

        // Documents are read a chunk at a time and folded into the running
        // per-item totals straight away; only the selected item's own lines
        // are kept, for the drill-down. Memory follows the item count, not
        // the line count.
        $byItem = [];
        $detailRows = [];

        foreach ([
            $this->saleRowBatches($from, $to, $storeId, $branchFilter),
            $this->returnRowBatches($from, $to, $storeId, $branchFilter),
        ] as $batches) {
            foreach ($batches as $batch) {
                foreach ($batch as $row) {
                    if (! $keep($row)) {
                        continue;
                    }

                    $this->accumulateItemRow($byItem, $row);

                    if ($itemId !== null && $row['item_id'] === $itemId) {
                        $detailRows[] = $row;
                    }
                }
            }
        }

        $items = $this->aggregatedItems($byItem);
        $lines = $itemId !== null ? $this->itemLineDetail($detailRows, $itemId, $branchFilter) : collect();

        return [
            'items' => $items,
            'totals' => $this->grandTotals($items),
            'lines' => $lines,
            'lineTotals' => $this->lineTotals($lines),
            'selectedItemName' => $itemId !== null ? Item::query()->whereKey($itemId)->value('name') : null,
            'itemsList' => $itemsList,
            'from' => $from,
            'to' => $to,
            'storeId' => $storeId,
            'itemId' => $itemId,
            'categoryId' => $categoryId,
            'subcategoryId' => $subcategoryId,
            'brandId' => $brandId,
            'search' => $search,
        ];
    }

    /**
     * The posted sale lines in scope: the one place the date, store and
     * branch rules live, shared by the id read and the per-chunk line read.
     *
     * @return Builder<SaleLine>
     */
    private function saleScope(string $from, string $to, ?int $storeId, BranchFilter $branchFilter): Builder
    {
        return SaleLine::query()
            ->join('sales', 'sales.id', '=', 'sale_lines.sale_id')
            ->leftJoin('customers', 'customers.id', '=', 'sales.customer_id')
            ->where('sales.status', 'posted')
            ->whereDate('sales.date', '>=', $from)
            ->whereDate('sales.date', '<=', $to)
            ->when($storeId, fn (Builder $query) => $query->where('sales.store_id', $storeId))
            ->tap(fn (Builder $query) => $branchFilter->applyToDocuments($query, 'sales.branch_id'));
    }

    /**
     * Every line of every posted sale in scope, a chunk of whole sales at a
     * time, with the sale's document discount, VAT and Round Off already
     * split onto it. Not item-filtered here: a sale is split across all its
     * lines, then the caller filters.
     *
     * @return Generator<int, list<array<string, mixed>>>
     */
    private function saleRowBatches(string $from, string $to, ?int $storeId, BranchFilter $branchFilter): Generator
    {
        $documentIds = $this->saleScope($from, $to, $storeId, $branchFilter)
            ->distinct()
            ->orderBy('sales.id')
            ->pluck('sales.id');

        foreach ($documentIds->chunk(self::DOCUMENT_CHUNK) as $chunk) {
            yield $this->saleRows($from, $to, $storeId, $branchFilter, $chunk->all());
        }
    }

    /**
     * @param  list<int>  $documentIds
     * @return list<array<string, mixed>>
     */
    private function saleRows(string $from, string $to, ?int $storeId, BranchFilter $branchFilter, array $documentIds): array
    {
        $lines = $this->saleScope($from, $to, $storeId, $branchFilter)
            ->whereIn('sales.id', $documentIds)
            ->orderBy('sales.id')
            ->orderBy('sale_lines.id')
            ->get([
                'sale_lines.id as id',
                'sale_lines.sale_id as document_id',
                'sale_lines.item_id as item_id',
                'sale_lines.quantity as quantity',
                'sale_lines.bonus_quantity as bonus_quantity',
                'sale_lines.unit_conversion_factor as unit_conversion_factor',
                'sale_lines.rate as rate',
                'sale_lines.line_total as line_total',
                'sale_lines.vatable as vatable',
                'sales.date as document_date',
                'sales.invoice_number as document_number',
                'sales.buyer_name as buyer_name',
                'customers.name as customer_name',
                'sales.taxable_amount as document_taxable',
                'sales.nontaxable_amount as document_nontaxable',
                'sales.vat_amount as document_vat',
                'sales.round_off as document_round_off',
                'sales.branch_id as branch_id',
            ]);

        $rows = [];

        foreach ($lines->groupBy('document_id') as $documentLines) {
            foreach ($this->allocateSale($documentLines) as $row) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * One sale's lines with the sale's stored amounts split onto them (see
     * splitDocument()).
     *
     * @param  Collection<int, Model>  $documentLines
     * @return list<array<string, mixed>>
     */
    private function allocateSale(Collection $documentLines): array
    {
        $header = $documentLines->first();
        $components = $this->splitDocument(
            $documentLines->mapWithKeys(fn (Model $line) => [
                (int) $line->id => ['line_total' => Money::of($line->line_total), 'vatable' => (bool) $line->vatable],
            ])->all(),
            Money::of($header->document_taxable ?? '0'),
            Money::of($header->document_nontaxable ?? '0'),
            Money::of($header->document_vat ?? '0'),
            Money::of($header->document_round_off ?? '0'),
        );

        $rows = [];

        foreach ($documentLines as $line) {
            $component = $components[(int) $line->id];
            $quantity = Quantity::of($line->quantity);
            $bonus = Quantity::of($line->bonus_quantity ?? '0');

            $rows[] = [
                'kind' => 'sale',
                'document_id' => (int) $line->document_id,
                'item_id' => (int) $line->item_id,
                'date' => substr((string) $line->document_date, 0, 10),
                'document_number' => $line->document_number,
                'party_name' => $line->buyer_name ?: $line->customer_name,
                'rate' => Quantity::of($line->rate),
                'quantity' => $quantity,
                'bonus_quantity' => $bonus,
                'base_quantity' => $this->baseQuantity($quantity->plus($bonus), $line->unit_conversion_factor),
                'line_total' => Money::of($line->line_total),
                'net_amount' => $component['net'],
                'vat_amount' => $component['vat'],
                'gross_amount' => $component['net']->plus($component['vat'])->plus($component['round_off']),
                'vatable' => (bool) $line->vatable,
                'branch_id' => $line->branch_id,
            ];
        }

        return $rows;
    }

    /**
     * Lines of posted sales returns dated in the range, signed negative so a
     * detail list of sales and returns sums to the net. A linked return line
     * stores its own net, VAT and Round Off share (C6) and takes its item and
     * unit from the sale line it returns. An unlinked return
     * (SalesReturn::postUnlinked()) stores VAT and Round Off only on the
     * credit note, so those are split across its lines with splitDocument(),
     * exactly as a sale is, before any item filter applies.
     *
     * Read a chunk of whole returns at a time.
     *
     * @return Generator<int, list<array<string, mixed>>>
     */
    private function returnRowBatches(string $from, string $to, ?int $storeId, BranchFilter $branchFilter): Generator
    {
        $documentIds = $this->returnScope($from, $to, $storeId, $branchFilter)
            ->distinct()
            ->orderBy('sales_returns.id')
            ->pluck('sales_returns.id');

        foreach ($documentIds->chunk(self::DOCUMENT_CHUNK) as $chunk) {
            yield $this->returnRows($from, $to, $storeId, $branchFilter, $chunk->all());
        }
    }

    /**
     * The posted return lines in scope (date, store and branch rules).
     *
     * @return Builder<SaleReturnLine>
     */
    private function returnScope(string $from, string $to, ?int $storeId, BranchFilter $branchFilter): Builder
    {
        return SaleReturnLine::query()
            ->join('sales_returns', 'sales_returns.id', '=', 'sale_return_lines.sales_return_id')
            ->leftJoin('sale_lines', 'sale_lines.id', '=', 'sale_return_lines.sale_line_id')
            ->leftJoin('sales', 'sales.id', '=', 'sales_returns.sale_id')
            ->leftJoin('customers as return_customers', 'return_customers.id', '=', 'sales_returns.customer_id')
            ->leftJoin('customers as sale_customers', 'sale_customers.id', '=', 'sales.customer_id')
            ->where('sales_returns.status', 'posted')
            ->whereDate('sales_returns.date', '>=', $from)
            ->whereDate('sales_returns.date', '<=', $to)
            ->when($storeId, fn (Builder $query) => $query->where('sales_returns.store_id', $storeId))
            ->tap(fn (Builder $query) => $branchFilter->applyToDocuments($query, 'sales_returns.branch_id'));
    }

    /**
     * @param  list<int>  $documentIds
     * @return list<array<string, mixed>>
     */
    private function returnRows(string $from, string $to, ?int $storeId, BranchFilter $branchFilter, array $documentIds): array
    {
        $lines = $this->returnScope($from, $to, $storeId, $branchFilter)
            ->whereIn('sales_returns.id', $documentIds)
            ->orderBy('sales_returns.id')
            ->orderBy('sale_return_lines.id')
            ->get([
                'sale_return_lines.id as id',
                'sale_return_lines.sales_return_id as document_id',
                'sale_return_lines.item_id as return_item_id',
                'sale_lines.item_id as sale_item_id',
                'sale_return_lines.quantity as quantity',
                'sale_return_lines.bonus_quantity as bonus_quantity',
                'sale_return_lines.unit_conversion_factor as return_factor',
                'sale_lines.unit_conversion_factor as sale_factor',
                'sale_return_lines.rate as rate',
                'sale_return_lines.line_total as line_total',
                'sale_return_lines.net_amount as net_amount',
                'sale_return_lines.vat_amount as vat_amount',
                'sale_return_lines.round_off as round_off',
                'sale_return_lines.vatable as return_vatable',
                'sale_lines.vatable as sale_vatable',
                'sales_returns.date as document_date',
                'sales_returns.credit_note_number as document_number',
                'sales_returns.sale_id as sale_id',
                'sales_returns.is_unlinked as is_unlinked',
                'sales_returns.taxable_amount as document_taxable',
                'sales_returns.nontaxable_amount as document_nontaxable',
                'sales_returns.vat_amount as document_vat',
                'sales_returns.round_off as document_round_off',
                'sales.buyer_name as buyer_name',
                'return_customers.name as return_customer_name',
                'sale_customers.name as sale_customer_name',
                'sales_returns.branch_id as branch_id',
            ]);

        $rows = [];

        foreach ($lines->groupBy('document_id') as $documentLines) {
            $header = $documentLines->first();
            $isUnlinked = (bool) $header->is_unlinked || $header->sale_id === null;
            $vatable = fn (Model $line): bool => (bool) ($line->sale_item_id !== null ? $line->sale_vatable : $line->return_vatable);

            // A line with no item cannot appear in an item report. It is
            // dropped BEFORE the credit note's VAT / Round Off / discount are
            // split, so the item lines absorb the whole stored document and
            // the returned total still ties to the Sales Return Register.
            $itemLines = $documentLines->filter(fn (Model $line): bool => ($line->return_item_id ?? $line->sale_item_id) !== null);

            $components = $isUnlinked
                ? $this->splitDocument(
                    $itemLines->mapWithKeys(fn (Model $line) => [
                        (int) $line->id => ['line_total' => Money::of($line->line_total), 'vatable' => $vatable($line)],
                    ])->all(),
                    Money::of($header->document_taxable ?? '0'),
                    Money::of($header->document_nontaxable ?? '0'),
                    Money::of($header->document_vat ?? '0'),
                    Money::of($header->document_round_off ?? '0'),
                )
                : $itemLines->mapWithKeys(fn (Model $line) => [(int) $line->id => [
                    'net' => Money::of($line->net_amount ?? $line->line_total),
                    'vat' => Money::of($line->vat_amount ?? '0'),
                    'round_off' => Money::of($line->round_off ?? '0'),
                ]])->all();

            foreach ($itemLines as $line) {
                $itemId = $line->return_item_id ?? $line->sale_item_id;

                $component = $components[(int) $line->id];
                $quantity = Quantity::of($line->quantity);
                $bonus = Quantity::of($line->bonus_quantity ?? '0');

                $rows[] = [
                    'kind' => 'return',
                    'document_id' => (int) $line->document_id,
                    'item_id' => (int) $itemId,
                    'date' => substr((string) $line->document_date, 0, 10),
                    'document_number' => $line->document_number,
                    'party_name' => $line->return_customer_name ?: ($line->buyer_name ?: $line->sale_customer_name),
                    'rate' => Quantity::of($line->rate),
                    'quantity' => $quantity->negated(),
                    'bonus_quantity' => $bonus->negated(),
                    'base_quantity' => $this->baseQuantity($quantity->plus($bonus), $line->return_factor ?? $line->sale_factor)->negated(),
                    'line_total' => Money::of($line->line_total)->negated(),
                    'net_amount' => $component['net']->negated(),
                    'vat_amount' => $component['vat']->negated(),
                    'gross_amount' => $component['net']->plus($component['vat'])->plus($component['round_off'])->negated(),
                    'vatable' => $vatable($line),
                    'branch_id' => $line->branch_id,
                ];
            }
        }

        return $rows;
    }

    private function baseQuantity(Quantity $quantity, mixed $conversionFactor): Quantity
    {
        return Quantity::round(
            $quantity->toBigDecimal()->multipliedBy(Quantity::of($conversionFactor ?? '1')->toBigDecimal())
        );
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function grandTotals(Collection $items): array
    {
        $sum = fn (string $key): string => Money::sum($items->map(fn (array $row) => Money::of($row[$key])))->toString();

        return [
            'total_value' => $sum('total_value'),
            'vat_amount' => $sum('vat_amount'),
            'gross_amount' => $sum('gross_amount'),
            'returned_value' => $sum('returned_value'),
            'returned_vat' => $sum('returned_vat'),
            'returned_gross' => $sum('returned_gross'),
            'net_value' => $sum('net_value'),
            'net_gross' => $sum('net_gross'),
            'quantities' => $this->quantitiesByUnit($items, 'total_quantity'),
            'returned_quantities' => $this->quantitiesByUnit($items, 'returned_quantity'),
            'net_quantities' => $this->quantitiesByUnit($items, 'net_quantity'),
        ];
    }

    /**
     * The selected item's sale and return lines, from the same rows as its
     * summary row, so the detail total equals that row by construction.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function itemLineDetail(array $rows, int $itemId, BranchFilter $branchFilter): Collection
    {
        return collect($rows)
            ->filter(fn (array $row) => $row['item_id'] === $itemId)
            ->sort(fn (array $a, array $b) => [$a['date'], $a['kind'] === 'sale' ? 0 : 1, $a['document_id']]
                <=> [$b['date'], $b['kind'] === 'sale' ? 0 : 1, $b['document_id']])
            ->map(fn (array $row) => [
                'kind' => $row['kind'],
                'document_id' => $row['document_id'],
                'date' => $row['date'],
                'document_number' => $row['document_number'],
                'party_name' => $row['party_name'],
                'rate' => $row['rate']->toString(),
                'quantity' => $row['quantity']->toString(),
                'bonus_quantity' => $row['bonus_quantity']->toString(),
                'base_quantity' => $row['base_quantity']->toString(),
                'line_total' => $row['line_total']->toString(),
                'net_amount' => $row['net_amount']->toString(),
                'vat_amount' => $row['vat_amount']->toString(),
                'gross_amount' => $row['gross_amount']->toString(),
                'vatable' => $row['vatable'],
                ...$branchFilter->rowBranch($row['branch_id']),
            ])
            ->values();
    }

    /**
     * Signed sums of the drill-down: equal to the item's net_quantity,
     * net_value and net_gross in the summary.
     *
     * @param  Collection<int, array<string, mixed>>  $lines
     * @return array{base_quantity: string, net_amount: string, vat_amount: string, gross_amount: string}
     */
    private function lineTotals(Collection $lines): array
    {
        return [
            'base_quantity' => Quantity::sum($lines->map(fn (array $line) => Quantity::of($line['base_quantity'])))->toString(),
            'net_amount' => Money::sum($lines->map(fn (array $line) => Money::of($line['net_amount'])))->toString(),
            'vat_amount' => Money::sum($lines->map(fn (array $line) => Money::of($line['vat_amount'])))->toString(),
            'gross_amount' => Money::sum($lines->map(fn (array $line) => Money::of($line['gross_amount'])))->toString(),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     * @return array<int, array{unit: string, quantity: string}>
     */
    private function quantitiesByUnit(Collection $items, string $key): array
    {
        $byUnit = [];

        foreach ($items as $row) {
            $unit = (string) ($row['unit'] ?? '');
            $byUnit[$unit] = ($byUnit[$unit] ?? Quantity::zero())->plus(Quantity::of($row[$key]));
        }

        $totals = [];

        foreach ($byUnit as $unit => $quantity) {
            if ($quantity->isZero() && $key !== 'total_quantity') {
                continue;
            }

            $totals[] = ['unit' => (string) $unit, 'quantity' => $quantity->toString()];
        }

        usort($totals, fn (array $a, array $b) => strcasecmp($a['unit'], $b['unit']));

        return $totals;
    }

    /**
     * Defaults to the current open fiscal year's date range when no
     * explicit `from`/`to` query params are given, falling back to
     * month-to-date if no fiscal year exists yet. Validation has already
     * rejected a malformed or one-sided range.
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
}
