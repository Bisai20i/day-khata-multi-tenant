<?php

namespace App\Exports;

use App\Support\Money\Money;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

/**
 * A plain heading + rows sheet for the HR reports (payroll register, staff
 * balances). Cells in `$moneyColumns` (zero-based) hold exact decimal strings
 * and become real 2-decimal numbers here, at the Excel boundary - the only
 * place a float is legitimate (.ai/rules/lib.md).
 */
class HrTableExport implements FromArray, WithColumnFormatting, WithHeadings
{
    /**
     * @param  list<string>  $headings
     * @param  list<list<string|null>>  $rows
     * @param  list<int>  $moneyColumns
     */
    public function __construct(
        private readonly array $headings,
        private readonly array $rows,
        private readonly array $moneyColumns,
    ) {}

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return $this->headings;
    }

    /**
     * @return list<list<string|float|null>>
     */
    public function array(): array
    {
        return array_map(function (array $row): array {
            foreach ($this->moneyColumns as $column) {
                if (isset($row[$column]) && $row[$column] !== '') {
                    $row[$column] = Money::of($row[$column])->toFloat();
                }
            }

            return $row;
        }, $this->rows);
    }

    /**
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        $formats = [];

        foreach ($this->moneyColumns as $column) {
            $formats[Coordinate::stringFromColumnIndex($column + 1)] = NumberFormat::FORMAT_NUMBER_00;
        }

        return $formats;
    }
}
