<?php

namespace App\Http\Controllers\Tenant\Reports;

use App\Enums\FiscalYearStatus;
use App\Enums\StockMovementType;
use App\Http\Controllers\Controller;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\ItemStockMovement;
use App\Models\Store;
use App\Support\Inventory\StockCosting;
use App\Support\Money\Money;
use App\Support\Money\Quantity;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Per-item stock movement summary over a date range: opening quantity (net
 * signed movements strictly before the range), quantity in and out within
 * the range, closing quantity, and the closing value.
 *
 * Quantities are summed in SQL as scaled integers (SQLite gives a DECIMAL
 * column REAL affinity, so a plain SUM() there is a float) and carried as
 * App\Support\Money\Quantity at 4 decimals. Values come from
 * App\Support\Inventory\StockCosting (C10), the one place stock is valued -
 * this controller used to run its own weighted average, which rounded the
 * cost to 4 decimals before multiplying and counted transfer-in rows as
 * fresh stock (audit P0-17), so it disagreed with both the Balance Sheet
 * and the year-end closing entry.
 *
 * Transfers are excluded from the in/out columns of the all-stores view for
 * the same reason: relocating your own goods between stores is not stock
 * coming in or going out of the business, and counting both legs made the
 * movement columns look like double the real activity. With a single store
 * selected a transfer IS a real movement for that store, so it counts.
 */
class InventoryReportController extends Controller
{
    public function stockSummary(Request $request): Response
    {
        $openFiscalYear = FiscalYear::query()->where('status', FiscalYearStatus::Open)->first();

        $from = ($request->date('from') ?? $openFiscalYear?->start_date ?? now()->subDays(30))->toDateString();
        $to = ($request->date('to') ?? $openFiscalYear?->end_date ?? now())->toDateString();
        $storeId = $request->integer('store_id') ?: null;

        $items = Item::query()
            ->where('is_stockable', true)
            ->orderBy('name')
            ->get(['id', 'name', 'unit']);

        $itemIds = $items->modelKeys();
        $dayBefore = Carbon::parse($from)->subDay()->toDateString();

        $opening = Item::currentStockByItem($itemIds, $storeId, $dayBefore);
        $closing = Item::currentStockByItem($itemIds, $storeId, $to);
        $movement = $this->movementTotals($itemIds, $from, $to, $storeId);
        $values = StockCosting::valuationRows($to, $storeId, ['include_empty' => true])->keyBy('item_id');

        $rows = $items
            ->map(function (Item $item) use ($opening, $closing, $movement, $values) {
                $itemOpening = $opening[$item->getKey()] ?? Quantity::zero();
                $itemClosing = $closing[$item->getKey()] ?? Quantity::zero();
                $itemMovement = $movement[$item->getKey()] ?? ['in' => Quantity::zero(), 'out' => Quantity::zero()];
                $valuation = $values->get($item->getKey());

                return [
                    'itemId' => $item->getKey(),
                    'name' => $item->name,
                    'unit' => $item->unit,
                    'opening' => $itemOpening->toString(),
                    'qtyIn' => $itemMovement['in']->toString(),
                    'qtyOut' => $itemMovement['out']->toString(),
                    'closing' => $itemClosing->toString(),
                    'avgCost' => $valuation['average_cost'] ?? Quantity::zero()->toString(),
                    'valuation' => ($valuation['value'] ?? Money::zero())->toString(),
                ];
            })
            // An item that neither held nor moved anything in the window is
            // noise on a 5,000-item catalog; exact zero checks only.
            ->reject(fn (array $row) => Quantity::of($row['opening'])->isZero()
                && Quantity::of($row['closing'])->isZero()
                && Quantity::of($row['qtyIn'])->isZero()
                && Quantity::of($row['qtyOut'])->isZero())
            ->values();

        return Inertia::render('Tenant/Reports/StockSummary', [
            'from' => $from,
            'to' => $to,
            'rows' => $rows,
            'grandTotalValuation' => Money::sum($rows->map(fn (array $row) => Money::of($row['valuation'])))->toString(),
            'stores' => Store::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'storeId' => $storeId,
        ]);
    }

    /**
     * Quantity in and quantity out per item within the window, summed in SQL
     * as scaled integers for the reason Item::currentStockByItem() documents.
     *
     * @param  array<int, int|string>  $itemIds
     * @return array<int, array{in: Quantity, out: Quantity}>
     */
    private function movementTotals(array $itemIds, string $from, string $to, ?int $storeId): array
    {
        $ids = array_values(array_unique(array_map('intval', $itemIds)));

        if ($ids === []) {
            return [];
        }

        $inTypes = $this->movementTypes(direction: 1, storeScoped: $storeId !== null);
        $outTypes = $this->movementTypes(direction: -1, storeScoped: $storeId !== null);

        if ($inTypes === [] && $outTypes === []) {
            return [];
        }

        $cast = (new ItemStockMovement)->getConnection()->getDriverName() === 'sqlite' ? 'INTEGER' : 'SIGNED';
        $scaled = "CAST(ROUND(quantity * 10000) AS {$cast})";
        $inPlaceholders = implode(', ', array_fill(0, max(count($inTypes), 1), '?'));
        $outPlaceholders = implode(', ', array_fill(0, max(count($outTypes), 1), '?'));

        $rows = ItemStockMovement::query()
            ->selectRaw(
                "item_id,
                 SUM(CASE WHEN movement_type IN ({$inPlaceholders}) THEN {$scaled} ELSE 0 END) as in_scaled,
                 SUM(CASE WHEN movement_type IN ({$outPlaceholders}) THEN {$scaled} ELSE 0 END) as out_scaled",
                [...($inTypes ?: ['']), ...($outTypes ?: [''])],
            )
            ->whereIn('item_id', $ids)
            ->where('cancelled', false)
            ->whereIn('movement_type', [...$inTypes, ...$outTypes])
            ->when($storeId !== null, fn (Builder $query) => $query->where('store_id', $storeId))
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->groupBy('item_id')
            ->get();

        $totals = [];

        foreach ($rows as $row) {
            $totals[(int) $row->item_id] = [
                'in' => $this->unscale((int) $row->in_scaled),
                'out' => $this->unscale((int) $row->out_scaled),
            ];
        }

        return $totals;
    }

    /**
     * @return list<string>
     */
    private function movementTypes(int $direction, bool $storeScoped): array
    {
        return array_values(array_map(
            fn (StockMovementType $type) => $type->value,
            array_filter(
                StockMovementType::cases(),
                fn (StockMovementType $type) => $type->direction() === $direction
                    && ($storeScoped || ! in_array($type, [StockMovementType::TransferIn, StockMovementType::TransferOut], true)),
            ),
        ));
    }

    private function unscale(int $scaled): Quantity
    {
        return Quantity::of(BigDecimal::of($scaled)->dividedBy(10000, 4, RoundingMode::Unnecessary));
    }
}
