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
 * Capital purchase list Excel export (item 8) - see PurchaseListExport's
 * docblock for the shared rationale.
 */
class CapitalPurchaseListExport implements FromCollection, WithColumnFormatting, WithHeadings, WithMapping
{
    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    public function __construct(
        private readonly Collection $rows,
        private readonly string $total,
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
            'type' => null,
            'supplier' => 'Total',
            'payment_mode' => null,
            'total' => $this->total,
            'status' => null,
        ]);
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return ['SN', 'Date (BS)', 'Date (AD)', 'Bill #', 'Type', 'Supplier', 'Payment Mode', 'Total', 'Status'];
    }

    /**
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        return ['H' => NumberFormat::FORMAT_NUMBER_00];
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
            $row['type'] ?? '',
            $row['supplier'] ?? '',
            $row['payment_mode'] ?? '',
            Money::of($row['total'])->toFloat(),
            $row['status'] ?? '',
        ];
    }
}
