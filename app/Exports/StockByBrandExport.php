<?php

namespace App\Exports;

use App\Support\Money\Money;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Mirrors BrandWiseReportController::stockByBrand()'s own
 * `rows`/`grandTotal` payload: one line per brand (plus "Unbranded" when it
 * holds stock), then the grand total valuation.
 *
 * Quantities stay a per-unit text breakdown ("12 Box, 3.5 Kg") exactly as
 * the page shows them - never one number added across units, and never
 * totalled. Every valuation cell is a real number with a 2-decimal display
 * format; `Money::toFloat()` is used at exactly that boundary and nowhere
 * else (C1).
 */
class StockByBrandExport implements FromCollection, ShouldAutoSize, WithColumnFormatting, WithHeadings, WithStrictNullComparison, WithStyles
{
    /**
     * @param  array<int, array<string, mixed>>  $rows  stockByBrand()'s `rows`
     * @param  array{value: string, quantities: array<int, array{unit: string, quantity: string}>}  $grandTotal
     * @param  string  $asOf  As-of date (AD, Y-m-d)
     * @param  string|null  $branchLabel  BranchFilter::label() in a multi-branch tenant, else null (no extra preamble row)
     * @param  string|null  $storeName  The chosen store, else null (no extra preamble row)
     */
    public function __construct(
        private readonly array $rows,
        private readonly array $grandTotal,
        private readonly string $asOf,
        private readonly ?string $branchLabel = null,
        private readonly ?string $storeName = null,
    ) {}

    /**
     * @return Collection<int, list<string|float>>
     */
    public function collection(): Collection
    {
        $lines = collect();

        foreach ($this->rows as $brand) {
            $lines->push([
                $brand['brandName'],
                $this->quantityLabel($brand['quantities'] ?? []),
                Money::of($brand['value'])->toFloat(),
            ]);
        }

        $lines->push(['', '', '']);
        $lines->push(['Grand Total Valuation', '', Money::of($this->grandTotal['value'] ?? '0.00')->toFloat()]);

        return $lines;
    }

    /**
     * @return list<list<string>>
     */
    public function headings(): array
    {
        $rows = [['Stock by Brand'], ["As on {$this->asOf} (AD)"]];

        if ($this->branchLabel !== null) {
            $rows[] = [$this->branchLabel];
        }

        if ($this->storeName !== null) {
            $rows[] = ["Store: {$this->storeName}"];
        }

        $rows[] = [''];
        $rows[] = ['Brand', 'Quantity', 'Valuation'];

        return $rows;
    }

    /**
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        return ['C' => NumberFormat::FORMAT_NUMBER_00];
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
            $sheet->mergeCells("A{$row}:C{$row}");
        }

        $totalRow = $headingRowCount + count($this->rows) + 2;
        $sheet->mergeCells("A{$totalRow}:B{$totalRow}");

        return [
            1 => ['font' => ['bold' => true, 'size' => 13]],
            $headingRowCount => ['font' => ['bold' => true]],
            $totalRow => ['font' => ['bold' => true]],
        ];
    }

    /**
     * The page's per-unit breakdown, trailing zeros trimmed as text (never
     * through a float): "12 Box, 3.5 Kg", or "-" when nothing is held.
     *
     * @param  array<int, array{unit: string, quantity: string}>  $quantities
     */
    private function quantityLabel(array $quantities): string
    {
        if ($quantities === []) {
            return '-';
        }

        return implode(', ', array_map(
            fn (array $entry): string => trim($this->trimQuantity($entry['quantity']).' '.$entry['unit']),
            $quantities,
        ));
    }

    private function trimQuantity(string $quantity): string
    {
        return str_contains($quantity, '.') ? rtrim(rtrim($quantity, '0'), '.') : $quantity;
    }
}
