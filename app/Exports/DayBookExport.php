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
 * One row per journal-voucher-line inside the chosen Day Book window (T14
 * task 2), mirroring AccountingReportController::dayBook()'s Inertia payload.
 * Money::toFloat() only at this Excel cell boundary (CONTRACTS C1).
 */
class DayBookExport implements FromCollection, WithColumnFormatting, WithHeadings, WithMapping
{
    /**
     * @param  array<int, array{date: string, voucherType: string, voucherNumber: int, narration: ?string, lines: array<int, array{accountCode: ?string, accountName: string, debit: string, credit: string, narration: ?string}>}>  $vouchers
     */
    public function __construct(private readonly array $vouchers) {}

    public function collection(): Collection
    {
        $rows = collect();

        foreach ($this->vouchers as $voucher) {
            foreach ($voucher['lines'] as $line) {
                $rows->push([
                    'date' => $voucher['date'],
                    'voucher' => strtoupper(str_replace('_', ' ', $voucher['voucherType'])).' #'.$voucher['voucherNumber'],
                    'account' => $line['accountCode'] ? "{$line['accountCode']} - {$line['accountName']}" : $line['accountName'],
                    'narration' => $line['narration'] ?? $voucher['narration'] ?? '',
                    'debit' => $line['debit'],
                    'credit' => $line['credit'],
                ]);
            }
        }

        return $rows;
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return ['Date', 'Voucher', 'Account', 'Narration', 'Debit', 'Credit'];
    }

    /**
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        return ['E' => NumberFormat::FORMAT_NUMBER_00, 'F' => NumberFormat::FORMAT_NUMBER_00];
    }

    /**
     * @param  array<string, string>  $row
     * @return array<int, string|float>
     */
    public function map($row): array
    {
        return [
            $row['date'],
            $row['voucher'],
            $row['account'],
            $row['narration'],
            Money::of($row['debit'])->toFloat(),
            Money::of($row['credit'])->toFloat(),
        ];
    }
}
