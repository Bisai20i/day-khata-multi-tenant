<?php

namespace App\Exports;

use App\Support\Money\Money;
use App\Support\NepaliCalendar;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

/**
 * Exact mirror of SalesVatBookExport for
 * SalesPurchaseReportController::purchaseVatBook()'s `rows`/`totals`
 * payload. See SalesVatBookExport's own docblock for the reasoning.
 *
 * The invoice column is the SUPPLIER's bill number, never our internal
 * voucher number (audit P1): an input-VAT claim is checked against the
 * seller's own invoice, so our voucher number is of no use to anyone
 * reconciling this book.
 */
class PurchaseVatBookExport implements FromCollection, WithColumnFormatting, WithHeadings, WithMapping
{
    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<string, string|int>  $totals
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
            'bill_number' => null,
            'supplier' => 'Total',
            'supplier_pan' => null,
            'entry' => null,
            'subtotal' => $this->totals['subtotal'] ?? '0.00',
            'nontaxable_amount' => $this->totals['nontaxable_amount'],
            'taxable_amount' => $this->totals['taxable_amount'],
            'vat_amount' => $this->totals['vat_amount'],
            'capital_amount' => $this->totals['capital_amount'],
            'total' => $this->totals['total'],
        ]);
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return ['SN', 'Date (BS)', 'Date (AD)', 'Bill #', 'Supplier', 'Supplier PAN', 'Entry', 'Sub Total', 'Non-Taxable', 'Taxable', 'VAT', 'Capital', 'Total'];
    }

    /**
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        return [
            'H' => NumberFormat::FORMAT_NUMBER_00,
            'I' => NumberFormat::FORMAT_NUMBER_00,
            'J' => NumberFormat::FORMAT_NUMBER_00,
            'K' => NumberFormat::FORMAT_NUMBER_00,
            'L' => NumberFormat::FORMAT_NUMBER_00,
            'M' => NumberFormat::FORMAT_NUMBER_00,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<int, string|int|float|null>
     */
    public function map($row): array
    {
        return [
            $row['sn'],
            $row['date'] === null ? '' : NepaliCalendar::formatBs($row['date']),
            $row['date'] ?? '',
            $row['bill_number'] ?? '',
            $row['supplier'] ?? '',
            $row['supplier_pan'] ?? '',
            $row['entry'] ?? '',
            Money::of($row['subtotal'] ?? '0')->toFloat(),
            Money::of($row['nontaxable_amount'])->toFloat(),
            Money::of($row['taxable_amount'])->toFloat(),
            Money::of($row['vat_amount'])->toFloat(),
            Money::of($row['capital_amount'])->toFloat(),
            Money::of($row['total'])->toFloat(),
        ];
    }
}
