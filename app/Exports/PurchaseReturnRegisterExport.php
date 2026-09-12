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
 * The debit-note book as a real .xlsx - the purchase-side mirror of
 * SalesReturnRegisterExport, carrying each note's stored
 * `debit_note_number` and the supplier bill number it debits. See
 * SalesReturnRegisterExport's own docblock for the reasoning.
 */
class PurchaseReturnRegisterExport implements FromCollection, WithColumnFormatting, WithHeadings, WithMapping
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
            'debit_note_number' => null,
            'bill_number' => null,
            'supplier' => 'Total',
            'supplier_pan' => null,
            'entry' => null,
            'taxable_amount' => $this->totals['taxable_amount'],
            'nontaxable_amount' => $this->totals['nontaxable_amount'],
            'vat_amount' => $this->totals['vat_amount'],
            'total' => $this->totals['total'],
        ]);
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return ['SN', 'Date (BS)', 'Date (AD)', 'Debit Note #', 'Against Bill #', 'Supplier', 'Supplier PAN', 'Entry', 'Taxable', 'Exempt', 'VAT', 'Total'];
    }

    /**
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        return [
            'I' => NumberFormat::FORMAT_NUMBER_00,
            'J' => NumberFormat::FORMAT_NUMBER_00,
            'K' => NumberFormat::FORMAT_NUMBER_00,
            'L' => NumberFormat::FORMAT_NUMBER_00,
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
            $row['debit_note_number'] ?? '',
            $row['bill_number'] ?? '',
            $row['supplier'] ?? '',
            $row['supplier_pan'] ?? '',
            $row['entry'] ?? '',
            Money::of($row['taxable_amount'])->toFloat(),
            Money::of($row['nontaxable_amount'])->toFloat(),
            Money::of($row['vat_amount'])->toFloat(),
            Money::of($row['total'])->toFloat(),
        ];
    }
}
