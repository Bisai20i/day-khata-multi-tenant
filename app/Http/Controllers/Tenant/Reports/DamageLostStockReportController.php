<?php

namespace App\Http\Controllers\Tenant\Reports;

use App\Enums\FiscalYearStatus;
use App\Http\Controllers\Controller;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\StockAdjustmentLine;
use App\Models\Store;
use Illuminate\Http\Request;
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
                    ->whereBetween('date', [$from, $to])
                    ->when($storeId, fn ($q) => $q->where('store_id', $storeId));
            });

        $lines = (clone $baseQuery)
            ->with(['item:id,name,unit', 'stockAdjustment:id,date,store_id,note', 'stockAdjustment.store:id,name'])
            ->get()
            ->sortBy(fn (StockAdjustmentLine $line) => $line->stockAdjustment->date)
            ->values();

        $itemWiseRows = (clone $baseQuery)
            ->join('stock_adjustments', 'stock_adjustments.id', '=', 'stock_adjustment_lines.stock_adjustment_id')
            ->join('items', 'items.id', '=', 'stock_adjustment_lines.item_id')
            ->groupBy('stock_adjustment_lines.item_id', 'items.name', 'items.unit')
            ->orderByDesc('total_quantity')
            ->selectRaw('stock_adjustment_lines.item_id as item_id')
            ->selectRaw('items.name as name')
            ->selectRaw('items.unit as unit')
            ->selectRaw('sum(stock_adjustment_lines.quantity) as total_quantity')
            ->selectRaw('count(distinct stock_adjustment_lines.stock_adjustment_id) as transaction_count')
            ->get();

        return Inertia::render('Tenant/Reports/DamageLostStock', [
            'lines' => $lines->map(fn (StockAdjustmentLine $line) => [
                'date' => $line->stockAdjustment->date->toDateString(),
                'itemName' => $line->item->name,
                'unit' => $line->item->unit,
                'storeName' => $line->stockAdjustment->store?->name,
                'reason' => $line->reason_type->value,
                'quantity' => round((float) $line->quantity, 4),
                'remarks' => $line->remarks,
                'note' => $line->stockAdjustment->note,
                'stockAdjustmentId' => $line->stock_adjustment_id,
            ])->values(),
            'itemWise' => $itemWiseRows->map(fn ($row) => [
                'item_id' => (int) $row->item_id,
                'name' => $row->name,
                'unit' => $row->unit,
                'total_quantity' => round((float) $row->total_quantity, 4),
                'transaction_count' => (int) $row->transaction_count,
            ])->values(),
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
