<?php

namespace App\Http\Controllers\Tenant\Reports;

use App\Enums\FiscalYearStatus;
use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemSubcategory;
use App\Models\PurchaseLine;
use App\Models\Store;
use App\Support\Money\Money;
use App\Support\Money\Quantity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Item-level counterpart to the invoice-level Purchase Register: every
 * PurchaseLine of every posted Purchase in a date range, aggregated by item,
 * so a user can see what is actually being bought rather than one row per
 * invoice. Cancelled and out-of-range purchases are excluded entirely,
 * matching the register above it.
 *
 * Quantities are in BASE units (`quantity x unit_conversion_factor`), not
 * whatever unit each line happened to be entered in. Buying 2 Boxes of 12
 * and 3 loose pieces is 27 pieces, not "5" (audit P1: alternate units were
 * being added to base units). For the same reason the grand total is a
 * per-unit breakdown rather than one number - adding Kilograms to Pieces
 * produces a figure nobody can use.
 *
 * Audit T15-6: legacy's viewPurchaseReportItemWise() requires a specific
 * item and shows nothing but a per-line transaction ledger (date, bill
 * number, supplier, rate, quantity, vatable flag) - it never aggregates.
 * This report keeps its own (more useful) all-items aggregate as the
 * default view, but when an `item_id` is picked it additionally returns
 * that item's raw per-line rows alongside the aggregate, so "show me every
 * purchase of item X, at what rate, from which supplier" has an answer here
 * too.
 *
 * Audit T15-11: legacy also had a single-category/subcategory/brand
 * drill-down. Rather than a second set of endpoints, `category_id`,
 * `subcategory_id` and `brand_id` narrow this same report - both the
 * aggregate and the item picker - to that group's items, so "pivot from a
 * category total into its lines" is answered here too. `item_id` is the
 * more specific filter: when it is set it wins outright (the group filters
 * still narrow which items appear in the all-items aggregate/picker, but
 * the single-item drill-down below is keyed on `item_id` alone).
 */
class ItemWisePurchaseReportController extends Controller
{
    public function index(Request $request): Response
    {
        [$from, $to] = $this->resolveDateRange($request);
        $storeId = $request->integer('store_id') ?: null;
        $itemId = $request->integer('item_id') ?: null;
        $categoryId = $request->integer('category_id') ?: null;
        $subcategoryId = $request->integer('subcategory_id') ?: null;
        $brandId = $request->integer('brand_id') ?: null;

        $lines = PurchaseLine::query()
            ->join('purchases', 'purchases.id', '=', 'purchase_lines.purchase_id')
            ->join('items', 'items.id', '=', 'purchase_lines.item_id')
            ->where('purchases.status', 'posted')
            ->whereDate('purchases.date', '>=', $from)
            ->whereDate('purchases.date', '<=', $to)
            ->when($storeId, fn (Builder $query) => $query->where('purchases.store_id', $storeId))
            ->when($categoryId, fn (Builder $query) => $query->where('items.item_category_id', $categoryId))
            ->when($subcategoryId, fn (Builder $query) => $query->where('items.item_subcategory_id', $subcategoryId))
            ->when($brandId, fn (Builder $query) => $query->where('items.brand_id', $brandId))
            ->get([
                'purchase_lines.item_id as item_id',
                'purchase_lines.purchase_id as document_id',
                'purchase_lines.quantity as quantity',
                'purchase_lines.unit_conversion_factor as unit_conversion_factor',
                'purchase_lines.line_total as line_total',
                'items.name as name',
                'items.unit as unit',
            ]);

        $items = $this->aggregateByItem($lines);

        return Inertia::render('Tenant/Reports/ItemWisePurchase', [
            'items' => $items,
            'totals' => [
                'total_value' => Money::sum($items->map(fn (array $row) => Money::of($row['total_value'])))->toString(),
                'quantities' => $this->quantitiesByUnit($items),
            ],
            'lines' => $itemId ? $this->itemLineDetail($itemId, $from, $to, $storeId) : [],
            'itemsList' => Item::query()
                ->when($categoryId, fn (Builder $query) => $query->where('item_category_id', $categoryId))
                ->when($subcategoryId, fn (Builder $query) => $query->where('item_subcategory_id', $subcategoryId))
                ->when($brandId, fn (Builder $query) => $query->where('brand_id', $brandId))
                ->orderBy('name')
                ->get(['id', 'name']),
            'categories' => ItemCategory::query()->orderBy('name')->get(['id', 'name']),
            'subcategories' => ItemSubcategory::query()->orderBy('name')->get(['id', 'name', 'item_category_id']),
            'brands' => Brand::query()->orderBy('name')->get(['id', 'name']),
            'stores' => Store::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'from' => $from,
            'to' => $to,
            'storeId' => $storeId,
            'itemId' => $itemId,
            'categoryId' => $categoryId,
            'subcategoryId' => $subcategoryId,
            'brandId' => $brandId,
        ]);
    }

    /**
     * The raw per-line transaction ledger for a single item, i.e. legacy's
     * entire viewPurchaseReportItemWise() view - one row per PurchaseLine,
     * not folded into any aggregate, so a shopkeeper can see exactly which
     * bill, at what rate, from which supplier.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function itemLineDetail(int $itemId, string $from, string $to, ?int $storeId): Collection
    {
        return PurchaseLine::query()
            ->join('purchases', 'purchases.id', '=', 'purchase_lines.purchase_id')
            ->leftJoin('suppliers', 'suppliers.id', '=', 'purchases.supplier_id')
            ->where('purchases.status', 'posted')
            ->where('purchase_lines.item_id', $itemId)
            ->whereDate('purchases.date', '>=', $from)
            ->whereDate('purchases.date', '<=', $to)
            ->when($storeId, fn (Builder $query) => $query->where('purchases.store_id', $storeId))
            ->orderBy('purchases.date')
            ->orderBy('purchases.id')
            ->get([
                'purchases.date as date',
                'purchases.bill_number as document_number',
                'suppliers.name as party_name',
                'purchase_lines.rate as rate',
                'purchase_lines.quantity as quantity',
                'purchase_lines.line_total as line_total',
                'purchase_lines.vatable as vatable',
            ])
            ->map(fn (Model $line) => [
                'date' => (string) $line->date,
                'document_number' => $line->document_number,
                'party_name' => $line->party_name,
                'rate' => Quantity::of($line->rate)->toString(),
                'quantity' => Quantity::of($line->quantity)->toString(),
                'line_total' => Money::of($line->line_total)->toString(),
                'vatable' => (bool) $line->vatable,
            ])
            ->values();
    }

    /**
     * @param  Collection<int, Model>  $lines
     * @return Collection<int, array<string, mixed>>
     */
    private function aggregateByItem(Collection $lines): Collection
    {
        $byItem = [];

        foreach ($lines as $line) {
            $itemId = (int) $line->item_id;

            $byItem[$itemId] ??= [
                'item_id' => $itemId,
                'name' => $line->name,
                'unit' => $line->unit,
                'quantity' => Quantity::zero(),
                'value' => Money::zero(),
                'documents' => [],
            ];

            $byItem[$itemId]['quantity'] = $byItem[$itemId]['quantity']->plus(
                Quantity::round(
                    Quantity::of($line->quantity)->toBigDecimal()
                        ->multipliedBy(Quantity::of($line->unit_conversion_factor ?? '1')->toBigDecimal())
                )
            );
            $byItem[$itemId]['value'] = $byItem[$itemId]['value']->plus(Money::of($line->line_total));
            $byItem[$itemId]['documents'][(int) $line->document_id] = true;
        }

        return collect($byItem)
            ->map(fn (array $row) => [
                'item_id' => $row['item_id'],
                'name' => $row['name'],
                'unit' => $row['unit'],
                'total_quantity' => $row['quantity']->toString(),
                'total_value' => $row['value']->toString(),
                'transaction_count' => count($row['documents']),
            ])
            ->sort(fn (array $a, array $b) => Money::of($b['total_value'])->compareTo(Money::of($a['total_value'])))
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     * @return array<int, array{unit: string, quantity: string}>
     */
    private function quantitiesByUnit(Collection $items): array
    {
        $byUnit = [];

        foreach ($items as $row) {
            $unit = (string) ($row['unit'] ?? '');
            $byUnit[$unit] = ($byUnit[$unit] ?? Quantity::zero())->plus(Quantity::of($row['total_quantity']));
        }

        $totals = [];

        foreach ($byUnit as $unit => $quantity) {
            $totals[] = ['unit' => $unit, 'quantity' => $quantity->toString()];
        }

        usort($totals, fn (array $a, array $b) => strcasecmp($a['unit'], $b['unit']));

        return $totals;
    }

    /**
     * Defaults to the current open fiscal year's date range when no
     * explicit `from`/`to` query params are given, falling back to
     * month-to-date if no fiscal year exists yet.
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
