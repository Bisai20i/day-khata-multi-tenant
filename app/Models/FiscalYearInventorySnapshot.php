<?php

namespace App\Models;

use App\Casts\Decimal;
use App\Enums\InventoryClass;
use App\Support\Inventory\PerpetualCosting;
use App\Support\Inventory\StockCosting;
use App\Support\Manufacturing\InventoryReconciliation;
use App\Support\Money\Money;
use App\Support\Money\Quantity;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/**
 * A verified closing quantity/value per inventory class written by
 * FiscalYear::close() after reconciliation (MC9); next year's opening RM,
 * WIP and FG figures read these. WIP rows carry `production_entry_id`
 * instead of `item_id`. The table has only `created_at`.
 *
 * `inventory_class` holds `raw_material`, `wip`, `finished_good` (the
 * InventoryReconciliation keys) or `trading`.
 */
#[Fillable([
    'fiscal_year_id',
    'inventory_class',
    'item_id',
    'production_entry_id',
    'quantity',
    'value',
])]
class FiscalYearInventorySnapshot extends Model
{
    use HasFactory;

    public const CLASS_TRADING = 'trading';

    public $timestamps = false;

    protected static function booted(): void
    {
        static::creating(function (self $snapshot): void {
            $snapshot->created_at ??= now();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => Decimal::class.':4',
            'value' => Decimal::class.':2',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<FiscalYear, $this>
     */
    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * @return BelongsTo<ProductionEntry, $this>
     */
    public function productionEntry(): BelongsTo
    {
        return $this->belongsTo(ProductionEntry::class);
    }

    /**
     * Replaces this year's snapshots with the closing figures as of $asOf.
     *
     * Called by FiscalYear::close() and FiscalYear::relock() only after
     * InventoryReconciliation::assertReconciled() has passed, inside their
     * transaction. The old rows are deleted first, so a re-close after a
     * reopen/relock cycle leaves exactly one set - the one matching the
     * corrected ledger - and reopening alone never touches them (the last
     * verified figures stay readable until a new verified set replaces them).
     *
     * - Raw Material / Finished Good: one row per item with a non-zero
     *   quantity or value: the all-stores quantity on hand and the item's
     *   perpetual value balance.
     * - WIP: one row per production entry with a non-zero WIP balance, plus
     *   one row with no production entry for issue notes not yet attached to
     *   any entry. Quantity is 0: the materials sitting in WIP are in mixed
     *   units, so only the value means anything at entry level (the
     *   per-material detail stays in production_wip_entries).
     * - Trading: one row per trading item from StockCosting::valuationRows(),
     *   the same valuation the periodic closing entry (AS11) is posted from.
     *
     * Each perpetual class's rows must add up to the reconciled subledger
     * figure (which equals the GL); otherwise this throws and the close rolls
     * back, so a snapshot nobody verified can never be published.
     *
     * @param  array<string, array{gl: Money, subledger: Money, difference: Money}>  $reconciled  InventoryReconciliation::check() output
     *
     * @throws InvalidArgumentException
     */
    public static function rewriteFor(FiscalYear $fiscalYear, string $asOf, array $reconciled): void
    {
        static::query()->where('fiscal_year_id', $fiscalYear->getKey())->delete();

        $rows = [
            ...static::perpetualRows(InventoryClass::RawMaterial, InventoryReconciliation::RAW_MATERIAL, $asOf, $reconciled),
            ...static::perpetualRows(InventoryClass::FinishedGood, InventoryReconciliation::FINISHED_GOOD, $asOf, $reconciled),
            ...static::wipRows($asOf, $reconciled),
            ...static::tradingRows($asOf),
        ];

        $now = now();

        foreach (array_chunk($rows, 500) as $chunk) {
            static::query()->insert(array_map(fn (array $row): array => [
                'fiscal_year_id' => $fiscalYear->getKey(),
                ...$row,
                'created_at' => $now,
            ], $chunk));
        }
    }

    /**
     * @param  array<string, array{gl: Money, subledger: Money, difference: Money}>  $reconciled
     * @return list<array{inventory_class: string, item_id: int, production_entry_id: null, quantity: string, value: string}>
     */
    private static function perpetualRows(InventoryClass $class, string $key, string $asOf, array $reconciled): array
    {
        $itemIds = Item::query()
            ->where('inventory_class', $class->value)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $quantities = $itemIds === [] ? [] : Item::currentStockByItem($itemIds, null, $asOf);
        $values = $itemIds === [] ? [] : PerpetualCosting::valueBalanceByItem($itemIds, $asOf);

        $rows = [];
        $total = Money::zero();

        foreach ($itemIds as $itemId) {
            $quantity = $quantities[$itemId] ?? Quantity::zero();
            $value = Money::of($values[$itemId] ?? Money::zero());

            if ($quantity->isZero() && $value->isZero()) {
                continue;
            }

            $total = $total->plus($value);
            $rows[] = [
                'inventory_class' => $class->value,
                'item_id' => $itemId,
                'production_entry_id' => null,
                'quantity' => $quantity->toString(),
                'value' => $value->toString(),
            ];
        }

        static::assertTotal($key, $total, $reconciled);

        return $rows;
    }

    /**
     * WIP value per production entry, read with the same rules as
     * WipLedger::balance() (non-cancelled rows dated on or before $asOf,
     * signed by direction, scaled-integer SUM for SQLite's REAL affinity)
     * but grouped, so a year with hundreds of entries is one query.
     *
     * @param  array<string, array{gl: Money, subledger: Money, difference: Money}>  $reconciled
     * @return list<array{inventory_class: string, item_id: null, production_entry_id: ?int, quantity: string, value: string}>
     */
    private static function wipRows(string $asOf, array $reconciled): array
    {
        $cast = (new ProductionWipEntry)->getConnection()->getDriverName() === 'sqlite' ? 'INTEGER' : 'SIGNED';

        $balances = ProductionWipEntry::query()
            ->where('cancelled', false)
            ->whereDate('date', '<=', $asOf)
            ->selectRaw("production_entry_id, COALESCE(SUM(direction * CAST(ROUND(value * 100) AS {$cast})), 0) as net_scaled")
            ->groupBy('production_entry_id')
            ->orderBy('production_entry_id')
            ->get();

        $rows = [];
        $total = Money::zero();

        foreach ($balances as $balance) {
            $value = Money::of(BigDecimal::of((int) $balance->net_scaled)->dividedBy(100, 2, RoundingMode::Unnecessary));

            if ($value->isZero()) {
                continue;
            }

            $total = $total->plus($value);
            $rows[] = [
                'inventory_class' => InventoryReconciliation::WIP,
                'item_id' => null,
                'production_entry_id' => $balance->production_entry_id === null ? null : (int) $balance->production_entry_id,
                'quantity' => '0.0000',
                'value' => $value->toString(),
            ];
        }

        static::assertTotal(InventoryReconciliation::WIP, $total, $reconciled);

        return $rows;
    }

    /**
     * Trading items' closing quantity and periodic value. Filtered by the
     * item's own class rather than by a key on the valuation row, so the
     * perpetual items valuationRows() also lists are never snapshotted twice.
     *
     * @return list<array{inventory_class: string, item_id: int, production_entry_id: null, quantity: string, value: string}>
     */
    private static function tradingRows(string $asOf): array
    {
        $tradingItemIds = Item::query()
            ->where('inventory_class', InventoryClass::Trading->value)
            ->pluck('id')
            ->mapWithKeys(fn ($id): array => [(int) $id => true])
            ->all();

        $rows = [];

        foreach (StockCosting::valuationRows($asOf) as $row) {
            if (! isset($tradingItemIds[(int) $row['item_id']])) {
                continue;
            }

            $rows[] = [
                'inventory_class' => self::CLASS_TRADING,
                'item_id' => (int) $row['item_id'],
                'production_entry_id' => null,
                'quantity' => Quantity::of($row['quantity'])->toString(),
                'value' => Money::of($row['value'])->toString(),
            ];
        }

        return $rows;
    }

    /**
     * The per-row breakdown must add up to the subledger total that was just
     * reconciled against the GL. It always does while PerpetualCosting and
     * WipLedger agree with themselves; if they ever stopped agreeing, writing
     * the snapshot anyway would publish an opening figure nobody verified.
     *
     * @param  array<string, array{gl: Money, subledger: Money, difference: Money}>  $reconciled
     */
    private static function assertTotal(string $key, Money $total, array $reconciled): void
    {
        $expected = $reconciled[$key]['subledger'];

        if (! $total->isEqualTo($expected)) {
            throw new InvalidArgumentException(sprintf(
                '%s snapshot rows total %s but the reconciled figure is %s.',
                InventoryReconciliation::label($key),
                $total->toString(),
                $expected->toString(),
            ));
        }
    }
}
