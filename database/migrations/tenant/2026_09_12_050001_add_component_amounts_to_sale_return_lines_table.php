<?php

use App\Support\Money\Money;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The three components a returned line credits (CONTRACTS C6): the value
     * after the line AND header discount, the share of the invoice's VAT,
     * and the share of its TDS.
     *
     * They have to be stored, not recomputed, because the return that takes
     * a sale line's last remaining quantity credits "the component minus
     * everything already credited for that line" - the rule that stops a
     * 3-unit line worth 100.00 from crediting 99.99 when it comes back as
     * 1 + 1 + 1 (audit P0-3). That subtraction is only exact if what earlier
     * returns credited is a recorded fact.
     */
    public function up(): void
    {
        Schema::table('sale_return_lines', function (Blueprint $table) {
            $table->decimal('net_amount', 15, 2)->default(0)->after('line_total');
            $table->decimal('vat_amount', 15, 2)->default(0)->after('net_amount');
            $table->decimal('tds_amount', 15, 2)->default(0)->after('vat_amount');
        });

        $this->backfillComponents();
    }

    /**
     * Reconstructs the components of every return that already exists, from
     * what that return itself stored, so the running totals the new rule
     * subtracts are the amounts those returns really posted:
     *
     * - `net_amount` is the line's own `line_total` (which has always been
     *   the returned value net of both discounts),
     * - `vat_amount` spreads the return's stored `vat_amount` across its
     *   vatable lines by value, largest remainder, so the parts add back to
     *   the note exactly,
     * - `tds_amount` spreads the TDS the old code derived from the sale
     *   (`sale.tds_amount x return.total / sale.total`, the formula those
     *   vouchers were actually posted with) across every line the same way.
     *
     * Idempotent: a return whose lines already carry a non-zero component,
     * or whose note has no VAT/TDS to spread, is left alone.
     */
    private function backfillComponents(): void
    {
        DB::table('sale_return_lines')->update(['net_amount' => DB::raw('line_total')]);

        DB::table('sales_returns')->chunkById(100, function ($returns) {
            foreach ($returns as $salesReturn) {
                $lines = DB::table('sale_return_lines as srl')
                    ->join('sale_lines as sl', 'sl.id', '=', 'srl.sale_line_id')
                    ->where('srl.sales_return_id', $salesReturn->id)
                    ->orderBy('srl.id')
                    ->get(['srl.id', 'srl.line_total', 'sl.vatable']);

                if ($lines->isEmpty()) {
                    continue;
                }

                $vatable = $lines->filter(fn ($line) => (int) $line->vatable === 1)->values();

                $this->spread($vatable, (string) $salesReturn->vat_amount, 'vat_amount');
                $this->spread($lines, $this->legacyTdsShare($salesReturn), 'tds_amount');

                // The header must agree with its lines to the paisa. Sale::outstandingAmount()
                // reads sales_returns.tds_amount directly rather than re-deriving the share,
                // so a one paisa disagreement here would leave the customer balance out of
                // step with the credit note the ledger actually posted. The column was added
                // with a default of 0 and is only correct once the lines above exist.
                $lineTds = DB::table('sale_return_lines')
                    ->where('sales_return_id', $salesReturn->id)
                    ->sum('tds_amount');

                DB::table('sales_returns')
                    ->where('id', $salesReturn->id)
                    ->update(['tds_amount' => Money::round($lineTds)->toString()]);
            }
        });
    }

    /**
     * The TDS share the pre-C6 code posted for this return: a proportion of
     * the sale's TDS by document total, rounded once. Returned as a plain
     * decimal string so the spread below stays exact.
     */
    private function legacyTdsShare(object $salesReturn): string
    {
        $sale = DB::table('sales')->where('id', $salesReturn->sale_id)->first(['tds_amount', 'total']);

        if (! $sale) {
            return '0';
        }

        $saleTds = Money::of((string) $sale->tds_amount);
        $saleTotal = Money::of((string) $sale->total);

        if ($saleTds->isZero() || ! $saleTotal->isPositive()) {
            return '0';
        }

        return $saleTds->multipliedByFraction((string) $salesReturn->total, $saleTotal)->toString();
    }

    /**
     * Largest-remainder split of $amount across $lines by `line_total`,
     * written to $column.
     *
     * @param  Collection<int, object>  $lines
     */
    private function spread($lines, string $amount, string $column): void
    {
        $money = Money::of($amount);

        if ($lines->isEmpty() || $money->isZero()) {
            return;
        }

        $weights = $lines->map(fn ($line) => Money::of((string) $line->line_total))->all();

        // A note whose lines are all worth zero cannot be split by value;
        // there is nothing meaningful to attribute, so leave the zeros.
        if (Money::sum($weights)->isZero()) {
            return;
        }

        $shares = $money->allocate($weights);

        foreach ($lines as $index => $line) {
            DB::table('sale_return_lines')->where('id', $line->id)->update([
                $column => $shares[$index]->toString(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('sale_return_lines', function (Blueprint $table) {
            $table->dropColumn(['net_amount', 'vat_amount', 'tds_amount']);
        });
    }
};
