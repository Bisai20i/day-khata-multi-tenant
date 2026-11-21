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
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Mirrors StockPotentialProfitReportController::index()'s `rows` and
 * `totals`: one line per item (quantity as text with its unit, because
 * quantities in different units are never added together), then a Total
 * line. An item with no selling rate leaves its sell rate, sell value,
 * profit and margin cells blank, never zero.
 *
 * Money and rate cells are real numbers with a display format. `toFloat()`
 * is used at exactly that boundary and nowhere else (C1): the values it
 * converts are already-final strings, so no arithmetic happens on a float.
 */
class StockPotentialProfitExport implements FromCollection, ShouldAutoSize, WithColumnFormatting, WithHeadings, WithStrictNullComparison, WithStyles
{
    private const LAST_COLUMN = 'I';

    /**
     * @param  list<array<string, mixed>>  $rows  index()'s `rows`
     * @param  array{costValue: string, pricedCostValue: string, sellValue: string, profit: string, marginPercent: string|null}  $totals
     * @param  int  $unpricedCount  Items with no selling rate, left out of the sell and profit totals
     * @param  string  $asOf  AD, Y-m-d
     * @param  string|null  $branchLabel  BranchFilter::label() in a multi-branch tenant, else null
     */
    public function __construct(
        private readonly array $rows,
        private readonly array $totals,
        private readonly int $unpricedCount,
        private readonly string $asOf,
        private readonly ?string $branchLabel = null,
    ) {}

    /**
     * @return Collection<int, list<string|float>>
     */
    public function collection(): Collection
    {
        $lines = collect();

        foreach ($this->rows as $row) {
            $unit = (string) ($row['unit'] ?? '');

            $lines->push([
                $row['name'] ?? '',
                $unit,
                trim(Quantity::of($row['quantity'])->formatQuantity().' '.$unit),
                Quantity::of($row['costRate'] ?? '0')->toFloat(),
                $row['sellRate'] === null ? '' : Quantity::of($row['sellRate'])->toFloat(),
                Money::of($row['costValue'])->toFloat(),
                $this->moneyCell($row['sellValue']),
                $this->moneyCell($row['profit']),
                $this->moneyCell($row['marginPercent']),
            ]);
        }

        $lines->push([
            'Total',
            '',
            '',
            '',
            '',
            Money::of($this->totals['costValue'])->toFloat(),
            Money::of($this->totals['sellValue'])->toFloat(),
            Money::of($this->totals['profit'])->toFloat(),
            $this->moneyCell($this->totals['marginPercent']),
        ]);

        return $lines;
    }

    /**
     * @return list<list<string>>
     */
    public function headings(): array
    {
        $rows = [['Stock with Potential Profit'], ["As on {$this->asOf} (AD)"]];

        if ($this->branchLabel !== null) {
            $rows[] = [$this->branchLabel];
        }

        // Notes split into short rows so a merged A:I cell never clips them;
        // styles() counts every row through count($this->headings()).
        $rows[] = ['Cost: weighted average cost, the same basis as Stock Valuation and the Balance Sheet.'];
        $rows[] = ['Sell rate: the selling rate on the item record, before VAT.'];

        if ($this->unpricedCount > 0) {
            $rows[] = ["{$this->unpricedCount} item(s) holding stock have no selling rate and are left out of the sell and profit totals."];
            $rows[] = ["Cost of the priced items: {$this->totals['pricedCostValue']}."];
        }

        $rows[] = [''];
        $rows[] = ['Item', 'Unit', 'Quantity', 'Cost Rate', 'Sell Rate', 'Stock Value at Cost', 'Stock Value at Sell', 'Potential Profit', 'Margin %'];

        return $rows;
    }

    /**
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        return [
            'D' => '#,##0.00##',
            'E' => '#,##0.00##',
            'F' => '#,##0.00',
            'G' => '#,##0.00',
            'H' => '#,##0.00',
            'I' => '0.00',
        ];
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
            $sheet->mergeCells("A{$row}:".self::LAST_COLUMN.$row);
        }

        return [
            1 => ['font' => ['bold' => true, 'size' => 13]],
            $headingRowCount => ['font' => ['bold' => true]],
            $headingRowCount + count($this->rows) + 1 => ['font' => ['bold' => true]],
        ];
    }

    /**
     * A 2-decimal figure as a numeric cell, or a blank cell when there is no
     * figure (an item with no selling rate, a margin with no sell value).
     */
    private function moneyCell(?string $value): float|string
    {
        return $value === null ? '' : Money::of($value)->toFloat();
    }
}
