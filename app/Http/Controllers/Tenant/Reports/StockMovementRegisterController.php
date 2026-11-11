<?php

namespace App\Http\Controllers\Tenant\Reports;

use App\Enums\FiscalYearStatus;
use App\Http\Controllers\Concerns\DescribesStockMovements;
use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemStockMovement;
use App\Models\ItemSubcategory;
use App\Models\Store;
use App\Support\Branches\BranchFilter;
use App\Support\Branches\BranchStock;
use App\Support\Money\Quantity;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Chronological audit trail of every individual stock movement in a date
 * range - the inventory-side sibling of AccountingReportController::
 * dayBook(). Unlike StockSummary/StockValuation (aggregates per item), this
 * lists one row per ItemStockMovement so a user can trace exactly what
 * happened to stock over time.
 *
 * Audit T15-11: `category_id`/`subcategory_id`/`brand_id` additionally
 * narrow the register to one item group's movements, via a `whereHas` on
 * the `item` relation (movements carry only `item_id`, not the group
 * columns, so this cannot be a plain `where()`) - the same "pivot from a
 * group aggregate into its lines" drill-down added to the item-wise
 * reports, for the register's own audience (stock, not sales/purchase
 * value).
 *
 * Branches (plan 12, BR15): `branch` / `branches[]` list only the movements
 * of the covered branches' stores (one whereIn on store_id); a `store_id`
 * outside them is dropped and the store dropdown offers only their stores.
 * `by_branch=1` adds the per-item, per-branch in/out/net summary to
 * `branchReport`. Not multi-branch: unchanged, plus `branchReport: null`.
 */
class StockMovementRegisterController extends Controller
{
    use DescribesStockMovements;

    public function index(Request $request): Response
    {
        [$from, $to] = $this->resolveDateRange($request);
        $filter = BranchFilter::fromRequest($request);
        $itemId = $request->integer('item_id') ?: null;
        $storeId = $filter->allowedStoreId($request->integer('store_id') ?: null);
        $branchStoreIds = $storeId === null ? $filter->storeIds() : null;
        $categoryId = $request->integer('category_id') ?: null;
        $subcategoryId = $request->integer('subcategory_id') ?: null;
        $brandId = $request->integer('brand_id') ?: null;

        $movements = ItemStockMovement::query()
            ->where('cancelled', false)
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->when($itemId, fn ($query) => $query->where('item_id', $itemId))
            ->when($storeId, fn ($query) => $query->where('store_id', $storeId))
            ->when($branchStoreIds !== null, fn ($query) => $query->whereIn('store_id', $branchStoreIds))
            ->when(
                $categoryId || $subcategoryId || $brandId,
                fn ($query) => $query->whereHas('item', function ($itemQuery) use ($categoryId, $subcategoryId, $brandId) {
                    $itemQuery
                        ->when($categoryId, fn ($q) => $q->where('item_category_id', $categoryId))
                        ->when($subcategoryId, fn ($q) => $q->where('item_subcategory_id', $subcategoryId))
                        ->when($brandId, fn ($q) => $q->where('brand_id', $brandId));
                })
            )
            ->with(['item:id,name,unit', 'store:id,name'])
            ->with($this->stockMovementReferenceEagerLoad())
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        return Inertia::render('Tenant/Reports/StockMovementRegister', [
            'movements' => $movements->map(fn (ItemStockMovement $movement) => [
                'date' => $movement->date->toDateString(),
                'itemId' => $movement->item_id,
                'itemName' => $movement->item->name,
                'storeName' => $movement->store?->name,
                'unit' => $movement->item->unit,
                'movementType' => $this->movementTypeLabel($movement->movement_type),
                'quantity' => $this->signedStockQuantity($movement)->toString(),
                'unitCostRate' => $movement->unit_cost_rate === null ? null : Quantity::of($movement->unit_cost_rate)->toString(),
                'reference' => $this->referenceDescription($movement->reference, $movement->narration),
            ])->values(),
            'items' => Item::query()->orderBy('name')->get(['id', 'name']),
            'categories' => ItemCategory::query()->orderBy('name')->get(['id', 'name']),
            'subcategories' => ItemSubcategory::query()->orderBy('name')->get(['id', 'name', 'item_category_id']),
            'brands' => Brand::query()->orderBy('name')->get(['id', 'name']),
            'stores' => BranchStock::storeOptions($filter, Store::where('is_active', true))->orderBy('name')->get(['id', 'name']),
            'from' => $from,
            'to' => $to,
            'itemId' => $itemId,
            'storeId' => $storeId,
            'categoryId' => $categoryId,
            'subcategoryId' => $subcategoryId,
            'brandId' => $brandId,
            'branchReport' => $this->branchReport($filter, $movements, $storeId),
        ]);
    }

    /**
     * The `branchReport` prop: BranchFilter::payload(), plus with
     * `by_branch=1` (and no single store chosen) the register's movements
     * summed per item and branch: `columns` and `summary`, one row per item
     * with `byBranch` (branch id => in, out, net, signed net) and `total`.
     * Quantities stay per item, never added across units. Built in PHP from
     * the movements already loaded, so it costs no query beyond the store
     * map. Every leg counts in its own store's branch, so a transfer between
     * branches is an out in one and an in in the other.
     *
     * @param  Collection<int, ItemStockMovement>  $movements
     * @return array<string, mixed>|null
     */
    private function branchReport(BranchFilter $filter, Collection $movements, ?int $storeId): ?array
    {
        $payload = $filter->payload(supportsByBranch: $storeId === null);

        if ($payload === null || ! $payload['byBranch']) {
            return $payload;
        }

        $columns = BranchStock::columns($filter);
        $storeBranches = $filter->storeBranches();
        $zero = fn (): array => ['in' => Quantity::zero(), 'out' => Quantity::zero()];
        $summary = [];

        foreach ($movements as $movement) {
            $itemId = (int) $movement->item_id;
            $branchId = $storeBranches[(int) $movement->store_id] ?? null;
            $quantity = Quantity::of($movement->quantity);
            $side = $movement->movement_type->direction() === 1 ? 'in' : 'out';

            $summary[$itemId] ??= [
                'itemId' => $itemId,
                'itemName' => $movement->item->name,
                'unit' => $movement->item->unit,
                'byBranch' => array_fill_keys(array_column($columns, 'id'), null),
                'total' => $zero(),
            ];

            if ($branchId !== null && array_key_exists($branchId, $summary[$itemId]['byBranch'])) {
                $summary[$itemId]['byBranch'][$branchId] ??= $zero();
                $summary[$itemId]['byBranch'][$branchId][$side] = $summary[$itemId]['byBranch'][$branchId][$side]->plus($quantity);
            }

            $summary[$itemId]['total'][$side] = $summary[$itemId]['total'][$side]->plus($quantity);
        }

        $present = fn (?array $figures): array => [
            'in' => ($figures['in'] ?? Quantity::zero())->toString(),
            'out' => ($figures['out'] ?? Quantity::zero())->toString(),
            'net' => ($figures['in'] ?? Quantity::zero())->minus($figures['out'] ?? Quantity::zero())->toString(),
        ];

        $rows = array_map(fn (array $row): array => [
            ...$row,
            'byBranch' => array_map($present, $row['byBranch']),
            'total' => $present($row['total']),
        ], array_values($summary));

        usort($rows, fn (array $a, array $b): int => strcasecmp((string) $a['itemName'], (string) $b['itemName']));

        return [...$payload, 'columns' => $columns, 'summary' => $rows];
    }

    /**
     * Defaults to the current open fiscal year's date range when no
     * explicit `from`/`to` query params are given, falling back to
     * month-to-date if no fiscal year exists yet. Duplicated from
     * SalesPurchaseReportController::resolveDateRange() rather than shared,
     * matching this app's existing per-controller-file convention.
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
