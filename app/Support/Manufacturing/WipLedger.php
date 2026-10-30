<?php

namespace App\Support\Manufacturing;

use App\Models\ProductionEntry;
use App\Models\ProductionWipEntry;
use App\Support\Money\Money;
use App\Support\Money\Quantity;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read side of the work-in-process subledger (MC7).
 *
 * Invariant P2: sum(direction x value) over non-cancelled
 * `production_wip_entries` rows equals the GL balance of AS13. Every figure
 * here is summed in SQL as a scaled integer (value x 100, quantity x 10^4)
 * and divided back exactly, for the same reason StockCosting does it: SQLite
 * stores a decimal as REAL, so a plain SUM() of 0.1 + 0.2 drifts and the
 * strict Money parser would refuse the result.
 *
 * Per-item figures (`quantityByItem`, `valueByItem`) count every non-cancelled
 * row of the entry that carries an `item_id`, signed by `direction`: material
 * issued in (+), returned or consumed out (-). Rows without an item (direct
 * costs, a whole-value completion, an abandon) only move the entry-level
 * `balance()`.
 */
class WipLedger
{
    /**
     * Net WIP value: for one production entry, or across all of them
     * (including notes not yet attached to an entry) when $entry is null.
     * Bounded to rows dated on or before $asOf (Y-m-d) when given.
     */
    public static function balance(?ProductionEntry $entry = null, ?string $asOf = null): Money
    {
        $scaled = static::liveRows($entry, $asOf)
            ->selectRaw('COALESCE(SUM(direction * '.static::scaledValue().'), 0) as net')
            ->value('net');

        return Money::of(BigDecimal::of((int) $scaled)->dividedBy(100, 2, RoundingMode::Unnecessary));
    }

    /**
     * Net WIP quantity per item (base units) on one production entry.
     *
     * @return array<int, Quantity> keyed by item id
     */
    public static function quantityByItem(ProductionEntry $entry): array
    {
        $rows = static::liveRows($entry)
            ->whereNotNull('item_id')
            ->groupBy('item_id')
            ->selectRaw('item_id, SUM(direction * '.static::scaledQuantity().') as net')
            ->get();

        $result = [];

        foreach ($rows as $row) {
            $result[(int) $row->item_id] = Quantity::of(
                BigDecimal::of((int) $row->net)->dividedBy(10000, 4, RoundingMode::Unnecessary)
            );
        }

        return $result;
    }

    /**
     * Net WIP value per item on one production entry.
     *
     * @return array<int, Money> keyed by item id
     */
    public static function valueByItem(ProductionEntry $entry): array
    {
        $rows = static::liveRows($entry)
            ->whereNotNull('item_id')
            ->groupBy('item_id')
            ->selectRaw('item_id, SUM(direction * '.static::scaledValue().') as net')
            ->get();

        $result = [];

        foreach ($rows as $row) {
            $result[(int) $row->item_id] = Money::of(
                BigDecimal::of((int) $row->net)->dividedBy(100, 2, RoundingMode::Unnecessary)
            );
        }

        return $result;
    }

    /**
     * Non-cancelled rows, optionally scoped to one entry and a cut-off date.
     * whereDate() because a date-cast column on SQLite holds a full datetime
     * string that sorts wrongly against a bare Y-m-d (mem.md).
     *
     * @return Builder<ProductionWipEntry>
     */
    private static function liveRows(?ProductionEntry $entry, ?string $asOf = null)
    {
        $query = ProductionWipEntry::query()->where('cancelled', false);

        if ($entry !== null) {
            $query->where('production_entry_id', $entry->getKey());
        }

        if ($asOf !== null) {
            $query->whereDate('date', '<=', $asOf);
        }

        return $query;
    }

    private static function cast(): string
    {
        return (new ProductionWipEntry)->getConnection()->getDriverName() === 'sqlite' ? 'INTEGER' : 'SIGNED';
    }

    private static function scaledValue(): string
    {
        return 'CAST(ROUND(value * 100) AS '.static::cast().')';
    }

    private static function scaledQuantity(): string
    {
        return 'CAST(ROUND(quantity * 10000) AS '.static::cast().')';
    }
}
