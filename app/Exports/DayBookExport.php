<?php

namespace App\Exports;

use App\Support\Money\Money;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

/**
 * One row per journal-voucher-line inside the chosen Day Book window (T14
 * task 2), mirroring AccountingReportController::dayBook()'s Inertia payload.
 * The voucher's Party is repeated on each of its rows (so the sheet can be
 * filtered by party); its Voucher Total is written once, on the first row,
 * so a column sum never counts a voucher twice.
 * Money::toFloat() only at this Excel cell boundary (CONTRACTS C1).
 */
class DayBookExport implements FromCollection, WithColumnFormatting, WithHeadings, WithMapping, WithStrictNullComparison
{
    /**
     * @param  array<int, array{date: string, voucherType: string, voucherNumber: int|string, narration: ?string, party?: ?string, total?: string, lines: array<int, array{accountCode: ?string, accountName: string, debit: string, credit: string, narration: ?string}>}>  $vouchers
     * @param  string|null  $branchLabel  BranchFilter::label() in a multi-branch tenant, else null (no extra heading row)
     * @param  string|null  $projectLabel  "CODE - Name" of the project filter, else null (no extra heading row)
     */
    public function __construct(
        private readonly array $vouchers,
        private readonly ?string $branchLabel = null,
        private readonly ?string $projectLabel = null,
    ) {}

    public function collection(): Collection
    {
        $rows = collect();

        foreach ($this->vouchers as $voucher) {
            foreach ($voucher['lines'] as $index => $line) {
                $rows->push([
                    'date' => $voucher['date'],
                    'voucher' => strtoupper(str_replace('_', ' ', $voucher['voucherType'])).' #'.$voucher['voucherNumber'],
                    'party' => $voucher['party'] ?? '',
                    'account' => $line['accountCode'] ? "{$line['accountCode']} - {$line['accountName']}" : $line['accountName'],
                    'narration' => $line['narration'] ?? $voucher['narration'] ?? '',
                    'debit' => $line['debit'],
                    'credit' => $line['credit'],
                    'total' => $index === 0 ? ($voucher['total'] ?? null) : null,
                ]);
            }
        }

        return $rows;
    }

    /**
     * @return array<int, string>|list<list<string>>
     */
    public function headings(): array
    {
        $headings = ['Date', 'Voucher', 'Party', 'Account', 'Narration', 'Debit', 'Credit', 'Voucher Total'];
        $leading = array_values(array_filter([
            $this->projectLabel !== null && $this->projectLabel !== '' ? ["Project: {$this->projectLabel}"] : null,
            $this->branchLabel !== null ? [$this->branchLabel] : null,
        ]));

        return $leading === [] ? $headings : [...$leading, $headings];
    }

    /**
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        return ['F' => NumberFormat::FORMAT_NUMBER_00, 'G' => NumberFormat::FORMAT_NUMBER_00, 'H' => NumberFormat::FORMAT_NUMBER_00];
    }

    /**
     * @param  array<string, string|null>  $row
     * @return array<int, string|float>
     */
    public function map($row): array
    {
        return [
            $row['date'],
            $row['voucher'],
            $row['party'],
            $row['account'],
            $row['narration'],
            $this->sideCell($row['debit']),
            $this->sideCell($row['credit']),
            $row['total'] === null ? '' : Money::of($row['total'])->toFloat(),
        ];
    }

    /**
     * The zero side of a line is an empty cell, as on the page; the Voucher
     * Total keeps a real 0.00 (strict null comparison, so a zero is written).
     */
    private function sideCell(string $amount): string|float
    {
        $money = Money::of($amount);

        return $money->isZero() ? '' : $money->toFloat();
    }
}
