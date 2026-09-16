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
 * Income Statement export (T14 task 2): every income row, every expense row,
 * then Gross Profit / Net Profit totals, mirroring
 * AccountingReportController::incomeStatement()'s Inertia payload row for
 * row. Money::toFloat() only at this Excel cell boundary (CONTRACTS C1).
 */
class IncomeStatementExport implements FromCollection, WithColumnFormatting, WithHeadings, WithMapping
{
    /**
     * @param  array<int, array{code: ?string, name: string, amount: string, computed: bool}>  $income
     * @param  array<int, array{code: ?string, name: string, amount: string, computed: bool}>  $expenses
     */
    public function __construct(
        private readonly array $income,
        private readonly array $expenses,
        private readonly string $grossProfit,
        private readonly string $netProfit,
    ) {}

    public function collection(): Collection
    {
        $rows = collect();

        foreach ($this->income as $row) {
            $rows->push(['section' => 'Income', 'code' => $row['code'] ?? '', 'name' => $row['name'], 'amount' => $row['amount']]);
        }

        foreach ($this->expenses as $row) {
            $rows->push(['section' => 'Expenses', 'code' => $row['code'] ?? '', 'name' => $row['name'], 'amount' => $row['amount']]);
        }

        $rows->push(['section' => '', 'code' => '', 'name' => 'Gross Profit', 'amount' => $this->grossProfit]);
        $rows->push(['section' => '', 'code' => '', 'name' => 'Net Profit', 'amount' => $this->netProfit]);

        return $rows;
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return ['Section', 'Code', 'Account', 'Amount'];
    }

    /**
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        return ['D' => NumberFormat::FORMAT_NUMBER_00];
    }

    /**
     * @param  array{section: string, code: string, name: string, amount: string}  $row
     * @return array<int, string|float>
     */
    public function map($row): array
    {
        return [$row['section'], $row['code'], $row['name'], Money::of($row['amount'])->toFloat()];
    }
}
