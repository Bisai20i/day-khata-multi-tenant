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
 * The Sales list as a real .xlsx (audit section 4 polish, "list export").
 * Built from exactly the filtered/sorted/searched rows
 * SaleController::index() renders (never every sale unfiltered), with a
 * trailing totals row matching the one the screen itself shows, computed the
 * same way (SQL SUM over the same query, CONTRACTS: exact comparisons, no
 * client-side re-summing). `Money::toFloat()` only at this Excel cell
 * boundary (C1).
 */
class SalesExport implements FromCollection, WithColumnFormatting, WithHeadings, WithMapping
{
    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<string, string>  $totals
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
            'date' => null,
            'invoice_number' => 'Total',
            'invoice_type' => null,
            'customer' => null,
            'agent' => null,
            'payment_mode' => null,
            'status' => null,
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
        return ['Date (BS)', 'Date (AD)', 'Invoice #', 'Type', 'Customer', 'Agent', 'Payment', 'Status', 'Taxable', 'Exempt', 'VAT', 'Total'];
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
     * @return array<int, string|float|null>
     */
    public function map($row): array
    {
        return [
            $row['date'] === null ? '' : NepaliCalendar::formatBs($row['date']),
            $row['date'] ?? '',
            $row['invoice_number'] ?? '',
            $row['invoice_type'] ?? '',
            $row['customer'] ?? '',
            $row['agent'] ?? '',
            $row['payment_mode'] ?? '',
            $row['status'] ?? '',
            Money::of($row['taxable_amount'])->toFloat(),
            Money::of($row['nontaxable_amount'])->toFloat(),
            Money::of($row['vat_amount'])->toFloat(),
            Money::of($row['total'])->toFloat(),
        ];
    }
}
