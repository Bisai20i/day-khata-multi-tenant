<?php

namespace App\Http\Controllers\Tenant\Reports;

use App\Enums\FiscalYearStatus;
use App\Enums\StockMovementType;
use App\Exports\StockMovementRegisterExport;
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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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
 * columns, so this cannot be a plain `where()`).
 *
 * Legacy parity (in-only / out-only reports): `direction` (all | in | out)
 * keeps only the movement types whose StockMovementType::direction() points
 * that way, and `type` keeps one movement type. Both narrow in SQL.
 *
 * `totals` is total in, total out and net per base unit over the whole
 * filtered result - never one figure across units (3 Kg plus 2 Pcs is not
 * "5"). The screen shows at most SCREEN_ROW_LIMIT rows (`truncated` says
 * so); the totals and the Excel export always cover every row.
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

    /**
     * Rows sent to the screen at most. Past this the page would ship a
     * multi-megabyte prop to paginate client-side; the user narrows the
     * range or exports instead.
     */
    private const SCREEN_ROW_LIMIT = 5000;

    public function index(Request $request): Response
    {
        $filter = BranchFilter::fromRequest($request);
        $register = $this->register($request, $filter, self::SCREEN_ROW_LIMIT);

        return Inertia::render('Tenant/Reports/StockMovementRegister', [
            'movements' => $register['rows'],
            'totals' => $register['totals'],
            'truncated' => $register['truncated'],
            'rowLimit' => self::SCREEN_ROW_LIMIT,
            'items' => Item::query()->orderBy('name')->get(['id', 'name']),
            'categories' => ItemCategory::query()->orderBy('name')->get(['id', 'name']),
            'subcategories' => ItemSubcategory::query()->orderBy('name')->get(['id', 'name', 'item_category_id']),
            'brands' => Brand::query()->orderBy('name')->get(['id', 'name']),
            'stores' => BranchStock::storeOptions($filter, Store::where('is_active', true))->orderBy('name')->get(['id', 'name']),
            'movementTypes' => $this->movementTypeOptions(),
            'from' => $register['from'],
            'to' => $register['to'],
            'itemId' => $register['itemId'],
            'storeId' => $register['storeId'],
            'categoryId' => $register['categoryId'],
            'subcategoryId' => $register['subcategoryId'],
            'brandId' => $register['brandId'],
            'direction' => $register['direction'],
            'movementType' => $register['movementType'],
            'branchReport' => $this->branchReport($filter, $register['query'], $register['storeId']),
        ]);
    }

    /**
     * Same rows and totals as index() for the same query string, every row
     * (never truncated), streamed as a real .xlsx.
     */
    public function export(Request $request): BinaryFileResponse
    {
        $filter = BranchFilter::fromRequest($request);
        $register = $this->register($request, $filter, null);

        return Excel::download(
            new StockMovementRegisterExport(
                $register['rows'],
                $register['totals'],
                $register['from'],
                $register['to'],
                $filter->isActive() ? $filter->label() : null,
            ),
            "stock-movement-register-{$register['from']}-to-{$register['to']}.xlsx",
        );
    }

    /**
     * The register shared by index() and export(): validated filters, the
     * presented rows (the first $rowLimit when given, else all of them), and
     * the per-unit totals over the whole filtered result.
     *
     * @return array{rows: list<array<string, mixed>>, totals: list<array{unit: string, in: string, out: string, net: string}>, truncated: bool, query: Builder<ItemStockMovement>, from: string, to: string, itemId: int|null, storeId: int|null, categoryId: int|null, subcategoryId: int|null, brandId: int|null, direction: string, movementType: string|null}
     */
    private function register(Request $request, BranchFilter $filter, ?int $rowLimit): array
    {
        $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'item_id' => ['nullable', 'integer', 'exists:items,id'],
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'category_id' => ['nullable', 'integer', 'exists:item_categories,id'],
            'subcategory_id' => ['nullable', 'integer', 'exists:item_subcategories,id'],
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'direction' => ['nullable', Rule::in(['all', 'in', 'out'])],
            'type' => ['nullable', Rule::enum(StockMovementType::class)],
        ]);

        [$from, $to] = $this->resolveDateRange($request);
        $itemId = $request->integer('item_id') ?: null;
        $storeId = $filter->allowedStoreId($request->integer('store_id') ?: null);
        $categoryId = $request->integer('category_id') ?: null;
        $subcategoryId = $request->integer('subcategory_id') ?: null;
        $brandId = $request->integer('brand_id') ?: null;
        $direction = $request->string('direction')->toString() ?: 'all';
        $movementType = $request->string('type')->toString() ?: null;

        $query = $this->movementQuery(
            $from, $to, $itemId, $storeId, $storeId === null ? $filter->storeIds() : null,
            $categoryId, $subcategoryId, $brandId, $direction, $movementType,
        );

        $rowsQuery = (clone $query)
            ->with(['item:id,name,unit', 'store:id,name'])
            ->with($this->stockMovementReferenceEagerLoad())
            ->orderBy('date')
            ->orderBy('id');

        $rows = [];
        $byUnit = [];
        $truncated = false;

        if ($rowLimit === null) {
            foreach ($rowsQuery->lazy(1000) as $movement) {
                $rows[] = $this->presentMovement($movement);
                $this->addToUnitTotals($byUnit, $movement);
            }
        } else {
            $loaded = $rowsQuery->limit($rowLimit + 1)->get();
            $truncated = $loaded->count() > $rowLimit;

            foreach ($loaded->take($rowLimit) as $movement) {
                $rows[] = $this->presentMovement($movement);

                if (! $truncated) {
                    $this->addToUnitTotals($byUnit, $movement);
                }
            }

            // The screen got only the first rows; the totals still cover all.
            if ($truncated) {
                foreach ($this->slimMovements($query) as $movement) {
                    $this->addToUnitTotals($byUnit, $movement);
                }
            }
        }

        return [
            'rows' => $rows,
            'totals' => $this->presentUnitTotals($byUnit),
            'truncated' => $truncated,
            'query' => $query,
            'from' => $from,
            'to' => $to,
            'itemId' => $itemId,
            'storeId' => $storeId,
            'categoryId' => $categoryId,
            'subcategoryId' => $subcategoryId,
            'brandId' => $brandId,
            'direction' => $direction,
            'movementType' => $movementType,
        ];
    }

    /**
     * The filtered, unordered movement query every pass starts from.
     *
     * @param  list<int>|null  $branchStoreIds
     * @return Builder<ItemStockMovement>
     */
    private function movementQuery(
        string $from,
        string $to,
        ?int $itemId,
        ?int $storeId,
        ?array $branchStoreIds,
        ?int $categoryId,
        ?int $subcategoryId,
        ?int $brandId,
        string $direction,
        ?string $movementType,
    ): Builder {
        $directionTypes = match ($direction) {
            'in' => $this->movementTypeValues(1),
            'out' => $this->movementTypeValues(-1),
            default => null,
        };

        return ItemStockMovement::query()
            ->where('cancelled', false)
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->when($itemId, fn ($query) => $query->where('item_id', $itemId))
            ->when($storeId, fn ($query) => $query->where('store_id', $storeId))
            ->when($branchStoreIds !== null, fn ($query) => $query->whereIn('store_id', $branchStoreIds))
            ->when($directionTypes !== null, fn ($query) => $query->whereIn('movement_type', $directionTypes))
            ->when($movementType !== null, fn ($query) => $query->where('movement_type', $movementType))
            ->when(
                $categoryId || $subcategoryId || $brandId,
                fn ($query) => $query->whereHas('item', function ($itemQuery) use ($categoryId, $subcategoryId, $brandId) {
                    $itemQuery
                        ->when($categoryId, fn ($q) => $q->where('item_category_id', $categoryId))
                        ->when($subcategoryId, fn ($q) => $q->where('item_subcategory_id', $subcategoryId))
                        ->when($brandId, fn ($q) => $q->where('brand_id', $brandId));
                })
            );
    }

    /**
     * Every movement of the query with only the columns a total needs and
     * no reference eager load, streamed in chunks so a year of movements is
     * never held in memory as full models.
     *
     * @param  Builder<ItemStockMovement>  $query
     * @return iterable<int, ItemStockMovement>
     */
    private function slimMovements(Builder $query): iterable
    {
        return (clone $query)
            ->select(['id', 'item_id', 'store_id', 'movement_type', 'quantity'])
            ->with('item:id,name,unit')
            ->orderBy('id')
            ->lazy(2000);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentMovement(ItemStockMovement $movement): array
    {
        return [
            'date' => $movement->date->toDateString(),
            'itemId' => $movement->item_id,
            'itemName' => $movement->item->name,
            'storeName' => $movement->store?->name,
            'unit' => $movement->item->unit,
            'movementType' => $this->movementTypeLabel($movement->movement_type),
            'quantity' => $this->signedStockQuantity($movement)->toString(),
            'unitCostRate' => $movement->unit_cost_rate === null ? null : Quantity::of($movement->unit_cost_rate)->toString(),
            'reference' => $this->referenceDescription($movement->reference, $movement->narration),
        ];
    }

    /**
     * @param  array<string, array{in: Quantity, out: Quantity}>  $byUnit
     */
    private function addToUnitTotals(array &$byUnit, ItemStockMovement $movement): void
    {
        $unit = (string) ($movement->item?->unit ?? '');
        $side = $movement->movement_type->direction() === 1 ? 'in' : 'out';

        $byUnit[$unit] ??= ['in' => Quantity::zero(), 'out' => Quantity::zero()];
        $byUnit[$unit][$side] = $byUnit[$unit][$side]->plus(Quantity::of($movement->quantity));
    }

    /**
     * @param  array<string, array{in: Quantity, out: Quantity}>  $byUnit
     * @return list<array{unit: string, in: string, out: string, net: string}>
     */
    private function presentUnitTotals(array $byUnit): array
    {
        $totals = [];

        foreach ($byUnit as $unit => $sides) {
            $totals[] = [
                'unit' => (string) $unit,
                'in' => $sides['in']->toString(),
                'out' => $sides['out']->toString(),
                'net' => $sides['in']->minus($sides['out'])->toString(),
            ];
        }

        usort($totals, fn (array $a, array $b): int => strcasecmp($a['unit'], $b['unit']));

        return $totals;
    }

    /**
     * @return list<string>
     */
    private function movementTypeValues(int $direction): array
    {
        return array_values(array_map(
            fn (StockMovementType $type): string => $type->value,
            array_filter(StockMovementType::cases(), fn (StockMovementType $type): bool => $type->direction() === $direction),
        ));
    }

    /**
     * The `type` dropdown: every enum case with its register label and its
     * direction, so the page can narrow the list to the chosen direction.
     *
     * @return list<array{value: string, label: string, direction: string}>
     */
    private function movementTypeOptions(): array
    {
        return array_map(fn (StockMovementType $type): array => [
            'value' => $type->value,
            'label' => $this->movementTypeLabel($type),
            'direction' => $type->direction() === 1 ? 'in' : 'out',
        ], StockMovementType::cases());
    }

    /**
     * The `branchReport` prop: BranchFilter::payload(), plus with
     * `by_branch=1` (and no single store chosen) the register's movements
     * summed per item and branch: `columns` and `summary`, one row per item
     * with `byBranch` (branch id => in, out, net, signed net) and `total`.
     * Quantities stay per item, never added across units. Streamed over the
     * whole filtered result (not the screen's truncated rows). Every leg
     * counts in its own store's branch, so a transfer between branches is an
     * out in one and an in in the other.
     *
     * @param  Builder<ItemStockMovement>  $query
     * @return array<string, mixed>|null
     */
    private function branchReport(BranchFilter $filter, Builder $query, ?int $storeId): ?array
    {
        $payload = $filter->payload(supportsByBranch: $storeId === null);

        if ($payload === null || ! $payload['byBranch']) {
            return $payload;
        }

        $columns = BranchStock::columns($filter);
        $storeBranches = $filter->storeBranches();
        $zero = fn (): array => ['in' => Quantity::zero(), 'out' => Quantity::zero()];
        $summary = [];

        foreach ($this->slimMovements($query) as $movement) {
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
     * Strict Y-m-d bounds; a malformed or reversed range is a validation
     * error the page shows, and the download name only ever holds a clean
     * date.
     *
     * Neither bound: the current open fiscal year, else month-to-date.
     * Exactly one bound: it is kept as sent and only the missing one is
     * filled from that same default range - unless that would reverse the
     * range, in which case the missing bound collapses onto the given one
     * (a single-day range). A lone bound is never silently replaced.
     *
     * @return array{0: string, 1: string}
     */
    private function resolveDateRange(Request $request): array
    {
        $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $from = $request->string('from')->toString();
        $to = $request->string('to')->toString();

        if ($from !== '' && $to !== '') {
            return [$from, $to];
        }

        [$defaultFrom, $defaultTo] = $this->defaultDateRange();

        // Y-m-d strings compare chronologically as plain strings.
        if ($from !== '') {
            return [$from, $defaultTo >= $from ? $defaultTo : $from];
        }

        if ($to !== '') {
            return [$defaultFrom <= $to ? $defaultFrom : $to, $to];
        }

        return [$defaultFrom, $defaultTo];
    }

    /**
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
