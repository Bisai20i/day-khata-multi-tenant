<?php

namespace App\Exports\Manufacturing;

use App\Support\Money\DecimalValue;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Any manufacturing report, flattened by the controller to headings plus
 * rows of cells, laid out like the other books: a title row, a period row,
 * the branch label (multi-branch tenants only), a blank row, then the column
 * headings. Money and Quantity cells become real numbers here, at the Excel
 * cell boundary only (CONTRACTS C1): money columns get a 2-decimal format,
 * quantity columns a 4-decimal one; everything else is written as text.
 * Subtotal and total rows are bold, alert rows (a reconciling difference)
 * bold red.
 */
class ManufacturingReportExport implements FromCollection, ShouldAutoSize, WithColumnFormatting, WithHeadings, WithMapping, WithStrictNullComparison, WithStyles
{
    private const MONEY_FORMAT = '#,##0.00';

    private const QUANTITY_FORMAT = '#,##0.00##';

    /**
     * @param  string  $title  report title, the first row
     * @param  string  $periodLabel  "From ... to ..." or "As on ...", the second row
     * @param  array<int, string>  $headings
     * @param  array<int, array{cells: array<int, mixed>, style: string, merge?: bool}>  $rows  `merge`: a text-only row (a status line) spanning every column
     * @param  array<int, int>  $numericColumns  zero-based column indexes
     * @param  array<int, int>  $quantityColumns  zero-based indexes of the numeric columns holding quantities (the rest are money)
     * @param  string|null  $branchLabel  BranchFilter::label() in a multi-branch tenant, else null (no extra heading row)
     */
    public function __construct(
        private readonly string $title,
        private readonly string $periodLabel,
        private readonly array $headings,
        private readonly array $rows,
        private readonly array $numericColumns,
        private readonly array $quantityColumns = [],
        private readonly ?string $branchLabel = null,
    ) {}

    public function collection(): Collection
    {
        return collect($this->rows);
    }

    /**
     * @return list<list<string>>
     */
    public function headings(): array
    {
        $rows = [[$this->title], [$this->periodLabel]];

        if ($this->branchLabel !== null) {
            $rows[] = [$this->branchLabel];
        }

        $rows[] = [''];
        $rows[] = array_values($this->headings);

        return $rows;
    }

    /**
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        $formats = [];

        foreach ($this->numericColumns as $index) {
            $formats[Coordinate::stringFromColumnIndex($index + 1)] = in_array($index, $this->quantityColumns, true)
                ? self::QUANTITY_FORMAT
                : self::MONEY_FORMAT;
        }

        return $formats;
    }

    /**
     * @param  array{cells: array<int, mixed>, style: string}  $row
     * @return array<int, string|float>
     */
    public function map($row): array
    {
        return array_map(
            fn (mixed $cell) => match (true) {
                $cell === null => '',
                $cell instanceof DecimalValue => $cell->toFloat(),
                default => (string) $cell,
            },
            $row['cells'],
        );
    }

    /**
     * @return array<int|string, array<string, mixed>>
     */
    public function styles(Worksheet $sheet): array
    {
        $headingRowCount = count($this->headings());
        $lastColumn = Coordinate::stringFromColumnIndex(max(count($this->headings), 1));

        // Long text lives in merged cells so ShouldAutoSize does not widen column A.
        foreach (range(1, $headingRowCount - 2) as $row) {
            $sheet->mergeCells("A{$row}:{$lastColumn}{$row}");
        }

        $styles = [
            1 => ['font' => ['bold' => true, 'size' => 13]],
            $headingRowCount => ['font' => ['bold' => true]],
        ];

        foreach (array_values($this->rows) as $offset => $row) {
            $style = match ($row['style']) {
                'subtotal', 'total' => ['font' => ['bold' => true]],
                'alert' => ['font' => ['bold' => true, 'color' => ['argb' => 'FFA30000']]],
                default => null,
            };

            $sheetRow = $headingRowCount + $offset + 1;

            if ($style !== null) {
                $styles[$sheetRow] = $style;
            }

            // A long status line in one merged cell, so ShouldAutoSize does not widen column A.
            if (($row['merge'] ?? false) === true) {
                $sheet->mergeCells("A{$sheetRow}:{$lastColumn}{$sheetRow}");
            }
        }

        return $styles;
    }
}
