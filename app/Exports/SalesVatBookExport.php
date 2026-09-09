<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * Mirrors SalesPurchaseReportController::salesVatBook()'s own Inertia
 * `rows`/`totals` payload exactly - same filtered, posted-only rows (the
 * caller has already applied the from/to/store_id filters before handing
 * this class its data), plus a trailing Total row - just as a real .xlsx
 * instead of an on-screen table. Every monetary cell is mapped through
 * (float) round(...) in WithMapping, never a formatted/comma-grouped
 * string, so the sheet is usable for real Excel sum/sort/filter.
 */
class SalesVatBookExport implements FromCollection, WithHeadings, WithMapping
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
            'customer' => 'Total',
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
        return ['SN', 'Date', 'Invoice #', 'Customer', 'Taxable', 'VAT', 'Non-taxable', 'Total'];
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
            $row['customer'] ?? '',
            (float) round((float) $row['taxable_amount'], 2),
            (float) round((float) $row['vat_amount'], 2),
            (float) round((float) $row['nontaxable_amount'], 2),
            (float) round((float) $row['total'], 2),
        ];
    }
}
