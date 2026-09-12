<?php

use App\Support\Money\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Capital sales and purchases used to take `vat_amount` as a typed-in number:
 * whatever the user put in the box was credited to Output VAT (LIA20) or
 * debited to Input VAT (ASA23), with nothing tying it to the amounts on the
 * lines. The VAT books and the VAT summary can only tie back to the ledger
 * (audit P0-20) if a capital document carries the same taxable / non-taxable /
 * rate breakdown a sale or purchase carries, so VAT can be computed rather
 * than trusted.
 *
 * `vatable` on the line is what makes that possible: a capital document can
 * mix a VAT-bearing account with an exempt one, exactly like a sale mixes
 * vatable and exempt items.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (['capital_sales', 'capital_purchases'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->decimal('taxable_amount', 15, 2)->default(0)->after('bank_amount');
                $blueprint->decimal('nontaxable_amount', 15, 2)->default(0)->after('taxable_amount');
                $blueprint->decimal('vat_rate', 5, 2)->default(0)->after('nontaxable_amount');
            });
        }

        foreach (['capital_sale_lines', 'capital_purchase_lines'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->boolean('vatable')->default(false)->after('amount');
            });
        }

        $this->backfill('capital_sales', 'capital_sale_lines', 'capital_sale_id');
        $this->backfill('capital_purchases', 'capital_purchase_lines', 'capital_purchase_id');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['capital_sale_lines', 'capital_purchase_lines'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn('vatable');
            });
        }

        foreach (['capital_sales', 'capital_purchases'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn(['taxable_amount', 'nontaxable_amount', 'vat_rate']);
            });
        }
    }

    /**
     * Reconstructs the breakdown of every document posted before these columns
     * existed, from the only two facts those rows carry: the total and the VAT
     * that was typed on it.
     *
     * A document that carried VAT was entirely VAT-bearing (there was no way
     * to mark part of it exempt), so its taxable amount is `total - vat` and
     * every one of its lines is flagged vatable. A document with no VAT was
     * entirely exempt, so the whole total is non-taxable. Either way
     * `taxable + nontaxable + vat` still equals the total that was posted to
     * the ledger, which is what the VAT reports have to tie to.
     *
     * The rate is the one implied by what was actually charged, so an old
     * document keeps printing the number it was billed at. Idempotent: the
     * result is a pure function of `total` and `vat_amount`, so a second run
     * writes the same values.
     */
    private function backfill(string $table, string $lineTable, string $foreignKey): void
    {
        foreach (DB::table($table)->lazyById() as $document) {
            $total = Money::of($document->total);
            $vat = Money::of($document->vat_amount);

            $isVatable = $vat->isPositive();
            $taxable = $isVatable ? $total->minus($vat) : Money::zero();
            $nonTaxable = $isVatable ? Money::zero() : $total;

            // Exact: BigDecimal division to 2 decimals, one HalfUp rounding.
            $rate = $isVatable && $taxable->isPositive()
                ? BigDecimal::of($vat->toString())
                    ->multipliedBy(100)
                    ->dividedBy($taxable->toString(), 2, RoundingMode::HalfUp)
                    ->__toString()
                : '0.00';

            DB::table($table)->where('id', $document->id)->update([
                'taxable_amount' => $taxable->toString(),
                'nontaxable_amount' => $nonTaxable->toString(),
                'vat_rate' => $rate,
            ]);

            DB::table($lineTable)->where($foreignKey, $document->id)->update(['vatable' => $isVatable]);
        }
    }
};
