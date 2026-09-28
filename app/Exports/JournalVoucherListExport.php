<?php

namespace App\Exports;

use App\Enums\VoucherType;
use App\Support\Money\Money;
use App\Support\NepaliCalendar;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

/**
 * The Journal Vouchers list as a spreadsheet, built from exactly the
 * filtered/sorted/searched rows JournalVoucherController::index() renders -
 * same shape as App\Exports\SalesExport. `amount` is the voucher's SQL-summed
 * debit side; `Money::toFloat()` only at this Excel cell boundary (C1).
 */
class JournalVoucherListExport implements FromCollection, WithColumnFormatting, WithHeadings, WithMapping
{
    /**
     * @param  Collection<int, array{date: string, voucher_type: string, voucher_number: int, narration: ?string, fiscal_year: ?string, amount: string, created_by: ?string, status: string}>  $rows
     */
    public function __construct(
        private readonly Collection $rows,
    ) {}

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function collection(): Collection
    {
        return $this->rows->values();
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return ['Date (BS)', 'Date (AD)', 'Type', 'Voucher #', 'Narration', 'Fiscal Year', 'Amount', 'Created By', 'Status'];
    }

    /**
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        return [
            'G' => NumberFormat::FORMAT_NUMBER_00,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<int, string|int|float|null>
     */
    public function map($row): array
    {
        return [
            NepaliCalendar::formatBs($row['date']),
            $row['date'],
            Str::headline(VoucherType::from($row['voucher_type'])->name),
            $row['voucher_number'],
            $row['narration'] ?? '',
            $row['fiscal_year'] ?? '',
            Money::of($row['amount'])->toFloat(),
            $row['created_by'] ?? '',
            $row['status'],
        ];
    }
}
