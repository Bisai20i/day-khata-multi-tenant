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
 * Purchase list Excel export (item 8), same shape as
 * App\Exports\PurchaseVatBookExport: a plain-array `rows`/`totals` payload
 * the controller has already built off the stored server values (never a
 * client/export-time recomputation), BS and AD dates, and a totals row.
 * `Money::toFloat()` is the one sanctioned use of a float here - an Excel
 * numeric cell, never arithmetic (CONTRACTS C1).
 */
class PurchaseListExport implements FromCollection, WithColumnFormatting, WithHeadings, WithMapping
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
            'supplier' => 'Total',
            'bill_number' => null,
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
        return ['SN', 'Date (BS)', 'Date (AD)', 'Supplier', 'Bill #', 'Payment Mode', 'Total', 'Status'];
    }

    /**
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        return ['G' => NumberFormat::FORMAT_NUMBER_00];
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
            $row['supplier'] ?? '',
            $row['bill_number'] ?? '',
            $row['payment_mode'] ?? '',
            Money::of($row['total'])->toFloat(),
            $row['status'] ?? '',
        ];
    }
}
