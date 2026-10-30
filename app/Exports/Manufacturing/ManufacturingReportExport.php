<?php

namespace App\Exports\Manufacturing;

use App\Support\Money\DecimalValue;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

/**
 * Any manufacturing report, flattened by the controller to headings plus
 * rows of cells. Money and Quantity cells become real numbers here, at the
 * Excel cell boundary only (CONTRACTS C1), with a 4-decimal display format so
 * the columns stay summable; everything else is written as text.
 */
class ManufacturingReportExport implements FromCollection, WithColumnFormatting, WithHeadings, WithMapping
{
    private const NUMBER_FORMAT = '#,##0.00##';

    /**
     * @param  array<int, string>  $headings
     * @param  array<int, array{cells: array<int, mixed>, style: string}>  $rows
     * @param  array<int, int>  $numericColumns  zero-based column indexes
     */
    public function __construct(
        private readonly array $headings,
        private readonly array $rows,
        private readonly array $numericColumns,
    ) {}

    public function collection(): Collection
    {
        return collect($this->rows);
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return $this->headings;
    }

    /**
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        $formats = [];

        foreach ($this->numericColumns as $index) {
            $formats[Coordinate::stringFromColumnIndex($index + 1)] = self::NUMBER_FORMAT;
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
}
