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
 * One account's running book (Cash Book, Bank Book or the account Ledger -
 * T14 task 2/3): a synthetic "Opening Balance" row first, one row per
 * ledger line, then a "Closing Balance" row, so the export reads exactly
 * like the on-screen table.
 *
 * Amounts are written as real numbers (Money::toFloat() only at this Excel
 * cell boundary, CONTRACTS C1) with a 2-decimal display format, never a
 * comma-grouped or prefixed string, so the Debit/Credit/Balance columns stay
 * summable in Excel.
 */
class AccountBookExport implements FromCollection, WithColumnFormatting, WithHeadings, WithMapping
{
    /**
     * @param  array<int, array{date: string, voucherType: string, voucherNumber: int, narration: ?string, debit: string, credit: string, balance: string}>  $entries
     */
    public function __construct(
        private readonly array $entries,
        private readonly string $openingBalance,
        private readonly string $closingBalance,
    ) {}

    public function collection(): Collection
    {
        $rows = collect();

        $rows->push(['date' => '', 'voucher' => '', 'narration' => 'Opening Balance', 'debit' => '', 'credit' => '', 'balance' => $this->openingBalance]);

        foreach ($this->entries as $entry) {
            $rows->push([
                'date' => $entry['date'],
                'voucher' => strtoupper(str_replace('_', ' ', $entry['voucherType'])).' #'.$entry['voucherNumber'],
                'narration' => $entry['narration'] ?? '',
                'debit' => $entry['debit'],
                'credit' => $entry['credit'],
                'balance' => $entry['balance'],
            ]);
        }

        $rows->push(['date' => '', 'voucher' => '', 'narration' => 'Closing Balance', 'debit' => '', 'credit' => '', 'balance' => $this->closingBalance]);

        return $rows;
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return ['Date', 'Voucher', 'Narration', 'Debit', 'Credit', 'Balance'];
    }

    /**
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        return ['D' => NumberFormat::FORMAT_NUMBER_00, 'E' => NumberFormat::FORMAT_NUMBER_00, 'F' => NumberFormat::FORMAT_NUMBER_00];
    }

    /**
     * @param  array{date: string, voucher: string, narration: string, debit: string, credit: string, balance: string}  $row
     * @return array<int, string|float>
     */
    public function map($row): array
    {
        return [
            $row['date'],
            $row['voucher'],
            $row['narration'],
            $row['debit'] === '' ? '' : Money::of($row['debit'])->toFloat(),
            $row['credit'] === '' ? '' : Money::of($row['credit'])->toFloat(),
            Money::of($row['balance'])->toFloat(),
        ];
    }
}
