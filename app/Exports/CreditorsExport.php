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
 * The ledger-based Creditors list as a real .xlsx: every supplier carrying a
 * non-zero balance in the selected fiscal year, exactly as
 * SalesPurchaseReportController::creditors() renders it. This is the party
 * breakdown of the Sundry Creditors subgroup on the Balance Sheet, so it
 * includes migrated opening payables that no open invoice explains - the gap
 * the 2026-09-11 audit found ("no ledger-based Debtors/Creditors list").
 *
 * The balance cell is a real number with a 2-decimal format;
 * `Money::toFloat()` is used at that boundary and nowhere else (C1).
 */
class CreditorsExport implements FromCollection, WithColumnFormatting, WithHeadings, WithMapping
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
            'name' => 'Total',
            'address' => null,
            'mobile_no' => null,
            'balance' => $this->total,
        ]);
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return ['Supplier', 'Address', 'Mobile', 'Balance'];
    }

    /**
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        return ['D' => NumberFormat::FORMAT_NUMBER_00];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<int, string|float|null>
     */
    public function map($row): array
    {
        return [
            $row['name'] ?? '',
            $row['address'] ?? '',
            $row['mobile_no'] ?? '',
            Money::of($row['balance'])->toFloat(),
        ];
    }
}
