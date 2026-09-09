<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * Exact mirror of SalesVatBookExport for
 * SalesPurchaseReportController::purchaseVatBook()'s `rows`/`totals`
 * payload (supplier + PAN number instead of customer). See
 * SalesVatBookExport's own docblock for the full reasoning.
 */
class PurchaseVatBookExport implements FromCollection, WithHeadings, WithMapping
{
    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array{taxable_amount: float, vat_amount: float, nontaxable_amount: float, total: float}  $totals
     */
    public function __construct(
        private readonly Collection $rows,
        private readonly array $totals,
    ) {}

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function collection(): Collection
    {
        return $this->rows->values()->push([
            'sn' => null,
            'date' => null,
            'voucher_number' => null,
            'supplier' => 'Total',
            'pan_number' => null,
            'taxable_amount' => $this->totals['taxable_amount'],
            'vat_amount' => $this->totals['vat_amount'],
            'nontaxable_amount' => $this->totals['nontaxable_amount'],
            'total' => $this->totals['total'],
        ]);
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return ['SN', 'Date', 'Voucher #', 'Supplier', 'PAN', 'Taxable', 'VAT', 'Non-taxable', 'Total'];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<int, string|int|float|null>
     */
    public function map($row): array
    {
        return [
            $row['sn'],
            $row['date'],
            $row['voucher_number'] ?? '',
            $row['supplier'] ?? '',
            $row['pan_number'] ?? '',
            (float) round((float) $row['taxable_amount'], 2),
            (float) round((float) $row['vat_amount'], 2),
            (float) round((float) $row['nontaxable_amount'], 2),
            (float) round((float) $row['total'], 2),
        ];
    }
}
