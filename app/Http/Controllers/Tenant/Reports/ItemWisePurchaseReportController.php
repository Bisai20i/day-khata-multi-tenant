<?php

namespace App\Http\Controllers\Tenant\Reports;

use App\Enums\FiscalYearStatus;
use App\Exports\ItemWisePurchaseExport;
use App\Http\Controllers\Concerns\AggregatesItemWiseRows;
use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemSubcategory;
use App\Models\PurchaseLine;
use App\Models\PurchaseReturnLine;
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
 * Item-level counterpart to the invoice-level Purchase Register: every
 * PurchaseLine of every posted Purchase in a date range, aggregated by item,
 * so a user can see what is actually being bought rather than one row per
 * invoice. Cancelled and out-of-range purchases are excluded entirely,
 * matching the register above it.
 *
 * Quantities are in BASE units and include bonus (free) units, the same
 * figure the stock movements carry: a purchase line is `(quantity +
 * bonus_quantity) x factor`, a return line `quantity x factor` (its quantity
 * already includes the bonus portion). Not
 * whatever unit each line happened to be entered in. Buying 2 Boxes of 12
 * and 3 loose pieces is 27 pieces, not "5" (audit P1: alternate units were
 * being added to base units). For the same reason the grand total is a
 * per-unit breakdown rather than one number - adding Kilograms to Pieces
 * produces a figure nobody can use.
 *
 * Money (R01). A purchase line stores its own exact share of the bill at
 * posting: `net_value` (after line and document discounts, before VAT),
 * `vat_amount` and `round_off`, allocated largest-remainder so they sum back
 * to the bill. Per item that gives:
 * - `total_value`: the summed `net_value` (before VAT);
 * - `vat_amount`: the summed VAT shares;
 * - `gross_amount`: value + VAT + Round Off share.
 * With no item filter the grand totals equal the Purchase Register's
 * Subtotal, VAT and Total for the same dates, store and branch.
 *
 * Returns (R01). Posted purchase returns dated in the range are a separate
 * set of columns (`returned_*`) and `net_*` = bought - returned, never
 * silently netted into the bought figures, so "bought" still ties to the
 * Purchase Register and "returned" to the Purchase Return Register (which
 * additionally shows a cancellation of an older return as a negative row;
 * this report does not).
 *
 * Audit T15-6: legacy's viewPurchaseReportItemWise() requires a specific
 * item and shows nothing but a per-line transaction ledger (date, bill
 * number, supplier, rate, quantity, VAT, discount, total) - it never
 * aggregates. This report keeps its own all-items aggregate as the default
 * view and, when an `item_id` is picked, also returns that item's rows
 * (purchase lines and return lines) from the very same set, so the detail
 * total equals the item's row in the summary.
 *
 * Audit T15-11: `category_id`, `subcategory_id`, `brand_id` and `search`
 * (item name) narrow both the aggregate and the item picker to a group of
 * items (validated with StockItemFilters' rules, kept under this report's
 * existing query names so links from Purchase by Category keep working).
 */
class ItemWisePurchaseReportController extends Controller
{
    use AggregatesItemWiseRows;

    public function index(Request $request): Response
    {
        $branchFilter = BranchFilter::fromRequest($request);
        $report = $this->report($request, $branchFilter);

        return Inertia::render('Tenant/Reports/ItemWisePurchase', [
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
            new ItemWisePurchaseExport(
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
            "item-wise-purchase-{$report['from']}-to-{$report['to']}.xlsx",
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
            $this->purchaseRowBatches($from, $to, $storeId, $branchFilter),
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
     * Every line of every posted purchase in scope, with the stored per-line
     * components. A row posted before those columns existed falls back to
     * its own line value (no VAT, no Round Off share).
     *
     * Read a chunk of whole purchases at a time.
     *
     * @return Generator<int, list<array<string, mixed>>>
     */
    private function purchaseRowBatches(string $from, string $to, ?int $storeId, BranchFilter $branchFilter): Generator
    {
        $documentIds = $this->purchaseScope($from, $to, $storeId, $branchFilter)
            ->distinct()
            ->orderBy('purchases.id')
            ->pluck('purchases.id');

        foreach ($documentIds->chunk(self::DOCUMENT_CHUNK) as $chunk) {
            yield $this->purchaseRows($from, $to, $storeId, $branchFilter, $chunk->all());
        }
    }

    /**
     * The posted purchase lines in scope (date, store and branch rules).
     *
     * @return Builder<PurchaseLine>
     */
    private function purchaseScope(string $from, string $to, ?int $storeId, BranchFilter $branchFilter): Builder
    {
        return PurchaseLine::query()
            ->join('purchases', 'purchases.id', '=', 'purchase_lines.purchase_id')
            ->leftJoin('suppliers', 'suppliers.id', '=', 'purchases.supplier_id')
            ->where('purchases.status', 'posted')
            ->whereDate('purchases.date', '>=', $from)
            ->whereDate('purchases.date', '<=', $to)
            ->when($storeId, fn (Builder $query) => $query->where('purchases.store_id', $storeId))
            ->tap(fn (Builder $query) => $branchFilter->applyToDocuments($query, 'purchases.branch_id'));
    }

    /**
     * @param  list<int>  $documentIds
     * @return list<array<string, mixed>>
     */
    private function purchaseRows(string $from, string $to, ?int $storeId, BranchFilter $branchFilter, array $documentIds): array
    {
        return $this->purchaseScope($from, $to, $storeId, $branchFilter)
            ->whereIn('purchases.id', $documentIds)
            ->orderBy('purchases.id')
            ->orderBy('purchase_lines.id')
            ->get([
                'purchase_lines.id as id',
                'purchase_lines.purchase_id as document_id',
                'purchase_lines.item_id as item_id',
                'purchase_lines.quantity as quantity',
                'purchase_lines.bonus_quantity as bonus_quantity',
                'purchase_lines.unit_conversion_factor as unit_conversion_factor',
                'purchase_lines.rate as rate',
                'purchase_lines.line_total as line_total',
                'purchase_lines.net_value as net_value',
                'purchase_lines.vat_amount as vat_amount',
                'purchase_lines.round_off as round_off',
                'purchase_lines.vatable as vatable',
                'purchases.date as document_date',
                'purchases.bill_number as document_number',
                'suppliers.name as party_name',
                'purchases.branch_id as branch_id',
            ])
            ->map(function (Model $line): array {
                $quantity = Quantity::of($line->quantity);
                $bonus = Quantity::of($line->bonus_quantity ?? '0');
                $net = Money::of($line->net_value ?? $line->line_total);
                $vat = Money::of($line->vat_amount ?? '0');

                return [
                    'kind' => 'purchase',
                    'document_id' => (int) $line->document_id,
                    'item_id' => (int) $line->item_id,
                    'date' => substr((string) $line->document_date, 0, 10),
                    'document_number' => $line->document_number,
                    'party_name' => $line->party_name,
                    'rate' => Quantity::of($line->rate),
                    'quantity' => $quantity,
                    'bonus_quantity' => $bonus,
                    // Paid plus bonus: what Purchase::post() puts on the shelf.
                    'base_quantity' => $this->baseQuantity($quantity->plus($bonus), $line->unit_conversion_factor),
                    'line_total' => Money::of($line->line_total),
                    'net_amount' => $net,
                    'vat_amount' => $vat,
                    'gross_amount' => $net->plus($vat)->plus(Money::of($line->round_off ?? '0')),
                    'vatable' => (bool) $line->vatable,
                    'branch_id' => $line->branch_id,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Lines of posted purchase returns dated in the range, signed negative
     * so a detail list of purchases and returns sums to the net. Each return
     * line stores its own net, VAT and Round Off share (C6). A linked line
     * takes its item and unit from the purchase line it returns.
     *
     * Read a chunk of whole returns at a time.
     *
     * @return Generator<int, list<array<string, mixed>>>
     */
    private function returnRowBatches(string $from, string $to, ?int $storeId, BranchFilter $branchFilter): Generator
    {
        $documentIds = $this->returnScope($from, $to, $storeId, $branchFilter)
            ->distinct()
            ->orderBy('purchase_returns.id')
            ->pluck('purchase_returns.id');

        foreach ($documentIds->chunk(self::DOCUMENT_CHUNK) as $chunk) {
            yield $this->returnRows($from, $to, $storeId, $branchFilter, $chunk->all());
        }
    }

    /**
     * The posted return lines in scope (date, store and branch rules).
     *
     * @return Builder<PurchaseReturnLine>
     */
    private function returnScope(string $from, string $to, ?int $storeId, BranchFilter $branchFilter): Builder
    {
        return PurchaseReturnLine::query()
            ->join('purchase_returns', 'purchase_returns.id', '=', 'purchase_return_lines.purchase_return_id')
            ->leftJoin('purchase_lines', 'purchase_lines.id', '=', 'purchase_return_lines.purchase_line_id')
            ->leftJoin('purchases', 'purchases.id', '=', 'purchase_returns.purchase_id')
            ->leftJoin('suppliers as return_suppliers', 'return_suppliers.id', '=', 'purchase_returns.supplier_id')
            ->leftJoin('suppliers as purchase_suppliers', 'purchase_suppliers.id', '=', 'purchases.supplier_id')
            ->where('purchase_returns.status', 'posted')
            ->whereDate('purchase_returns.date', '>=', $from)
            ->whereDate('purchase_returns.date', '<=', $to)
            ->when($storeId, fn (Builder $query) => $query->where('purchase_returns.store_id', $storeId))
            ->tap(fn (Builder $query) => $branchFilter->applyToDocuments($query, 'purchase_returns.branch_id'));
    }

    /**
     * @param  list<int>  $documentIds
     * @return list<array<string, mixed>>
     */
    private function returnRows(string $from, string $to, ?int $storeId, BranchFilter $branchFilter, array $documentIds): array
    {
        return $this->returnScope($from, $to, $storeId, $branchFilter)
            ->whereIn('purchase_returns.id', $documentIds)
            ->orderBy('purchase_returns.id')
            ->orderBy('purchase_return_lines.id')
            ->get([
                'purchase_return_lines.id as id',
                'purchase_return_lines.purchase_return_id as document_id',
                'purchase_return_lines.item_id as return_item_id',
                'purchase_lines.item_id as purchase_item_id',
                'purchase_return_lines.quantity as quantity',
                'purchase_return_lines.bonus_quantity as bonus_quantity',
                'purchase_return_lines.unit_conversion_factor as return_factor',
                'purchase_lines.unit_conversion_factor as purchase_factor',
                'purchase_return_lines.rate as rate',
                'purchase_return_lines.line_total as line_total',
                'purchase_return_lines.net_value as net_value',
                'purchase_return_lines.vat_amount as vat_amount',
                'purchase_return_lines.round_off as round_off',
                'purchase_lines.vatable as purchase_vatable',
                'purchase_returns.date as document_date',
                'purchase_returns.debit_note_number as document_number',
                'return_suppliers.name as return_supplier_name',
                'purchase_suppliers.name as purchase_supplier_name',
                'purchase_returns.branch_id as branch_id',
            ])
            ->filter(fn (Model $line) => ($line->return_item_id ?? $line->purchase_item_id) !== null)
            ->map(function (Model $line): array {
                // A return line's quantity already includes the bonus units it
                // took back (PurchaseReturn::prepareLine() splits it; bonus_quantity
                // is that split), so paid = quantity - bonus and the stock moved
                // is quantity x factor.
                $quantity = Quantity::of($line->quantity);
                $bonus = Quantity::of($line->bonus_quantity ?? '0');
                $net = Money::of($line->net_value ?? $line->line_total);
                $vat = Money::of($line->vat_amount ?? '0');

                return [
                    'kind' => 'return',
                    'document_id' => (int) $line->document_id,
                    'item_id' => (int) ($line->return_item_id ?? $line->purchase_item_id),
                    'date' => substr((string) $line->document_date, 0, 10),
                    'document_number' => $line->document_number,
                    'party_name' => $line->return_supplier_name ?: $line->purchase_supplier_name,
                    'rate' => Quantity::of($line->rate),
                    'quantity' => $quantity->minus($bonus)->negated(),
                    'bonus_quantity' => $bonus->negated(),
                    'base_quantity' => $this->baseQuantity($quantity, $line->return_factor ?? $line->purchase_factor)->negated(),
                    'line_total' => Money::of($line->line_total)->negated(),
                    'net_amount' => $net->negated(),
                    'vat_amount' => $vat->negated(),
                    'gross_amount' => $net->plus($vat)->plus(Money::of($line->round_off ?? '0'))->negated(),
                    // An unlinked return line has no vatable flag of its own; it carried VAT or it did not.
                    'vatable' => $line->purchase_item_id !== null ? (bool) $line->purchase_vatable : ! $vat->isZero(),
                    'branch_id' => $line->branch_id,
                ];
            })
            ->values()
            ->all();
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
     * The selected item's purchase and return lines, from the same rows as its
     * summary row, so the detail total equals that row by construction.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function itemLineDetail(array $rows, int $itemId, BranchFilter $branchFilter): Collection
    {
        return collect($rows)
            ->filter(fn (array $row) => $row['item_id'] === $itemId)
            ->sort(fn (array $a, array $b) => [$a['date'], $a['kind'] === 'purchase' ? 0 : 1, $a['document_id']]
                <=> [$b['date'], $b['kind'] === 'purchase' ? 0 : 1, $b['document_id']])
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
