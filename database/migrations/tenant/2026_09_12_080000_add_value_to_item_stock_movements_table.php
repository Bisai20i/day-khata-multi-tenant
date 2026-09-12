<?php

use App\Support\Money\Money;
use App\Support\Money\Quantity;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `item_stock_movements.value` (CONTRACTS C10): the exact rupee value of
     * a priced movement, so stock valuation has a real cost basis instead of
     * re-deriving one from `unit_cost_rate`.
     *
     * The audit (P0-17) found two defects this column exists to fix:
     *
     * 1. A purchase recorded the *entered-unit gross rate* against the
     *    *base* quantity, so 2 Box of 12 at Rs 1,200 per Box valued every
     *    single piece at Rs 1,200 - a 12x overstatement of stock.
     * 2. Line and header discounts were ignored entirely, so a discounted
     *    purchase valued stock above what was actually paid for it.
     *
     * Storing the net value and deriving `unit_cost_rate = value / base
     * quantity` makes both impossible: the value is the money that actually
     * changed hands for that movement, and the rate is always per base unit.
     *
     * Nullable on purpose: an unpriced movement (a sale, a transfer, a
     * damage write-off, a conversion line with no cost) genuinely has no
     * value, and `StockCosting` must be able to tell "no basis" apart from
     * "a basis of zero".
     */
    public function up(): void
    {
        Schema::table('item_stock_movements', function (Blueprint $table) {
            $table->decimal('value', 15, 2)->nullable()->after('unit_cost_rate');
        });

        $this->backfillPurchaseMovements();
        $this->backfillAdjustmentMovements();
    }

    /**
     * Purchase movements: value = the line's net value after its own line
     * discount and its share of the header discount, VAT excluded. Then
     * `unit_cost_rate` is rewritten as value / base quantity, which is what
     * the movement's own `quantity` already holds (Purchase::post() has
     * always recorded the base-unit quantity, only the rate was wrong).
     *
     * The header discount is allocated across the *vatable* lines only,
     * because that is what the purchase posting actually did for every row
     * this backfill can see (see Purchase::post()'s $taxableAmount). It is
     * deliberately not the C3 rule: re-splitting an old bill under the new
     * rule would value historical stock at a number that bill never had.
     *
     * Idempotent: only movements whose `value` is still null are touched, so
     * a re-run after a partial failure resumes rather than double-applying.
     */
    private function backfillPurchaseMovements(): void
    {
        $purchaseLineType = 'App\Models\PurchaseLine';

        $purchaseIds = DB::table('item_stock_movements')
            ->join('purchase_lines', 'item_stock_movements.reference_id', '=', 'purchase_lines.id')
            ->where('item_stock_movements.reference_type', $purchaseLineType)
            ->whereNull('item_stock_movements.value')
            ->distinct()
            ->pluck('purchase_lines.purchase_id');

        foreach ($purchaseIds->chunk(100) as $chunk) {
            $purchases = DB::table('purchases')->whereIn('id', $chunk)->get(['id', 'discount', 'discount_type'])->keyBy('id');
            $linesByPurchase = DB::table('purchase_lines')
                ->whereIn('purchase_id', $chunk)
                ->orderBy('id')
                ->get(['id', 'purchase_id', 'line_total', 'vatable'])
                ->groupBy('purchase_id');

            foreach ($linesByPurchase as $purchaseId => $lines) {
                $purchase = $purchases->get($purchaseId);

                if (! $purchase) {
                    continue;
                }

                foreach ($this->netValuesByLine($purchase, $lines) as $lineId => $netValue) {
                    $this->writeValue($purchaseLineType, (int) $lineId, $netValue);
                }
            }
        }
    }

    /**
     * The net value of each purchase line as a Money, keyed by line id.
     *
     * @param  Collection<int, object>  $lines
     * @return array<int, Money>
     */
    private function netValuesByLine(object $purchase, $lines): array
    {
        // Money::round(), not Money::of(), on every raw column read in this
        // backfill: these come straight off the query builder, and SQLite
        // gives a `numeric` column REAL affinity, so a historical row can
        // arrive as a float carrying more decimals than its own DECIMAL(_,2)
        // definition allows. of() would throw and abort the migration
        // mid-table; the column is 2 decimals wide either way.
        $lineTotals = $lines->mapWithKeys(
            fn (object $line) => [$line->id => Money::round((string) $line->line_total)]
        );

        $vatableLines = $lines->filter(fn (object $line) => (bool) $line->vatable)->values();
        $vatableSubtotal = Money::sum($vatableLines->map(fn (object $line) => $lineTotals[$line->id]));

        $headerDiscount = ((string) ($purchase->discount_type ?? 'flat')) === 'percentage'
            ? $vatableSubtotal->percent(Money::round((string) $purchase->discount)->toBigDecimal())
            : Money::round((string) $purchase->discount);

        $shares = [];

        // allocate() rejects an all-zero weight set, and a discount larger
        // than the subtotal it is being taken out of is a broken historical
        // row rather than something to reconstruct - leave those lines at
        // their pre-discount value rather than inventing a negative one.
        if ($headerDiscount->isPositive() && $vatableSubtotal->isPositive()
            && $headerDiscount->isLessThanOrEqualTo($vatableSubtotal)) {
            $parts = $headerDiscount->allocate(
                $vatableLines->map(fn (object $line) => $lineTotals[$line->id])->all()
            );

            foreach ($vatableLines as $index => $line) {
                $shares[$line->id] = $parts[$index];
            }
        }

        $values = [];

        foreach ($lines as $line) {
            $values[$line->id] = $lineTotals[$line->id]->minus($shares[$line->id] ?? Money::zero());
        }

        return $values;
    }

    /**
     * Opening stock and priced adjustment/conversion/transfer movements:
     * value = quantity x unit_cost_rate, with a single rounding. A movement
     * with no `unit_cost_rate` stays null (it has no cost basis at all, and
     * a zero would wrongly drag the weighted average down).
     *
     * Transfers are included here only so the column is complete; they are
     * excluded from the cost basis by StockCosting itself, not by leaving
     * the column empty.
     */
    private function backfillAdjustmentMovements(): void
    {
        // chunkById(), not chunk(): the update below removes each row from
        // this very query's own `value IS NULL` filter, and offset paging
        // would then skip every other page.
        DB::table('item_stock_movements')
            ->whereNull('value')
            ->whereNotNull('unit_cost_rate')
            ->where(fn ($query) => $query
                ->whereNull('reference_type')
                ->orWhere('reference_type', '!=', 'App\Models\PurchaseLine'))
            ->select(['id', 'quantity', 'unit_cost_rate'])
            ->chunkById(500, function ($movements): void {
                foreach ($movements as $movement) {
                    $value = Money::round(
                        Quantity::round((string) $movement->quantity)
                            ->toBigDecimal()
                            ->multipliedBy(BigDecimal::of((string) $movement->unit_cost_rate))
                    );

                    DB::table('item_stock_movements')->where('id', $movement->id)->update([
                        'value' => $value->toString(),
                    ]);
                }
            });
    }

    /**
     * Writes the value and the derived per-base-unit rate onto every
     * still-unvalued movement generated by one document line. A movement of
     * zero base quantity keeps a null rate: there is nothing to divide by,
     * and a zero rate would read as "this stock was free".
     */
    private function writeValue(string $referenceType, int $referenceId, Money $value): void
    {
        $movements = DB::table('item_stock_movements')
            ->where('reference_type', $referenceType)
            ->where('reference_id', $referenceId)
            ->whereNull('value')
            ->get(['id', 'quantity']);

        foreach ($movements as $movement) {
            $quantity = Quantity::round((string) $movement->quantity);

            DB::table('item_stock_movements')->where('id', $movement->id)->update([
                'value' => $value->toString(),
                'unit_cost_rate' => $quantity->isZero()
                    ? null
                    : $value->toBigDecimal()
                        ->dividedBy($quantity->toBigDecimal(), 4, RoundingMode::HalfUp)
                        ->toString(),
            ]);
        }
    }

    /**
     * Drops the column. The `unit_cost_rate` values this migration rewrote
     * are not restored - the pre-migration rates were the wrong number (see
     * the up() docblock), so putting them back would only reintroduce the
     * defect.
     */
    public function down(): void
    {
        Schema::table('item_stock_movements', function (Blueprint $table) {
            $table->dropColumn('value');
        });
    }
};
