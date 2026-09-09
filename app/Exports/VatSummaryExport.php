<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * One row per VAT Summary metric (gross/returns/net for both output and
 * input VAT, plus the final net payable/refundable figure) - mirrors
 * VatSummaryReportController's own Inertia payload exactly, just flattened
 * into a two-column sheet instead of the two side-by-side cards the screen
 * renders. Every amount is mapped through (float) round(...) in
 * WithMapping so Excel treats the column as real numbers (summable,
 * sortable, filterable) - never a comma-grouped or "Rs."-prefixed string,
 * which is exactly what made the legacy predecessor's DOM-scraped VAT
 * export unusable for Excel math.
 */
class VatSummaryExport implements FromCollection, WithHeadings, WithMapping
{
    /**
     * @param  array{gross: float, returns: float, net: float}  $outputVat
     * @param  array{gross: float, returns: float, net: float}  $inputVat
     */
    public function __construct(
        private readonly array $outputVat,
        private readonly array $inputVat,
        private readonly float $netVatPayable,
    ) {}

    /**
     * @return Collection<int, array{metric: string, amount: float}>
     */
    public function collection(): Collection
    {
        return collect([
            ['metric' => 'Gross Output VAT', 'amount' => $this->outputVat['gross']],
            ['metric' => 'Less: Sales Returns VAT', 'amount' => $this->outputVat['returns']],
            ['metric' => 'Net Output VAT', 'amount' => $this->outputVat['net']],
            ['metric' => 'Gross Input VAT', 'amount' => $this->inputVat['gross']],
            ['metric' => 'Less: Purchase Returns VAT', 'amount' => $this->inputVat['returns']],
            ['metric' => 'Net Input VAT', 'amount' => $this->inputVat['net']],
            [
                'metric' => $this->netVatPayable >= 0 ? 'Net VAT Payable' : 'Net VAT Refundable',
                'amount' => abs($this->netVatPayable),
            ],
        ]);
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return ['Metric', 'Amount'];
    }

    /**
     * @param  array{metric: string, amount: float}  $row
     * @return array<int, string|float>
     */
    public function map($row): array
    {
        return [
            $row['metric'],
            (float) round((float) $row['amount'], 2),
        ];
    }
}
