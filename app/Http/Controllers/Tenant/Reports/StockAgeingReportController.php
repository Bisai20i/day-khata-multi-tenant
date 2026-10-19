<?php

namespace App\Http\Controllers\Tenant\Reports;

use App\Enums\StockMovementType;
use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\ItemStockMovement;
use App\Models\Store;
use App\Support\AsOfDate;
use App\Support\Inventory\StockCosting;
use App\Support\Money\Money;
use App\Support\Money\Quantity;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Stock (item) ageing: how long the stock on hand has been sitting, bucketed
 * 0-30 / 31-60 / 61-90 / 90+ days as of one date.
 *
 * Age is FIFO-derived: the on-hand quantity (the same figure the Stock
 * Valuation report shows) is allocated NEWEST-first against the item's
 * inbound movement rows, because the oldest goods are assumed sold first, so
 * what is left on the shelf is the most recent receipts. Each lot's age is
 * `as_of - movement date`.
 *
 * Value is NOT re-costed per lot. The item's value comes from
 * StockCosting::valuationRows() (weighted average, CONTRACTS C10) and is
 * split across the age buckets in proportion to quantity with
 * Money::allocate() (largest remainder), so the grand total reconciles with
 * the Stock Valuation report to the paisa for the same date and store.
 *
 * Quantity that no inbound row explains (negative stock, or a remainder with
 * no recorded receipt) goes to the "Unaged" column so a row always sums to
 * its quantity and value; it is never silently dropped.
 *
 * Transfers: in the all-stores view TransferIn rows are ignored as lots (the
 * goods are already counted by their original receipt and the TransferOut
 * leg nets them off); with a store selected a transfer is a real receipt for
 * that store and counts as a lot.
 *
 * Non-stockable items (services, capital items) never carry stock and are
 * excluded; the page says so.
 */
class StockAgeingReportController extends Controller
{
    private const BUCKETS = ['d0_30', 'd31_60', 'd61_90', 'd90_plus'];

    public function index(Request $request): Response
    {
        $asOfDate = AsOfDate::resolveCarbon($request->input('as_of'));
        $asOf = $asOfDate->toDateString();
        $storeId = $request->integer('store_id') ?: null;
        $storeId = $storeId !== null && Store::query()->whereKey($storeId)->exists() ? $storeId : null;

        $valuation = StockCosting::valuationRows($asOf, $storeId);

        $positiveIds = $valuation
            ->filter(fn (array $row) => $row['quantity']->isPositive())
            ->pluck('item_id')
            ->all();

        $lots = $this->inboundLotsByItem($positiveIds, $asOf, $storeId);
        $details = Item::query()
            ->whereIn('id', $valuation->pluck('item_id')->all())
            ->get(['id', 'barcode'])
            ->keyBy('id');

        $rows = $valuation->map(function (array $row) use ($lots, $details, $asOfDate): array {
            $quantity = $row['quantity'];
            $remaining = $quantity->isPositive() ? $quantity : Quantity::zero();
            $bucketQty = array_fill_keys(self::BUCKETS, Quantity::zero());

            foreach ($lots[$row['item_id']] ?? [] as $lot) {
                if (! $remaining->isPositive()) {
                    break;
                }

                $take = $lot['quantity']->isGreaterThan($remaining) ? $remaining : $lot['quantity'];
                $days = (int) abs($lot['date']->copy()->startOfDay()->diffInDays($asOfDate->copy()->startOfDay()));
                $bucket = $this->bucketFor($days);
                $bucketQty[$bucket] = $bucketQty[$bucket]->plus($take);
                $remaining = $remaining->minus($take);
            }

            // Whatever no lot explains: all of a negative quantity, or a
            // positive remainder with no recorded receipt.
            $unagedQty = $quantity->minus(Quantity::sum(array_values($bucketQty)));
            $weights = [...array_values($bucketQty), $unagedQty->isPositive() ? $unagedQty : Quantity::zero()];
            $values = $this->splitValue($row['value'], $weights);

            $out = [
                'itemId' => $row['item_id'],
                'name' => $row['name'],
                'unit' => $row['unit'],
                'barcode' => $details[$row['item_id']]->barcode ?? null,
                'hsCode' => $row['hs_code'],
                'quantity' => $quantity->toString(),
                'value' => $row['value']->toString(),
                'unagedQuantity' => $unagedQty->toString(),
                'unagedValue' => $values[4]->toString(),
            ];

            foreach (self::BUCKETS as $index => $bucket) {
                $out[$bucket.'Qty'] = $bucketQty[$bucket]->toString();
                $out[$bucket.'Value'] = $values[$index]->toString();
            }

            return $out;
        })
            ->sort(fn (array $a, array $b) => Money::of($b['value'])->compareTo(Money::of($a['value'])))
            ->values();

        $totals = [];

        foreach (['quantity', 'unagedQuantity', ...array_map(fn (string $b) => $b.'Qty', self::BUCKETS)] as $key) {
            $totals[$key] = Quantity::sum($rows->map(fn (array $r) => Quantity::of($r[$key])))->toString();
        }

        foreach (['value', 'unagedValue', ...array_map(fn (string $b) => $b.'Value', self::BUCKETS)] as $key) {
            $totals[$key] = Money::sum($rows->map(fn (array $r) => Money::of($r[$key])))->toString();
        }

        return Inertia::render('Tenant/Reports/StockAgeing', [
            'asOf' => $asOf,
            'rows' => $rows,
            'totals' => $totals,
            'valuationTotal' => StockCosting::totalClosingValue($asOf, $storeId)->toString(),
            'negativeStockCount' => $rows->filter(fn (array $r) => Quantity::of($r['quantity'])->isNegative())->count(),
            'excludedNonStockCount' => Item::query()->where('is_stockable', false)->count(),
            'stores' => Store::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'storeId' => $storeId,
        ]);
    }

    private function bucketFor(int $days): string
    {
        return match (true) {
            $days <= 30 => 'd0_30',
            $days <= 60 => 'd31_60',
            $days <= 90 => 'd61_90',
            default => 'd90_plus',
        };
    }

    /**
     * Split a value across [4 bucket quantities, unaged quantity] by
     * quantity weight. All-zero weights (negative stock) put the whole value
     * on Unaged.
     *
     * @param  list<Quantity>  $weights
     * @return list<Money>
     */
    private function splitValue(Money $value, array $weights): array
    {
        $zero = Money::zero();

        if ($value->isZero()) {
            return array_fill(0, count($weights), $zero);
        }

        if (! Quantity::sum($weights)->isPositive()) {
            return [$zero, $zero, $zero, $zero, $value];
        }

        return $value->allocate($weights);
    }

    /**
     * Inbound lots per item, newest first.
     *
     * @param  list<int>  $itemIds
     * @return array<int, list<array{date: \Carbon\CarbonInterface, quantity: Quantity}>>
     */
    private function inboundLotsByItem(array $itemIds, string $asOf, ?int $storeId): array
    {
        if ($itemIds === []) {
            return [];
        }

        $inTypes = collect(StockMovementType::cases())
            ->filter(fn (StockMovementType $type) => $type->direction() === 1)
            ->when($storeId === null, fn (Collection $types) => $types->reject(fn (StockMovementType $type) => $type === StockMovementType::TransferIn))
            ->map(fn (StockMovementType $type) => $type->value)
            ->values()
            ->all();

        $lots = [];

        ItemStockMovement::query()
            ->whereIn('item_id', $itemIds)
            ->whereIn('movement_type', $inTypes)
            ->where('cancelled', false)
            ->whereDate('date', '<=', $asOf)
            ->when($storeId !== null, fn ($query) => $query->where('store_id', $storeId))
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get(['id', 'item_id', 'quantity', 'date'])
            ->each(function (ItemStockMovement $movement) use (&$lots): void {
                $lots[$movement->item_id][] = ['date' => $movement->date, 'quantity' => Quantity::of($movement->quantity)];
            });

        return $lots;
    }
}
