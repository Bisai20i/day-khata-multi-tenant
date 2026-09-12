<?php

namespace App\Http\Controllers\Tenant\Reports;

use App\Enums\FiscalYearStatus;
use App\Http\Controllers\Controller;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\StockAdjustmentLine;
use App\Models\Store;
use App\Support\Money\Quantity;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

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
 * Damage/Lost lines are always zero-valued (see StockAdjustmentReason::
 * isZeroValue(), enforced in StockAdjustment::post()), so - matching
 * legacy's own item-wise query, which only ever summed `outstock` - neither
 * variant here reports a monetary value, only quantity.
 */
class DamageLostStockReportController extends Controller
{
    public function index(Request $request): Response
    {
        [$from, $to] = $this->resolveDateRange($request);
        $storeId = $request->integer('store_id') ?: null;
        $itemId = $request->integer('item_id') ?: null;
        $reasonFilter = $request->string('reason')->toString();
        $reasons = in_array($reasonFilter, ['damage', 'lost'], true) ? [$reasonFilter] : ['damage', 'lost'];

        $baseQuery = StockAdjustmentLine::query()
            ->where('direction', 'out')
            ->whereIn('reason_type', $reasons)
            ->when($itemId, fn ($query) => $query->where('stock_adjustment_lines.item_id', $itemId))
            ->whereHas('stockAdjustment', function ($query) use ($from, $to, $storeId) {
                $query->where('status', 'posted')
                    ->whereDate('date', '>=', $from)
                    ->whereDate('date', '<=', $to)
                    ->when($storeId, fn ($q) => $q->where('store_id', $storeId));
            });

        $lines = (clone $baseQuery)
            ->with(['item:id,name,unit', 'stockAdjustment:id,date,store_id,note', 'stockAdjustment.store:id,name'])
            ->get()
            ->sortBy(fn (StockAdjustmentLine $line) => $line->stockAdjustment->date)
            ->values();

        $itemWiseRows = $this->itemWiseTotals($lines);

        return Inertia::render('Tenant/Reports/DamageLostStock', [
            'lines' => $lines->map(fn (StockAdjustmentLine $line) => [
                'date' => $line->stockAdjustment->date->toDateString(),
                'itemName' => $line->item->name,
                'unit' => $line->item->unit,
                'storeName' => $line->stockAdjustment->store?->name,
                'reason' => $line->reason_type->value,
                'quantity' => Quantity::of($line->quantity)->toString(),
                'remarks' => $line->remarks,
                'note' => $line->stockAdjustment->note,
                'stockAdjustmentId' => $line->stock_adjustment_id,
            ])->values(),
            'itemWise' => $itemWiseRows,
            'totalQuantities' => $this->quantitiesByUnit($itemWiseRows),
            'items' => Item::query()->orderBy('name')->get(['id', 'name']),
            'stores' => Store::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'from' => $from,
            'to' => $to,
            'storeId' => $storeId,
            'itemId' => $itemId,
            'reason' => in_array($reasonFilter, ['damage', 'lost'], true) ? $reasonFilter : null,
        ]);
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
                'adjustments' => [],
            ];

            $byItem[$itemId]['quantity'] = $byItem[$itemId]['quantity']->plus(Quantity::of($line->quantity));
            $byItem[$itemId]['adjustments'][(int) $line->stock_adjustment_id] = true;
        }

        $rows = array_map(fn (array $row) => [
            'item_id' => $row['item_id'],
            'name' => $row['name'],
            'unit' => $row['unit'],
            'total_quantity' => $row['quantity']->toString(),
            'transaction_count' => count($row['adjustments']),
        ], array_values($byItem));

        usort($rows, fn (array $a, array $b) => Quantity::of($b['total_quantity'])->compareTo(Quantity::of($a['total_quantity'])));

        return $rows;
    }

    /**
     * The written-off total per base unit. Three Kilograms plus two Pieces
     * is not "five", so there is no single grand total anywhere on this
     * report.
     *
     * @param  array<int, array<string, mixed>>  $itemWiseRows
     * @return array<int, array{unit: string, quantity: string}>
     */
    private function quantitiesByUnit(array $itemWiseRows): array
    {
        $byUnit = [];

        foreach ($itemWiseRows as $row) {
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
     * month-to-date if no fiscal year exists yet. Duplicated from
     * StockMovementRegisterController::resolveDateRange() rather than
     * shared, matching this app's existing per-controller-file convention.
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
