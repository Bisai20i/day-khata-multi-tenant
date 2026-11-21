<?php

namespace App\Support\Reports;

use App\Support\Money\Money;
use Illuminate\Support\Collection;

/**
 * The legacy "Unified VAT Register" (old reportsController::allVatReport):
 * sales, sales returns, purchases and purchase returns in one chronological
 * list.
 *
 * Pure and DB-free: it only re-shapes the rows the four VAT books already
 * produce (SalesPurchaseReportController::*Payload()['rows']), so the
 * register can never disagree with the books or with the VAT Summary.
 *
 * Two deliberate differences from legacy, both audit-driven:
 *
 * - **Returns reduce VAT.** A book lists a credit or debit note as a positive
 *   amount (and its cancellation as negative). Here the sign is flipped so a
 *   return is a negative line on its own side, and a cancelled return a
 *   positive one. Legacy added returns into the totals as positives, which
 *   overstated VAT.
 * - **No single grand total.** Output VAT (sales side) and input VAT
 *   (purchase side) are different things; adding them together is
 *   meaningless. The totals are per side, with net VAT payable =
 *   net output - net input, the same figure the VAT Summary reports.
 *
 * @phpstan-type Row array{sn: int, kind: string, type: string, side: string, entry: string, date: string, issued_on: string, document_number: ?string, reference: ?string, party: ?string, party_pan: ?string, subtotal: string, nontaxable_amount: string, taxable_amount: string, vat_amount: string, total: string, branch?: ?string}
 * @phpstan-type SideTotals array{count: int, subtotal: string, nontaxable_amount: string, taxable_amount: string, vat_amount: string, total: string}
 */
final class UnifiedVatRegister
{
    public const OUTPUT = 'output';

    public const INPUT = 'input';

    /**
     * Per book-row `kind`: the side it belongs to, its label, the sign that
     * turns the book's own amounts into register amounts, and which keys of
     * the book row carry its number, original-document reference, party and
     * PAN.
     *
     * @var array<string, array{side: string, label: string, sign: int, number: string, reference: ?string, party: string, pan: string}>
     */
    private const KINDS = [
        'sale' => ['side' => self::OUTPUT, 'label' => 'Sales', 'sign' => 1, 'number' => 'invoice_number', 'reference' => null, 'party' => 'buyer_name', 'pan' => 'buyer_pan'],
        'capital_sale' => ['side' => self::OUTPUT, 'label' => 'Capital sales', 'sign' => 1, 'number' => 'invoice_number', 'reference' => null, 'party' => 'buyer_name', 'pan' => 'buyer_pan'],
        'sales_return' => ['side' => self::OUTPUT, 'label' => 'Sales return', 'sign' => -1, 'number' => 'credit_note_number', 'reference' => 'invoice_number', 'party' => 'buyer_name', 'pan' => 'buyer_pan'],
        'purchase' => ['side' => self::INPUT, 'label' => 'Purchases', 'sign' => 1, 'number' => 'bill_number', 'reference' => null, 'party' => 'supplier', 'pan' => 'supplier_pan'],
        'capital_purchase' => ['side' => self::INPUT, 'label' => 'Capital purchases', 'sign' => 1, 'number' => 'bill_number', 'reference' => null, 'party' => 'supplier', 'pan' => 'supplier_pan'],
        'purchase_return' => ['side' => self::INPUT, 'label' => 'Purchase return', 'sign' => -1, 'number' => 'debit_note_number', 'reference' => 'bill_number', 'party' => 'supplier', 'pan' => 'supplier_pan'],
    ];

    private const AMOUNT_KEYS = ['subtotal', 'nontaxable_amount', 'taxable_amount', 'vat_amount', 'total'];

    /**
     * @param  Collection<int, array<string, mixed>>  $sales  salesVatBookPayload()['rows']
     * @param  Collection<int, array<string, mixed>>  $salesReturns  salesReturnRegisterPayload()['rows']
     * @param  Collection<int, array<string, mixed>>  $purchases  purchaseVatBookPayload()['rows']
     * @param  Collection<int, array<string, mixed>>  $purchaseReturns  purchaseReturnRegisterPayload()['rows']
     * @return array{rows: list<Row>, totals: array{output: SideTotals, input: SideTotals, netVatPayable: string}}
     */
    public static function build(Collection $sales, Collection $salesReturns, Collection $purchases, Collection $purchaseReturns): array
    {
        $rows = $sales
            ->concat($salesReturns)
            ->concat($purchases)
            ->concat($purchaseReturns)
            ->map(fn (array $row): array => self::row($row))
            ->sortBy([['date', 'asc'], ['kind', 'asc'], ['id', 'asc']])
            ->values()
            ->map(fn (array $row, int $index): array => ['sn' => $index + 1, ...$row])
            ->all();

        $output = self::sideTotals($rows, self::OUTPUT);
        $input = self::sideTotals($rows, self::INPUT);

        return [
            'rows' => $rows,
            'totals' => [
                'output' => $output,
                'input' => $input,
                'netVatPayable' => Money::of($output['vat_amount'])->minus(Money::of($input['vat_amount']))->toString(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $book
     * @return array<string, mixed>
     */
    private static function row(array $book): array
    {
        $kind = self::KINDS[$book['kind']];

        $row = [
            'id' => $book['id'],
            'kind' => $book['kind'],
            'type' => $kind['label'],
            'side' => $kind['side'],
            'entry' => $book['entry'],
            'date' => $book['date'],
            'issued_on' => $book['issued_on'],
            'document_number' => $book[$kind['number']] ?? null,
            'reference' => $kind['reference'] === null ? null : ($book[$kind['reference']] ?? null),
            'party' => $book[$kind['party']] ?? null,
            'party_pan' => $book[$kind['pan']] ?? null,
        ];

        foreach (self::AMOUNT_KEYS as $key) {
            $money = Money::of($book[$key] ?? '0');
            $row[$key] = ($kind['sign'] < 0 ? $money->negated() : $money)->toString();
        }

        if (array_key_exists('branch', $book)) {
            $row['branch'] = $book['branch'];
        }

        return $row;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return SideTotals
     */
    private static function sideTotals(array $rows, string $side): array
    {
        $own = array_values(array_filter($rows, fn (array $row): bool => $row['side'] === $side));
        $totals = ['count' => count($own)];

        foreach (self::AMOUNT_KEYS as $key) {
            $totals[$key] = Money::sum(array_column($own, $key))->toString();
        }

        return $totals;
    }
}
