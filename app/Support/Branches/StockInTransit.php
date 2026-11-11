<?php

namespace App\Support\Branches;

use App\Models\StockTransfer;
use App\Support\Money\Money;
use App\Support\Money\Quantity;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use InvalidArgumentException;

/**
 * Goods between branches (plan 12, section 5.5, BR19): what a transfer
 * between branches has taken out of the sender's store and not yet put into
 * the receiver's. The one query helper the stock summary, the transfer list
 * and the inter-branch balances report share, so all three count the same
 * transfers.
 *
 * "In transit as at a date" follows the stock movements exactly: the
 * TransferOut leg is dated the transfer date, the TransferIn leg the receipt
 * date (received_on), and cancelling flags the TransferOut leg cancelled
 * outright. So, for the stock side, a transfer is in transit on $asOf when it
 * is between branches, dated on or before $asOf, and either still in transit
 * or received after $asOf. The ledger side (asBooked) also counts a transfer
 * cancelled after $asOf: its dispatch voucher stays in the books until the
 * reversal's date.
 *
 * Quantities and values are the stored transfer lines (quantity and the
 * line_value fixed at dispatch, the amount both vouchers carry), summed as
 * scaled integers for the SQLite reason Item::currentStockByItem() gives.
 * Since BR25 a transfer may carry raw material and finished goods too: its
 * total_value is still every class (what clearing carries) and its
 * perpetual_value the perpetual lines' share, so the ledger-side helpers
 * take a value basis (ALL, PERIODIC, PERPETUAL) naming which part to count.
 *
 * Reads go through StockTransfer's BranchAccessScope: a restricted user only
 * ever counts transfers with at least one end in their branches. Callers only
 * use this for a multi-branch company, so a Main-only or module-off company
 * never runs it.
 */
final class StockInTransit
{
    /** Both ends inside the viewer's set of stores (or no set: the company). */
    public const WITHIN = 'within';

    /** Sent from the viewer's stores to a store outside them. */
    public const OUTGOING = 'outgoing';

    /** Sent to the viewer's stores from a store outside them. */
    public const INCOMING = 'incoming';

    /** Value basis: the whole transfer value, every class (what clearing carries). */
    public const ALL = 'all';

    /** Value basis: the trading (periodic) share, what INI23 / EXE11 carry. */
    public const PERIODIC = 'periodic';

    /** Value basis: the raw material and finished goods share, what AS12 / AS14 carry. */
    public const PERPETUAL = 'perpetual';

    /**
     * Connections (name|database) whose stock_transfers is known to have
     * perpetual_value; see hasPerpetualValue().
     *
     * @var array<string, true>
     */
    private static array $perpetualColumnKnown = [];

    /**
     * Transfers between branches in transit on $asOf (see the class
     * docblock), through the access scope. Null $asOf means right now,
     * whatever their dates: status in_transit.
     *
     * @return Builder<StockTransfer>
     */
    public static function transfers(?string $asOf, bool $asBooked = false): Builder
    {
        $query = StockTransfer::query()
            ->whereColumn('stock_transfers.from_branch_id', '!=', 'stock_transfers.to_branch_id');

        if ($asOf === null) {
            return $query->where('stock_transfers.status', StockTransfer::STATUS_IN_TRANSIT);
        }

        return $query
            ->whereDate('stock_transfers.date', '<=', $asOf)
            ->where(function (Builder $open) use ($asOf, $asBooked): void {
                $open->where('stock_transfers.status', StockTransfer::STATUS_IN_TRANSIT)
                    ->orWhere(fn (Builder $late) => $late
                        ->where('stock_transfers.status', StockTransfer::STATUS_RECEIVED)
                        ->whereDate('stock_transfers.received_on', '>', $asOf));

                if ($asBooked) {
                    $open->orWhere(fn (Builder $cancelled) => $cancelled
                        ->where('stock_transfers.status', StockTransfer::STATUS_CANCELLED)
                        ->whereExists(fn (QueryBuilder $reversal) => $reversal
                            ->selectRaw('1')
                            ->from('journal_vouchers')
                            ->whereColumn('journal_vouchers.id', 'stock_transfers.reversal_journal_voucher_id')
                            ->whereDate('journal_vouchers.date', '>', $asOf)));
                }
            });
    }

    /**
     * Per item, the quantity and value in transit on $asOf, split by where
     * the transfer's ends sit relative to $storeIds (the stock report's
     * stores: BranchFilter::stockScope(), one store, a list, or null for the
     * whole company). With null every transfer is WITHIN. A transfer with
     * neither end in $storeIds is left out.
     *
     * WITHIN is what reconciles a report's columns: its TransferOut leg has
     * already left Closing qty, but because both ends are inside the view it
     * is in neither Qty in nor Qty out. OUTGOING was counted in Qty out and
     * INCOMING is not in the view yet; both are shown for information.
     *
     * @param  int|list<int>|null  $storeIds
     * @return array<int, array<string, array{quantity: Quantity, value: Money}>> item id => bucket => figures
     */
    public static function byItem(int|array|null $storeIds, string $asOf): array
    {
        $storeIds = is_int($storeIds) ? [$storeIds] : $storeIds;

        if ($storeIds === []) {
            return [];
        }

        $query = self::transfers($asOf)
            ->join('stock_transfer_lines', 'stock_transfer_lines.stock_transfer_id', '=', 'stock_transfers.id');

        if ($storeIds !== null) {
            $query->where(fn (Builder $ends) => $ends
                ->whereIn('stock_transfers.from_store_id', $storeIds)
                ->orWhereIn('stock_transfers.to_store_id', $storeIds));
        }

        $rows = $query
            ->selectRaw(
                'stock_transfer_lines.item_id as item_id, stock_transfers.from_store_id as from_store_id, stock_transfers.to_store_id as to_store_id, '
                .self::scaledSums()
            )
            ->groupBy('stock_transfer_lines.item_id', 'stock_transfers.from_store_id', 'stock_transfers.to_store_id')
            ->toBase()
            ->get();

        $scaled = [];

        foreach ($rows as $row) {
            $bucket = self::bucket((int) $row->from_store_id, (int) $row->to_store_id, $storeIds);
            $itemId = (int) $row->item_id;

            $scaled[$itemId][$bucket]['quantity'] = ($scaled[$itemId][$bucket]['quantity'] ?? 0) + (int) $row->quantity_scaled;
            $scaled[$itemId][$bucket]['value'] = ($scaled[$itemId][$bucket]['value'] ?? 0) + (int) $row->value_scaled;
        }

        $items = [];

        foreach ($scaled as $itemId => $buckets) {
            foreach ($buckets as $bucket => $figures) {
                $items[$itemId][$bucket] = [
                    'quantity' => self::quantity($figures['quantity']),
                    'value' => self::money($figures['value']),
                ];
            }
        }

        return $items;
    }

    /**
     * Value in transit on $asOf per (sending branch, receiving branch), the
     * ledger way (asBooked).
     *
     * $basis picks which part of each transfer's value is counted (BR25):
     * ALL (total_value, every class) is what Inter-Branch Clearing carries,
     * so it is the amount by which the two ends of each clearing pair are
     * expected to differ (InterBranchBalances). PERIODIC (total_value less
     * perpetual_value) is the trading share, the only part that goes
     * through Stock Transferred / Stock Received (INI23 / EXE11). PERPETUAL
     * (perpetual_value) is the raw material and finished goods share, which
     * leaves and re-enters AS12 / AS14 instead. Pairs whose figure is zero
     * on the chosen basis are left out.
     *
     * @return array<int, array<int, Money>> from branch id => to branch id => value
     */
    public static function valueByBranchPair(string $asOf, string $basis = self::ALL): array
    {
        $rows = self::transfers($asOf, asBooked: true)
            ->selectRaw('from_branch_id, to_branch_id, '.self::valueSums())
            ->groupBy('from_branch_id', 'to_branch_id')
            ->toBase()
            ->get();

        $values = [];

        foreach ($rows as $row) {
            $value = self::valueOf($row, $basis);

            if ($basis !== self::ALL && $value->isZero()) {
                continue;
            }

            $values[(int) $row->from_branch_id][(int) $row->to_branch_id] = $value;
        }

        return $values;
    }

    /**
     * Value in transit on $asOf across every pair (valueByBranchPair()
     * summed), on the given basis.
     */
    public static function totalValue(string $asOf, string $basis = self::ALL): Money
    {
        $total = Money::zero();

        foreach (self::valueByBranchPair($asOf, $basis) as $byReceiver) {
            foreach ($byReceiver as $value) {
                $total = $total->plus($value);
            }
        }

        return $total;
    }

    /**
     * The select list for valueOf(): SUM(total_value) as value_scaled and
     * SUM(perpetual_value) as perpetual_scaled, both whole paisa. Before
     * the BR25 migration adds perpetual_value no transfer can carry a
     * perpetual item between branches (BR18 refused them), so the
     * perpetual part is then a literal 0.
     */
    public static function valueSums(): string
    {
        $perpetual = self::hasPerpetualValue()
            ? self::scaledValueSum('stock_transfers.perpetual_value')
            : '0';

        return self::scaledValueSum('stock_transfers.total_value').' as value_scaled, '.$perpetual.' as perpetual_scaled';
    }

    /**
     * One row of valueSums() on $basis. The subtraction for PERIODIC is on
     * whole paisa (PHP integers), never on the decimal columns.
     *
     * @throws InvalidArgumentException for an unknown basis
     */
    public static function valueOf(object $row, string $basis): Money
    {
        $total = (int) $row->value_scaled;
        $perpetual = (int) $row->perpetual_scaled;

        return self::money(match ($basis) {
            self::ALL => $total,
            self::PERIODIC => $total - $perpetual,
            self::PERPETUAL => $perpetual,
            default => throw new InvalidArgumentException("Unknown transit value basis \"{$basis}\"."),
        });
    }

    /**
     * Whether stock_transfers has the BR25 perpetual_value column. Only a
     * "yes" is remembered (per connection and database, so one process
     * serving several tenants never reuses another tenant's answer): a
     * column, once added, stays, while a "no" may turn into a "yes" when
     * the migration runs in a long-lived process.
     */
    public static function hasPerpetualValue(): bool
    {
        $connection = StockTransfer::query()->getConnection();
        $key = $connection->getName().'|'.$connection->getDatabaseName();

        if (isset(self::$perpetualColumnKnown[$key])) {
            return true;
        }

        if (! $connection->getSchemaBuilder()->hasColumn('stock_transfers', 'perpetual_value')) {
            return false;
        }

        self::$perpetualColumnKnown[$key] = true;

        return true;
    }

    /**
     * Count and value of the transfers in transit (on $asOf, or right now
     * when null), for the transfer list's banner: those leaving $branchIds
     * (OUTGOING), arriving into them (INCOMING), or both ends inside
     * (WITHIN). Null $branchIds is the whole company: everything WITHIN.
     *
     * @param  list<int>|null  $branchIds
     * @return array<string, array{count: int, value: Money}>
     */
    public static function summary(?array $branchIds, ?string $asOf = null): array
    {
        $rows = self::transfers($asOf)
            ->when($branchIds !== null, fn (Builder $query) => $query->where(fn (Builder $ends) => $ends
                ->whereIn('stock_transfers.from_branch_id', $branchIds)
                ->orWhereIn('stock_transfers.to_branch_id', $branchIds)))
            ->selectRaw('from_branch_id, to_branch_id, COUNT(*) as transfer_count, '.self::scaledValueSum('stock_transfers.total_value').' as value_scaled')
            ->groupBy('from_branch_id', 'to_branch_id')
            ->toBase()
            ->get();

        $summary = [];

        foreach ($rows as $row) {
            $bucket = self::bucket((int) $row->from_branch_id, (int) $row->to_branch_id, $branchIds);
            $current = $summary[$bucket] ?? ['count' => 0, 'value' => Money::zero()];

            $summary[$bucket] = [
                'count' => $current['count'] + (int) $row->transfer_count,
                'value' => $current['value']->plus(self::money((int) $row->value_scaled)),
            ];
        }

        return $summary;
    }

    /**
     * @param  list<int>|null  $inside
     */
    private static function bucket(int $from, int $to, ?array $inside): string
    {
        if ($inside === null) {
            return self::WITHIN;
        }

        $fromInside = in_array($from, $inside, true);
        $toInside = in_array($to, $inside, true);

        return match (true) {
            $fromInside && $toInside => self::WITHIN,
            $fromInside => self::OUTGOING,
            default => self::INCOMING,
        };
    }

    private static function scaledSums(): string
    {
        $cast = self::cast();

        return "COALESCE(SUM(CAST(ROUND(stock_transfer_lines.quantity * 10000) AS {$cast})), 0) as quantity_scaled, "
            .self::scaledValueSum('stock_transfer_lines.line_value').' as value_scaled';
    }

    private static function scaledValueSum(string $column): string
    {
        $cast = self::cast();

        return "COALESCE(SUM(CAST(ROUND({$column} * 100) AS {$cast})), 0)";
    }

    private static function cast(): string
    {
        return StockTransfer::query()->getConnection()->getDriverName() === 'sqlite' ? 'INTEGER' : 'SIGNED';
    }

    private static function quantity(int $scaled): Quantity
    {
        return Quantity::of(BigDecimal::of($scaled)->dividedBy(10000, 4, RoundingMode::Unnecessary));
    }

    private static function money(int $scaled): Money
    {
        return Money::of(BigDecimal::of($scaled)->dividedBy(100, 2, RoundingMode::Unnecessary));
    }
}
