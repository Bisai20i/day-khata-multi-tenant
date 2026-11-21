<?php

namespace App\Http\Controllers\Concerns;

use App\Support\Money\Money;

/**
 * Splits a document's stored taxable / non-taxable amounts, VAT and Round Off
 * across its lines (largest remainder, exact to the paisa), for reports that
 * need a per-line figure the line table does not store (item-wise sales,
 * sales by category, unlinked credit notes).
 */
trait AllocatesDocumentDiscount
{
    /**
     * Splits a document's stored taxable / non-taxable amounts, VAT and
     * Round Off across its lines, largest remainder, so the parts sum back to
     * the stored figures to the paisa (the report then ties to the register):
     * - net: per VAT group, the document discount (all the group's line
     *   values minus the stored group amount) is spread over the group's
     *   non-negative lines by line value; a negative (in-bill adjustment)
     *   line carries no weight and keeps its own value;
     * - VAT: spread over the vatable lines by net (negative nets weigh 0);
     * - Round Off: spread over every line by net, in line-id order.
     * The weights and the line-id order follow SalesReturn::saleComponents(),
     * so on a sale with no negative line a fully returned line credits
     * exactly what it shows here. saleComponents() instead spreads a
     * negative line's value over the other lines; here that line keeps it,
     * so its own item carries it.
     *
     * @param  array<int, array{line_total: Money, vatable: bool}>  $lines  keyed by line id
     * @return array<int, array{net: Money, vat: Money, round_off: Money}>
     */
    private function splitDocument(array $lines, Money $taxable, Money $nontaxable, Money $vat, Money $roundOff): array
    {
        $vatableTotals = [];
        $nonVatableTotals = [];

        foreach ($lines as $lineId => $line) {
            if ($line['vatable']) {
                $vatableTotals[$lineId] = $line['line_total'];
            } else {
                $nonVatableTotals[$lineId] = $line['line_total'];
            }
        }

        // A group with no lines (never expected) hands its stored amount to
        // the other group, so the document still ties to the register.
        if ($vatableTotals === []) {
            $nontaxable = $nontaxable->plus($taxable);
            $taxable = Money::zero();
        } elseif ($nonVatableTotals === []) {
            $taxable = $taxable->plus($nontaxable);
            $nontaxable = Money::zero();
        }

        $net = $this->netOfDocumentDiscount($vatableTotals, $taxable)
            + $this->netOfDocumentDiscount($nonVatableTotals, $nontaxable);

        ksort($net);

        $vatShares = $this->shareByLine($vat, $vatableTotals === [] ? $net : array_intersect_key($net, $vatableTotals));
        $roundOffShares = $this->shareByLine($roundOff, $net);

        $components = [];

        foreach ($net as $lineId => $lineNet) {
            $components[$lineId] = [
                'net' => $lineNet,
                'vat' => $vatShares[$lineId] ?? Money::zero(),
                'round_off' => $roundOffShares[$lineId] ?? Money::zero(),
            ];
        }

        return $components;
    }

    /**
     * Each line's value after its share of the group's document discount.
     * The shares sum to exactly `line totals - $groupAmount`, so the nets
     * sum back to the stored `$groupAmount`.
     *
     * @param  array<int, Money>  $lineTotals
     * @return array<int, Money>
     */
    private function netOfDocumentDiscount(array $lineTotals, Money $groupAmount): array
    {
        if ($lineTotals === []) {
            return [];
        }

        $discount = Money::sum($lineTotals)->minus($groupAmount);

        if ($discount->isZero()) {
            return $lineTotals;
        }

        $shares = $this->shareByLine($discount, $lineTotals);
        $net = [];

        foreach ($lineTotals as $lineId => $lineTotal) {
            $net[$lineId] = $lineTotal->minus($shares[$lineId]);
        }

        return $net;
    }

    /**
     * Money::allocate() keyed by line id, weighted by the positive weights
     * only (allocate() refuses a negative weight). With no positive weight at
     * all the whole amount goes to the first line, so nothing is ever lost.
     *
     * @param  array<int, Money>  $weights
     * @return array<int, Money>
     */
    private function shareByLine(Money $amount, array $weights): array
    {
        $shares = array_map(fn (): Money => Money::zero(), $weights);

        if ($weights === [] || $amount->isZero()) {
            return $shares;
        }

        $positive = array_map(fn (Money $weight): Money => $weight->isPositive() ? $weight : Money::zero(), $weights);

        if (Money::sum($positive)->isZero()) {
            $shares[array_key_first($weights)] = $amount;

            return $shares;
        }

        return array_combine(array_keys($positive), $amount->allocate(array_values($positive)));
    }
}
