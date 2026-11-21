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
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Mirrors StockAgeingReportController's own `rows`/`totals` payload: one line
 * per stockable item with its quantity, value, oldest-stock age in days, the
 * four age buckets (quantity and value each) and the unaged remainder, then a
 * totals line.
 *
 * Quantity cells use a 4-decimal format and money cells a 2-decimal format.
 * `toFloat()` is used at exactly that cell boundary and nowhere else (C1):
 * the values it converts are the already-final strings, so no arithmetic ever
 * happens on a float. Oldest days is a plain integer.
 */
class StockAgeingExport implements FromCollection, ShouldAutoSize, WithColumnFormatting, WithHeadings, WithStrictNullComparison, WithStyles
{
    /** @var array<string, string> bucket key => heading label */
    private const BUCKETS = [
        'd0_30' => '0-30 days',
        'd31_60' => '31-60 days',
        'd61_90' => '61-90 days',
        'd90_plus' => 'Over 90 days',
    ];

    private const LEADING_COLUMNS = 3;

    /**
     * @param  list<array<string, mixed>>  $rows  The controller's `rows`
     * @param  array<string, string>  $totals  The controller's `totals`
     * @param  string  $asOf  As-of date (AD, Y-m-d)
     * @param  string|null  $branchLabel  BranchFilter::label() when a branch filter is active, else null (no extra preamble row)
     */
    public function __construct(
        private readonly array $rows,
        private readonly array $totals,
        private readonly string $asOf,
        private readonly ?string $branchLabel = null,
    ) {}

    /**
     * @return Collection<int, list<string|int|float|null>>
     */
    public function collection(): Collection
    {
        $lines = collect();

        foreach ($this->rows as $row) {
            $lines->push([
                $row['name'] ?? '',
                ($row['barcode'] ?? null) ?: ($row['hsCode'] ?? '') ?: '',
                $row['unit'] ?? '',
                ...$this->figureCells($row),
            ]);
        }

        $lines->push(['Total', '', '', ...$this->figureCells([...$this->totals, 'oldestDays' => null])]);

        return $lines;
    }

    /**
     * @return list<list<string>>
     */
    public function headings(): array
    {
        $rows = [['Stock Ageing'], ["As on {$this->asOf} (AD)"]];

        if ($this->branchLabel !== null) {
            $rows[] = [$this->branchLabel];
        }

        $rows[] = [''];

        $headings = ['Item', 'Code', 'Unit', 'Quantity', 'Total Value', 'Oldest Stock (days)'];

        foreach (self::BUCKETS as $label) {
            $headings[] = "{$label} Qty";
            $headings[] = "{$label} Value";
        }

        $rows[] = [...$headings, 'Unaged Qty', 'Unaged Value'];

        return $rows;
    }

    /**
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        $formats = [
            $this->columnLetter(self::LEADING_COLUMNS + 1) => '#,##0.0000',
            $this->columnLetter(self::LEADING_COLUMNS + 2) => NumberFormat::FORMAT_NUMBER_00,
            $this->columnLetter(self::LEADING_COLUMNS + 3) => NumberFormat::FORMAT_NUMBER,
        ];

        $index = self::LEADING_COLUMNS + 4;

        foreach ([...array_keys(self::BUCKETS), 'unaged'] as $ignored) {
            $formats[$this->columnLetter($index++)] = '#,##0.0000';
            $formats[$this->columnLetter($index++)] = NumberFormat::FORMAT_NUMBER_00;
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
        $lastColumn = $this->columnLetter(self::LEADING_COLUMNS + 3 + (count(self::BUCKETS) + 1) * 2);

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

    private function columnLetter(int $index): string
    {
        return Coordinate::stringFromColumnIndex($index);
    }

    /**
     * Quantity, total value, oldest days, each bucket's qty/value, unaged
     * qty/value: the columns after Item / Code / Unit.
     *
     * @param  array<string, mixed>  $source
     * @return list<float|int|string>
     */
    private function figureCells(array $source): array
    {
        $cells = [
            Quantity::of($source['quantity'] ?? '0')->toFloat(),
            Money::of($source['value'] ?? '0')->toFloat(),
            isset($source['oldestDays']) ? (int) $source['oldestDays'] : '',
        ];

        foreach (array_keys(self::BUCKETS) as $bucket) {
            $cells[] = Quantity::of($source[$bucket.'Qty'] ?? '0')->toFloat();
            $cells[] = Money::of($source[$bucket.'Value'] ?? '0')->toFloat();
        }

        $cells[] = Quantity::of($source['unagedQuantity'] ?? '0')->toFloat();
        $cells[] = Money::of($source['unagedValue'] ?? '0')->toFloat();

        return $cells;
    }
}
