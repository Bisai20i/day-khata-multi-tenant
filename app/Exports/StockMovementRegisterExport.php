<?php

namespace App\Exports;

use App\Support\Money\Quantity;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Mirrors StockMovementRegisterController::index()'s `movements`/`totals`
 * payload for the same query string, but every row - the export is never
 * truncated. One line per stock movement (quantity signed: in positive, out
 * negative), then per base unit a total in, total out and net block. There
 * is no grand total across units.
 *
 * Quantities and rates are real numbers with a quantity display format.
 * `Quantity::toFloat()` is used at exactly that boundary and nowhere else
 * (C1): the values it converts are already-final exact strings, so no
 * arithmetic ever happens on a float.
 */
class StockMovementRegisterExport implements FromCollection, ShouldAutoSize, WithColumnFormatting, WithHeadings, WithStrictNullComparison, WithStyles
{
    private const QUANTITY_FORMAT = '#,##0.####';

    private const RATE_FORMAT = '#,##0.00##';

    private const COLUMN_COUNT = 8;

    /**
     * @param  list<array<string, mixed>>  $rows  The controller's presented movement rows
     * @param  list<array{unit: string, in: string, out: string, net: string}>  $totals  Per-unit totals
     * @param  string  $from  Period start (AD, Y-m-d)
     * @param  string  $to  Period end (AD, Y-m-d)
     * @param  string|null  $branchLabel  BranchFilter::label() in a multi-branch tenant, else null (no extra preamble row)
     */
    public function __construct(
        private readonly array $rows,
        private readonly array $totals,
        private readonly string $from,
        private readonly string $to,
        private readonly ?string $branchLabel = null,
    ) {}

    /**
     * @return Collection<int, list<string|float>>
     */
    public function collection(): Collection
    {
        $lines = collect();

        foreach ($this->rows as $row) {
            $lines->push([
                $row['date'] ?? '',
                $row['itemName'] ?? '',
                $row['storeName'] ?? '',
                $row['unit'] ?? '',
                $row['movementType'] ?? '',
                Quantity::of($row['quantity'] ?? '0')->toFloat(),
                ($row['unitCostRate'] ?? null) === null ? '' : Quantity::of($row['unitCostRate'])->toFloat(),
                $row['reference'] ?? '',
            ]);
        }

        $lines->push($this->blankLine());

        foreach ($this->totals as $total) {
            $unit = $total['unit'] !== '' ? " ({$total['unit']})" : '';

            $lines->push($this->totalLine("Total in{$unit}", $total['in']));
            $lines->push($this->totalLine("Total out{$unit}", $total['out']));
            $lines->push($this->totalLine("Net{$unit}", $total['net']));
        }

        return $lines;
    }

    /**
     * @return list<list<string>>
     */
    public function headings(): array
    {
        $rows = [['Stock Movement Register'], ["Period: {$this->from} to {$this->to} (AD)"]];

        if ($this->branchLabel !== null) {
            $rows[] = [$this->branchLabel];
        }

        $rows[] = [''];

        $rows[] = ['Date (AD)', 'Item', 'Store', 'Unit', 'Movement Type', 'Quantity', 'Cost per unit', 'Reference'];

        return $rows;
    }

    /**
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        return ['F' => self::QUANTITY_FORMAT, 'G' => self::RATE_FORMAT];
    }

    /**
     * @return array<int|string, array<string, mixed>>
     */
    public function styles(Worksheet $sheet): array
    {
        $headingRowCount = count($this->headings());
        $preambleRowCount = $headingRowCount - 2;

        // Long text lives in merged cells so ShouldAutoSize does not widen column A.
        foreach (range(1, $preambleRowCount) as $row) {
            $sheet->mergeCells("A{$row}:H{$row}");
        }

        $styles = [
            1 => ['font' => ['bold' => true, 'size' => 13]],
            $headingRowCount => ['font' => ['bold' => true]],
        ];

        $firstTotalRow = $headingRowCount + count($this->rows) + 2;

        for ($offset = 0; $offset < count($this->totals) * 3; $offset++) {
            $row = $firstTotalRow + $offset;
            $sheet->mergeCells("A{$row}:E{$row}");
            $styles[$row] = ['font' => ['bold' => true]];
        }

        return $styles;
    }

    /**
     * @return list<string>
     */
    private function blankLine(): array
    {
        return array_fill(0, self::COLUMN_COUNT, '');
    }

    /**
     * @return list<string|float>
     */
    private function totalLine(string $label, string $quantity): array
    {
        return [$label, '', '', '', '', Quantity::of($quantity)->toFloat(), '', ''];
    }
}
