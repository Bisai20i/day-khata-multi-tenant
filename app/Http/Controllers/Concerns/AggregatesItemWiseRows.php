<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Item;
use App\Support\Money\Money;
use App\Support\Money\Quantity;
use Illuminate\Support\Collection;

/**
 * Running per-item totals for the two item-wise reports (sales and purchase).
 *
 * The reports read their documents a chunk at a time and fold each line into
 * these totals straight away, so memory is bounded by the number of items, not
 * by the number of lines in the date range. Every figure is an exact decimal
 * sum, so the result is the same whatever order or chunk size the lines
 * arrive in. Only the selected item's own lines are ever kept, for the
 * drill-down.
 *
 * A row is an array with `kind` ('return' for a return, anything else for the
 * main side), `item_id`, `document_id`, `base_quantity`, `net_amount`,
 * `vat_amount` and `gross_amount`. Return rows are signed negative; the
 * returned bucket holds them as positive figures. Rows of one document must
 * arrive together, which is how the chunks are read.
 */
trait AggregatesItemWiseRows
{
    /** Documents read per query. */
    private const DOCUMENT_CHUNK = 500;

    /**
     * @param  array<int, array<string, mixed>>  $byItem
     * @param  array<string, mixed>  $row
     */
    private function accumulateItemRow(array &$byItem, array $row): void
    {
        $itemId = $row['item_id'];

        $byItem[$itemId] ??= [
            'main' => $this->emptyBucket(),
            'returned' => $this->emptyBucket(),
            'documents' => 0,
            'last_document' => null,
        ];

        $isReturn = $row['kind'] === 'return';
        $side = $isReturn ? 'returned' : 'main';
        $bucket = $byItem[$itemId][$side];
        $add = $isReturn
            ? fn (Money|Quantity $total, Money|Quantity $value) => $total->minus($value)
            : fn (Money|Quantity $total, Money|Quantity $value) => $total->plus($value);

        $byItem[$itemId][$side] = [
            'quantity' => $add($bucket['quantity'], $row['base_quantity']),
            'net' => $add($bucket['net'], $row['net_amount']),
            'vat' => $add($bucket['vat'], $row['vat_amount']),
            'gross' => $add($bucket['gross'], $row['gross_amount']),
        ];

        // A document is counted once per item however many of its lines carry
        // that item (its lines arrive together).
        if (! $isReturn && $byItem[$itemId]['last_document'] !== $row['document_id']) {
            $byItem[$itemId]['documents']++;
            $byItem[$itemId]['last_document'] = $row['document_id'];
        }
    }

    /**
     * One row per item, biggest value first.
     *
     * @param  array<int, array<string, mixed>>  $byItem
     * @return Collection<int, array<string, mixed>>
     */
    private function aggregatedItems(array $byItem): Collection
    {
        $items = Item::query()->whereIn('id', array_keys($byItem))->get(['id', 'name', 'unit'])->keyBy('id');

        return collect($byItem)
            ->map(function (array $entry, int $itemId) use ($items): array {
                $main = $entry['main'];
                $returned = $entry['returned'];

                return [
                    'item_id' => $itemId,
                    'name' => $items->get($itemId)?->name,
                    'unit' => $items->get($itemId)?->unit,
                    'total_quantity' => $main['quantity']->toString(),
                    'total_value' => $main['net']->toString(),
                    'vat_amount' => $main['vat']->toString(),
                    'gross_amount' => $main['gross']->toString(),
                    'transaction_count' => $entry['documents'],
                    'returned_quantity' => $returned['quantity']->toString(),
                    'returned_value' => $returned['net']->toString(),
                    'returned_vat' => $returned['vat']->toString(),
                    'returned_gross' => $returned['gross']->toString(),
                    'net_quantity' => $main['quantity']->minus($returned['quantity'])->toString(),
                    'net_value' => $main['net']->minus($returned['net'])->toString(),
                    'net_gross' => $main['gross']->minus($returned['gross'])->toString(),
                ];
            })
            ->sort(function (array $a, array $b): int {
                $byValue = Money::of($b['total_value'])->compareTo(Money::of($a['total_value']));

                return $byValue !== 0 ? $byValue : strcasecmp((string) $a['name'], (string) $b['name']);
            })
            ->values();
    }

    /**
     * @return array{quantity: Quantity, net: Money, vat: Money, gross: Money}
     */
    private function emptyBucket(): array
    {
        return ['quantity' => Quantity::zero(), 'net' => Money::zero(), 'vat' => Money::zero(), 'gross' => Money::zero()];
    }
}
