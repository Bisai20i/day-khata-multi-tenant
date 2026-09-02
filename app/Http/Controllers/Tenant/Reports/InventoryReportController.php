<?php

namespace App\Http\Controllers\Tenant\Reports;

use App\Enums\FiscalYearStatus;
use App\Http\Controllers\Controller;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\Store;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InventoryReportController extends Controller
{
    /**
     * Per-item stock movement summary over a date range: opening quantity
     * (net signed movements strictly before the range), qty in/out within
     * the range, closing quantity, and a valuation. Computed directly from
     * ItemStockMovement rather than Item::currentStock() (which has no date
     * boundary), so "as of" queries into the past are correct.
     */
    public function stockSummary(Request $request): Response
    {
        $openFiscalYear = FiscalYear::query()->where('status', FiscalYearStatus::Open)->first();

        $from = ($request->date('from') ?? $openFiscalYear?->start_date ?? now()->subDays(30))->copy()->startOfDay();
        $to = ($request->date('to') ?? $openFiscalYear?->end_date ?? now())->copy()->endOfDay();
        $storeId = $request->integer('store_id') ?: null;

        $rows = [];
        $grandTotalValuation = 0.0;

        Item::query()->where('is_stockable', true)->orderBy('name')->each(function (Item $item) use ($from, $to, $storeId, &$rows, &$grandTotalValuation): void {
            $movements = $item->stockMovements()->where('cancelled', false)
                ->when($storeId !== null, fn ($query) => $query->where('store_id', $storeId))
                ->get();

            $opening = (float) $movements
                ->filter(fn ($movement) => $movement->date->lt($from))
                ->sum(fn ($movement) => (float) $movement->quantity * $movement->movement_type->direction());

            $withinRange = $movements->filter(fn ($movement) => $movement->date->between($from, $to));

            $qtyIn = (float) $withinRange
                ->filter(fn ($movement) => $movement->movement_type->direction() === 1)
                ->sum(fn ($movement) => (float) $movement->quantity);

            $qtyOut = (float) $withinRange
                ->filter(fn ($movement) => $movement->movement_type->direction() === -1)
                ->sum(fn ($movement) => (float) $movement->quantity);

            $closing = $opening + $qtyIn - $qtyOut;

            if ($movements->isEmpty() && abs($opening) < 0.0001 && abs($closing) < 0.0001) {
                return;
            }

            // Deliberately simplified weighted-average cost across every
            // non-cancelled stock-increasing movement up to $to that has a
            // recorded unit_cost_rate - not a literal port of any legacy
            // costing method (none was worth copying; periodic inventory
            // accounting means legacy never priced stock consistently
            // either). Matches the "fresh, consolidated" approach already
            // used for the chart of accounts.
            $costBasis = $movements->filter(
                fn ($movement) => $movement->movement_type->direction() === 1
                    && $movement->date->lte($to)
                    && $movement->unit_cost_rate !== null,
            );

            $costQuantity = (float) $costBasis->sum(fn ($movement) => (float) $movement->quantity);
            $costValue = (float) $costBasis->sum(fn ($movement) => (float) $movement->quantity * (float) $movement->unit_cost_rate);

            $avgCost = $costQuantity > 0 ? round($costValue / $costQuantity, 4) : 0.0;
            $valuation = round($avgCost * $closing, 2);

            $grandTotalValuation += $valuation;

            $rows[] = [
                'itemId' => $item->id,
                'name' => $item->name,
                'unit' => $item->unit,
                'opening' => round($opening, 4),
                'qtyIn' => round($qtyIn, 4),
                'qtyOut' => round($qtyOut, 4),
                'closing' => round($closing, 4),
                'avgCost' => $avgCost,
                'valuation' => $valuation,
            ];
        });

        return Inertia::render('Tenant/Reports/StockSummary', [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'rows' => $rows,
            'grandTotalValuation' => round($grandTotalValuation, 2),
            'stores' => Store::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'storeId' => $storeId,
        ]);
    }
}
