<?php

namespace App\Exports;

use App\Support\Money\Money;
use App\Support\Money\Quantity;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Mirrors StockValuationReportController::index()'s own `rows` payload: one
 * line per stockable item with its on-hand quantity (kept as text with its
 * unit, because quantities in different units are never added together),
 * weighted average cost per base unit and valuation, then a total-valuation
 * line. With the by-branch view on, each branch adds a quantity and a value
 * column.
 *
 * Money cells are real numbers with a 2-decimal display format. `toFloat()`
 * is used at exactly that boundary and nowhere else (C1): the values it
 * converts are already-final strings, so no arithmetic happens on a float.
 */
class StockValuationExport implements FromCollection, ShouldAutoSize, WithColumnFormatting, WithHeadings, WithStrictNullComparison, WithStyles
{
    private const LEADING_COLUMNS = 5;

    /**
     * @param  list<array<string, mixed>>  $rows  index()'s `rows` (itemId, name, unit, hsCode, quantity, avgCost, valuation, byBranch?)
     * @param  string  $grandTotalValuation  Total value of every row
     * @param  string  $asOf  AD, Y-m-d
     * @param  string|null  $branchLabel  BranchFilter::label() in a multi-branch tenant, else null (no extra preamble row)
     * @param  list<array{id: int, name: string, code: string}>  $branchColumns  By-branch columns; empty when not shown
     * @param  array<int|string, string>  $branchTotals  Value total per branch id
     */
    public function __construct(
        private readonly array $rows,
        private readonly string $grandTotalValuation,
        private readonly string $asOf,
        private readonly ?string $branchLabel = null,
        private readonly array $branchColumns = [],
        private readonly array $branchTotals = [],
    ) {}

    /**
     * @return Collection<int, list<string|float>>
     */
    public function collection(): Collection
    {
        $lines = collect();

        foreach ($this->rows as $row) {
            $unit = (string) ($row['unit'] ?? '');

            $branchCells = [];

            foreach ($this->branchColumns as $column) {
                $figures = $row['byBranch'][$column['id']] ?? ['quantity' => '0', 'value' => '0'];

                $branchCells[] = $this->quantityText($figures['quantity'], $unit);
                $branchCells[] = Money::of($figures['value'])->toFloat();
            }

            $lines->push([
                $row['name'] ?? '',
                $row['hsCode'] ?? '',
                $this->quantityText($row['quantity'], $unit),
                Quantity::of($row['avgCost'] ?? '0')->toFloat(),
                Money::of($row['valuation'])->toFloat(),
                ...$branchCells,
            ]);
        }

        $totalBranchCells = [];

        foreach ($this->branchColumns as $column) {
            $totalBranchCells[] = '';
            $totalBranchCells[] = Money::of($this->branchTotals[$column['id']] ?? '0')->toFloat();
        }

        $lines->push([
            'Total valuation',
            '',
            '',
            '',
            Money::of($this->grandTotalValuation)->toFloat(),
            ...$totalBranchCells,
        ]);

        return $lines;
    }

    /**
     * @return list<list<string>>
     */
    public function headings(): array
    {
        $rows = [['Stock Valuation'], ["As on {$this->asOf} (AD)"]];

        if ($this->branchLabel !== null) {
            $rows[] = [$this->branchLabel];
        }

        $rows[] = [''];

        $header = ['Item', 'HS Code', 'Quantity', 'Average Cost per Unit', 'Valuation'];

        foreach ($this->branchColumns as $column) {
            $header[] = "{$column['name']} Quantity";
            $header[] = "{$column['name']} Value";
        }

        $rows[] = $header;

        return $rows;
    }

    /**
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        $formats = [
            'D' => '#,##0.00##',
            'E' => '#,##0.00',
        ];

        foreach (array_keys($this->branchColumns) as $index) {
            $formats[Coordinate::stringFromColumnIndex(self::LEADING_COLUMNS + ($index * 2) + 2)] = '#,##0.00';
        }

        return $formats;
    }

    /**
     * @return array<int|string, array<string, mixed>>
     */
    public function styles(Worksheet $sheet): array
    {
        $headingRowCount = count($this->headings());
        $preambleRowCount = $headingRowCount - 2;
        $lastColumn = Coordinate::stringFromColumnIndex(self::LEADING_COLUMNS + (count($this->branchColumns) * 2));

        // Long text lives in merged cells so ShouldAutoSize does not widen column A.
        foreach (range(1, $preambleRowCount) as $row) {
            $sheet->mergeCells("A{$row}:{$lastColumn}{$row}");
        }

        return [
            1 => ['font' => ['bold' => true, 'size' => 13]],
            $headingRowCount => ['font' => ['bold' => true]],
            $headingRowCount + count($this->rows) + 1 => ['font' => ['bold' => true]],
        ];
    }

    /**
     * "12.5 pcs": the quantity without padding zeros, then its unit.
     */
    private function quantityText(string $quantity, string $unit): string
    {
        return trim(Quantity::of($quantity)->formatQuantity().' '.$unit);
    }
}
