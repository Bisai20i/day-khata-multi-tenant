<?php

namespace App\Http\Controllers\Tenant\Reports;

use App\Enums\FiscalYearStatus;
use App\Exports\DamageLostStockExport;
use App\Http\Controllers\Controller;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\StockAdjustmentLine;
use App\Models\Store;
use App\Support\Branches\BranchFilter;
use App\Support\Branches\BranchStock;
use App\Support\Money\Money;
use App\Support\Money\Quantity;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Read-only report over stock written off as damaged or lost - legacy
 * day_khata's damagestock() / itemwisedamagestock() /
 * SearchItemBetweenDateitemwiseDamage() equivalents (reportsController.php,
 * lines ~2847-3033), rebuilt against this app's own stock_adjustment_lines
 * table instead of legacy's stock_adjustment_items. Both legacy variants
 * filter the same underlying condition - reason_type IN ('damage','lost') on
 * a non-cancelled line, restricted to lines that actually removed stock
 * (legacy's own `outstock > 0` condition) - so both are returned from this
 * one endpoint rather than split across two routes/controllers: `lines` is
 * the raw one-row-per-StockAdjustmentLine list (damagestock()'s own
 * variant), `itemWise` is the same rows summed per item (itemwisedamagestock
 * ()/SearchItemBetweenDateitemwiseDamage()'s variant, which additionally let
 * an operator search a single item - covered here by the same `item_id`
 * filter StockMovementRegisterController already uses).
 *
 * Quantities are always in the item's base unit: a line entered in an
 * alternate unit ("2 Box") is multiplied by its `unit_conversion_factor`
 * (StockAdjustment::post() stores `quantity` as entered), so the figure
 * matches the unit label beside it.
 *
 * Value: a non-perpetual item's Damage/Lost line is zero-valued (see
 * StockAdjustmentReason::isZeroValue()), but StockAdjustment::post() costs a
 * perpetual item's out line at its issue cost into `line_value`. Both
 * variants therefore carry a Money `value`, and the totals carry the value
 * written off.
 *
 * `totalQuantities` is the raw list's totals row: base quantity, value and
 * line count per base unit; `totalValue` is the value across all lines (money
 * of different units is still money). export() streams the same rows as an
 * .xlsx, the raw list or - with `view=item` - the item-wise rows.
 * Lines carry `stockAdjustmentId` but are not linked: stock adjustments
 * have no show route (only index/print).
 *
 * Branches (plan 12, BR15): filtered on the adjustment's own `branch_id`
 * (BranchFilter::applyToDocuments(), like the BR14 document reports); a
 * `store_id` outside the covered branches is dropped. Not multi-branch:
 * unchanged, plus `branchReport: null`.
 */
class DamageLostStockReportController extends Controller
{
    public function index(Request $request): Response
    {
        $filter = BranchFilter::fromRequest($request);
        $register = $this->register($request, $filter);

        return Inertia::render('Tenant/Reports/DamageLostStock', [
            'lines' => $register['lines'],
            'itemWise' => $register['itemWise'],
            'totalQuantities' => $register['totalQuantities'],
            'totalValue' => $register['totalValue'],
            'view' => $register['view'],
            'items' => Item::query()->orderBy('name')->get(['id', 'name']),
            'stores' => BranchStock::storeOptions($filter, Store::where('is_active', true))->orderBy('name')->get(['id', 'name']),
            'from' => $register['from'],
            'to' => $register['to'],
            'storeId' => $register['storeId'],
            'itemId' => $register['itemId'],
            'reason' => $register['reason'],
            'branchReport' => $filter->payload(),
        ]);
    }

    /**
     * Same lines, item-wise rows and totals as index() for the same query
     * string, streamed as a real .xlsx: the raw list, or the item-wise rows
     * when `view=item`.
     */
    public function export(Request $request): BinaryFileResponse
    {
        $filter = BranchFilter::fromRequest($request);
        $register = $this->register($request, $filter);

        return Excel::download(
            new DamageLostStockExport(
                $register['lines'],
                $register['totalQuantities'],
                $register['from'],
                $register['to'],
                $register['reason'],
                $filter->isActive() ? $filter->label() : null,
                $register['itemWise'],
                $register['view'],
                $register['totalValue'],
            ),
            "damage-lost-stock-{$register['from']}-to-{$register['to']}.xlsx",
        );
    }

    /**
     * The report shared by index() and export(): validated filters, the raw
     * lines, the item-wise rows and the per-unit totals.
     *
     * @return array{lines: list<array<string, mixed>>, itemWise: list<array<string, mixed>>, totalQuantities: list<array{unit: string, quantity: string, value: string, lines: int}>, totalValue: string, view: string, from: string, to: string, storeId: int|null, itemId: int|null, reason: string|null}
     */
    private function register(Request $request, BranchFilter $filter): array
    {
        $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'item_id' => ['nullable', 'integer', 'exists:items,id'],
            'reason' => ['nullable', Rule::in(['damage', 'lost'])],
            'view' => ['nullable', Rule::in(['list', 'item'])],
        ]);

        $view = $request->string('view')->toString() ?: 'list';

        [$from, $to] = $this->resolveDateRange($request);
        $storeId = $filter->allowedStoreId($request->integer('store_id') ?: null);
        $itemId = $request->integer('item_id') ?: null;
        $reasonFilter = $request->string('reason')->toString() ?: null;
        $reasons = $reasonFilter !== null ? [$reasonFilter] : ['damage', 'lost'];

        $baseQuery = StockAdjustmentLine::query()
            ->where('direction', 'out')
            ->whereIn('reason_type', $reasons)
            ->when($itemId, fn ($query) => $query->where('stock_adjustment_lines.item_id', $itemId))
            ->whereHas('stockAdjustment', function ($query) use ($from, $to, $storeId, $filter) {
                $query->where('status', 'posted')
                    ->whereDate('date', '>=', $from)
                    ->whereDate('date', '<=', $to)
                    ->when($storeId, fn ($q) => $q->where('store_id', $storeId));

                // The adjustment's own branch (I5: its store's branch).
                $filter->applyToDocuments($query, 'stock_adjustments.branch_id');
            });

        $lines = (clone $baseQuery)
            ->with(['item:id,name,unit', 'stockAdjustment:id,date,store_id,note', 'stockAdjustment.store:id,name'])
            ->get()
            ->sortBy(fn (StockAdjustmentLine $line) => $line->stockAdjustment->date)
            ->values();

        return [
            'lines' => $lines->map(fn (StockAdjustmentLine $line) => [
                'date' => $line->stockAdjustment->date->toDateString(),
                'itemName' => $line->item->name,
                'unit' => $line->item->unit,
                'storeName' => $line->stockAdjustment->store?->name,
                'reason' => $line->reason_type->value,
                'quantity' => $this->baseQuantity($line)->toString(),
                'value' => $this->lineValue($line)->toString(),
                'remarks' => $line->remarks,
                'note' => $line->stockAdjustment->note,
                'stockAdjustmentId' => $line->stock_adjustment_id,
            ])->values()->all(),
            'itemWise' => $this->itemWiseTotals($lines),
            'totalQuantities' => $this->quantitiesByUnit($lines),
            'totalValue' => Money::sum($lines->map(fn (StockAdjustmentLine $line) => $this->lineValue($line)))->toString(),
            'view' => $view,
            'from' => $from,
            'to' => $to,
            'storeId' => $storeId,
            'itemId' => $itemId,
            'reason' => $reasonFilter,
        ];
    }

    /**
     * The same rows summed per item, in exact Quantity rather than a SQL
     * SUM() - on SQLite a DECIMAL column has REAL affinity, so summing
     * quantities there in the database reintroduces the float drift this
     * rewrite removed (audit P1: "0.19999999999999998").
     *
     * Quantities stay per item, so nothing ever adds two different units
     * together.
     *
     * @param  Collection<int, StockAdjustmentLine>  $lines
     * @return array<int, array<string, mixed>>
     */
    private function itemWiseTotals($lines): array
    {
        $byItem = [];

        foreach ($lines as $line) {
            $itemId = (int) $line->item_id;

            $byItem[$itemId] ??= [
                'item_id' => $itemId,
                'name' => $line->item?->name,
                'unit' => $line->item?->unit,
                'quantity' => Quantity::zero(),
                'value' => Money::zero(),
                'adjustments' => [],
            ];

            $byItem[$itemId]['quantity'] = $byItem[$itemId]['quantity']->plus($this->baseQuantity($line));
            $byItem[$itemId]['value'] = $byItem[$itemId]['value']->plus($this->lineValue($line));
            $byItem[$itemId]['adjustments'][(int) $line->stock_adjustment_id] = true;
        }

        $rows = array_map(fn (array $row) => [
            'item_id' => $row['item_id'],
            'name' => $row['name'],
            'unit' => $row['unit'],
            'total_quantity' => $row['quantity']->toString(),
            'total_value' => $row['value']->toString(),
            'transaction_count' => count($row['adjustments']),
        ], array_values($byItem));

        usort($rows, fn (array $a, array $b) => Quantity::of($b['total_quantity'])->compareTo(Quantity::of($a['total_quantity'])));

        return $rows;
    }

    /**
     * The written-off total per base unit, with the number of lines behind
     * it - the raw list's totals row. Three Kilograms plus two Pieces is not
     * "five", so there is no single quantity total anywhere on this report;
     * the value written off is money, so it is summed per unit here and once
     * across everything in `totalValue`.
     *
     * @param  Collection<int, StockAdjustmentLine>  $lines
     * @return list<array{unit: string, quantity: string, value: string, lines: int}>
     */
    private function quantitiesByUnit($lines): array
    {
        $byUnit = [];

        foreach ($lines as $line) {
            $unit = (string) ($line->item?->unit ?? '');
            $byUnit[$unit] ??= ['quantity' => Quantity::zero(), 'value' => Money::zero(), 'lines' => 0];
            $byUnit[$unit]['quantity'] = $byUnit[$unit]['quantity']->plus($this->baseQuantity($line));
            $byUnit[$unit]['value'] = $byUnit[$unit]['value']->plus($this->lineValue($line));
            $byUnit[$unit]['lines']++;
        }

        $totals = [];

        foreach ($byUnit as $unit => $figures) {
            $totals[] = [
                'unit' => (string) $unit,
                'quantity' => $figures['quantity']->toString(),
                'value' => $figures['value']->toString(),
                'lines' => $figures['lines'],
            ];
        }

        usort($totals, fn (array $a, array $b) => strcasecmp($a['unit'], $b['unit']));

        return $totals;
    }

    /**
     * The line's quantity in the item's base unit: `quantity` is stored as
     * entered (possibly "2 Box"), so it is multiplied by the line's unit
     * conversion factor, exactly as StockAdjustment::post() does for stock.
     */
    private function baseQuantity(StockAdjustmentLine $line): Quantity
    {
        return Quantity::of($line->quantity ?? '0')
            ->multipliedBy(Quantity::of($line->unit_conversion_factor ?? '1'));
    }

    /**
     * The value stored on the line: zero for a written-off non-perpetual
     * item, the issue cost for a perpetual item's out line.
     */
    private function lineValue(StockAdjustmentLine $line): Money
    {
        return Money::of($line->line_value ?? '0');
    }

    /**
     * Strict Y-m-d bounds; a malformed or reversed range is a validation
     * error the page shows. Duplicated from StockMovementRegisterController::
     * resolveDateRange() rather than shared, matching this app's existing
     * per-controller-file convention.
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
