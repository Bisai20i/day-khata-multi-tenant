<?php

namespace App\Exports;

use App\Support\Money\Money;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

/**
 * One row per VAT Summary metric, mirroring VatSummaryReportController's own
 * Inertia payload exactly - including the capital columns and the
 * cancellation line the 2026-09-11 audit added (P0-20), and the ledger
 * reconciliation block that proves the report ties to LIA20/ASA23 for the
 * period.
 *
 * Amounts are written as real numbers with a 2-decimal display format so
 * Excel treats the column as summable, sortable numbers - never a
 * comma-grouped or "Rs."-prefixed string, which is exactly what made the
 * legacy predecessor's DOM-scraped VAT export unusable for Excel math.
 * `Money::toFloat()` appears only at that cell boundary (C1).
 */
class VatSummaryExport implements FromCollection, WithColumnFormatting, WithHeadings, WithMapping
{
    /**
     * @param  array{gross: string, capital: string, cancelled: string, returns: string, net: string}  $outputVat
     * @param  array{gross: string, capital: string, cancelled: string, returns: string, net: string}  $inputVat
     * @param  array<string, mixed>  $reconciliation
     */
    public function __construct(
        private readonly array $outputVat,
        private readonly array $inputVat,
        private readonly string $netVatPayable,
        private readonly array $reconciliation = ['applicable' => false],
    ) {}

    /**
     * @return Collection<int, array{metric: string, amount: string}>
     */
    public function collection(): Collection
    {
        $net = Money::of($this->netVatPayable);

        $rows = [
            ['metric' => 'Output VAT on sales', 'amount' => $this->outputVat['gross']],
            ['metric' => 'Output VAT on capital sales', 'amount' => $this->outputVat['capital']],
            ['metric' => 'Less: cancelled sales VAT', 'amount' => $this->outputVat['cancelled']],
            ['metric' => 'Less: credit note VAT', 'amount' => $this->outputVat['returns']],
            ['metric' => 'Net Output VAT', 'amount' => $this->outputVat['net']],
            ['metric' => 'Input VAT on purchases', 'amount' => $this->inputVat['gross']],
            ['metric' => 'Input VAT on capital purchases', 'amount' => $this->inputVat['capital']],
            ['metric' => 'Less: cancelled purchases VAT', 'amount' => $this->inputVat['cancelled']],
            ['metric' => 'Less: debit note VAT', 'amount' => $this->inputVat['returns']],
            ['metric' => 'Net Input VAT', 'amount' => $this->inputVat['net']],
            [
                'metric' => $net->isNegative() ? 'Net VAT Refundable' : 'Net VAT Payable',
                'amount' => $net->abs()->toString(),
            ],
        ];

        if ($this->reconciliation['applicable'] === true) {
            $rows[] = ['metric' => 'Ledger VAT payable for the period', 'amount' => $this->reconciliation['ledgerNetVatPayable']];
            $rows[] = ['metric' => 'Report total', 'amount' => $this->reconciliation['reportNetVatPayable']];
            $rows[] = ['metric' => 'Difference (must be 0.00)', 'amount' => $this->reconciliation['difference']];
        }

        return collect($rows);
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return ['Metric', 'Amount'];
    }

    /**
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        return ['B' => NumberFormat::FORMAT_NUMBER_00];
    }

    /**
     * @param  array{metric: string, amount: string}  $row
     * @return array<int, string|float>
     */
    public function map($row): array
    {
        return [
            $row['metric'],
            Money::of($row['amount'])->toFloat(),
        ];
    }
}
