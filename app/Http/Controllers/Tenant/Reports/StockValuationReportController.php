<?php

namespace App\Http\Controllers\Tenant\Reports;

use App\Http\Controllers\Controller;
use App\Models\Item;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StockValuationReportController extends Controller
{
    /**
     * Point-in-time stock valuation snapshot: for every stockable item, its
     * on-hand quantity as of a single date and its weighted-average-cost
     * valuation, sorted by valuation descending. Uses the same weighted-
     * average-cost algorithm as InventoryReportController::stockSummary(),
     * collapsed to one "as of" cutoff instead of a from/to range - computed
     * directly from ItemStockMovement rather than Item::currentStock()
     * (which has no date boundary), so "as of" queries into the past are
     * correct.
     */
    public function index(Request $request): Response
    {
        $asOf = ($request->date('as_of') ?? now())->copy()->endOfDay();

        $rows = [];
        $grandTotalValuation = 0.0;

        Item::query()->where('is_stockable', true)->orderBy('name')->each(function (Item $item) use ($asOf, &$rows, &$grandTotalValuation): void {
            $movements = $item->stockMovements()->where('cancelled', false)->get();

            $asOfMovements = $movements->filter(fn ($movement) => $movement->date->lte($asOf));

            $quantity = (float) $asOfMovements
                ->sum(fn ($movement) => (float) $movement->quantity * $movement->movement_type->direction());

            if ($movements->isEmpty() && abs($quantity) < 0.0001) {
                return;
            }

            // Same deliberately simplified weighted-average cost as
            // stockSummary(): every non-cancelled stock-increasing movement
            // up to $asOf that has a recorded unit_cost_rate.
            $costBasis = $asOfMovements->filter(
                fn ($movement) => $movement->movement_type->direction() === 1
                    && $movement->unit_cost_rate !== null,
            );

            $costQuantity = (float) $costBasis->sum(fn ($movement) => (float) $movement->quantity);
            $costValue = (float) $costBasis->sum(fn ($movement) => (float) $movement->quantity * (float) $movement->unit_cost_rate);

            $avgCost = $costQuantity > 0 ? round($costValue / $costQuantity, 4) : 0.0;
            $valuation = round($avgCost * $quantity, 2);

            $grandTotalValuation += $valuation;

            $rows[] = [
                'itemId' => $item->id,
                'name' => $item->name,
                'unit' => $item->unit,
                'quantity' => round($quantity, 4),
                'avgCost' => $avgCost,
                'valuation' => $valuation,
            ];
        });

        usort($rows, fn (array $a, array $b): int => $b['valuation'] <=> $a['valuation']);

        return Inertia::render('Tenant/Reports/StockValuation', [
            'asOf' => $asOf->toDateString(),
            'rows' => $rows,
            'grandTotalValuation' => round($grandTotalValuation, 2),
        ]);
    }
}
