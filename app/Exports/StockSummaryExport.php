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
 * Mirrors InventoryReportController::stockSummary()'s own `rows` payload: one
 * line per item with its unit in its own column, then opening, in, out and closing
 * quantity as numeric cells (quantities in different units are never added
 * together, so the total row leaves them blank), then the in-transit quantity and value per shown bucket, the
 * average cost and the valuation, and a total-valuation line.
 *
 * Quantity and money cells are real numbers with a display format. `toFloat()`
 * is used at exactly that boundary and nowhere else (C1): the values it
 * converts are already-final strings, so no arithmetic happens on a float.
 */
class StockSummaryExport implements FromCollection, ShouldAutoSize, WithColumnFormatting, WithHeadings, WithStrictNullComparison, WithStyles
{
    private const LEADING_COLUMNS = 6;

    private const TRANSIT_HEADERS = [
        'within' => 'In transit',
        'outgoing' => 'Dispatched, awaiting receipt',
        'incoming' => 'Incoming',
    ];

    /**
     * @param  list<array<string, mixed>>  $rows  stockSummary()'s `rows` (name, unit, opening, qtyIn, qtyOut, closing, avgCost, valuation, transit?)
     * @param  string  $grandTotalValuation  Total value of every row
     * @param  string  $from  Period start (AD, Y-m-d)
     * @param  string  $to  Period end (AD, Y-m-d)
     * @param  string|null  $branchLabel  BranchFilter::label() in a multi-branch tenant, else null (no extra preamble row)
     * @param  array{buckets: list<string>, totals: array<string, string>}|null  $inTransit  stockSummary()'s `inTransit`
     */
    public function __construct(
        private readonly array $rows,
        private readonly string $grandTotalValuation,
        private readonly string $from,
        private readonly string $to,
        private readonly ?string $branchLabel = null,
        private readonly ?array $inTransit = null,
    ) {}

    /**
     * @return Collection<int, list<string|float>>
     */
    public function collection(): Collection
    {
        $buckets = $this->buckets();
        $lines = collect();

        foreach ($this->rows as $row) {
            $unit = (string) ($row['unit'] ?? '');
            $transitCells = [];

            foreach ($buckets as $bucket) {
                $figures = $row['transit'][$bucket] ?? ['quantity' => '0', 'value' => '0'];

                $transitCells[] = $this->quantityText($figures['quantity'], $unit);
                $transitCells[] = Money::of($figures['value'])->toFloat();
            }

            $lines->push([
                $row['name'] ?? '',
                $unit,
                Quantity::of($row['opening'])->toFloat(),
                Quantity::of($row['qtyIn'])->toFloat(),
                Quantity::of($row['qtyOut'])->toFloat(),
                Quantity::of($row['closing'])->toFloat(),
                ...$transitCells,
                Quantity::of($row['avgCost'] ?? '0')->toFloat(),
                Money::of($row['valuation'])->toFloat(),
            ]);
        }

        $totalTransitCells = [];

        foreach ($buckets as $bucket) {
            $totalTransitCells[] = '';
            $totalTransitCells[] = Money::of($this->inTransit['totals'][$bucket] ?? '0')->toFloat();
        }

        $lines->push([
            'Total valuation',
            '',
            '',
            '',
            '',
            '',
            ...$totalTransitCells,
            '',
            Money::of($this->grandTotalValuation)->toFloat(),
        ]);

        return $lines;
    }

    /**
     * @return list<list<string>>
     */
    public function headings(): array
    {
        $rows = [['Stock Summary'], ["Period: {$this->from} to {$this->to} (AD)"]];

        if ($this->branchLabel !== null) {
            $rows[] = [$this->branchLabel];
        }

        $rows[] = [''];

        $header = ['Item', 'Unit', 'Opening Qty', 'Qty In', 'Qty Out', 'Closing Qty'];

        foreach ($this->buckets() as $bucket) {
            $name = self::TRANSIT_HEADERS[$bucket] ?? $bucket;
            $header[] = "{$name} Quantity";
            $header[] = "{$name} Value";
        }

        $rows[] = [...$header, 'Average Cost per Unit', 'Valuation'];

        return $rows;
    }

    /**
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        $transitColumns = count($this->buckets()) * 2;
        $formats = [
            'C' => '#,##0.00##',
            'D' => '#,##0.00##',
            'E' => '#,##0.00##',
            'F' => '#,##0.00##',
            Coordinate::stringFromColumnIndex(self::LEADING_COLUMNS + $transitColumns + 1) => '#,##0.00##',
            Coordinate::stringFromColumnIndex(self::LEADING_COLUMNS + $transitColumns + 2) => '#,##0.00',
        ];

        foreach (array_keys($this->buckets()) as $index) {
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
        $lastColumn = Coordinate::stringFromColumnIndex(self::LEADING_COLUMNS + (count($this->buckets()) * 2) + 2);

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
     * @return list<string>
     */
    private function buckets(): array
    {
        return $this->inTransit['buckets'] ?? [];
    }

    /**
     * "12.5 pcs": the quantity without padding zeros, then its unit.
     */
    private function quantityText(string $quantity, string $unit): string
    {
        return trim(Quantity::of($quantity)->formatQuantity().' '.$unit);
    }
}
