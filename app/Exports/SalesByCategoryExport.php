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
 * Mirrors CategoryWiseReportController::salesByCategory()'s own
 * `rows`/`grandTotal` payload: one bold line per category, its subcategories
 * beneath it (the Subcategory column carries the indent), then the grand
 * total. PurchaseByCategoryExport only changes the title and the wording.
 *
 * Quantities stay a per-unit text breakdown ("12 Box, 3.5 Kg") exactly as
 * the page shows them, never one number added across units. Value, Returns
 * and Net (value minus returns, the last column, F) are real numbers with a
 * 2-decimal display format; `toFloat()` is used at exactly that boundary and
 * nowhere else (C1). All three are after line and document discounts, before
 * VAT, the Item-wise basis.
 */
class SalesByCategoryExport implements FromCollection, ShouldAutoSize, WithColumnFormatting, WithHeadings, WithStrictNullComparison, WithStyles
{
    /**
     * @param  array<int, array<string, mixed>>  $rows  The report's `rows`
     * @param  array{value: string, returns: string, net: string, quantities: array<int, array{unit: string, quantity: string}>}  $grandTotal
     * @param  string  $from  Start date (AD, Y-m-d)
     * @param  string  $to  End date (AD, Y-m-d)
     * @param  string|null  $branchLabel  BranchFilter::label() in a multi-branch tenant, else null (no extra preamble row)
     * @param  string|null  $storeName  The chosen store, else null (no extra preamble row)
     */
    public function __construct(
        protected readonly array $rows,
        protected readonly array $grandTotal,
        protected readonly string $from,
        protected readonly string $to,
        protected readonly ?string $branchLabel = null,
        protected readonly ?string $storeName = null,
    ) {}

    protected function title(): string
    {
        return 'Sales by Category';
    }

    protected function valueHeading(): string
    {
        return 'Sales';
    }

    protected function returnsHeading(): string
    {
        return 'Sales Returns';
    }

    /**
     * @return Collection<int, list<string|float>>
     */
    public function collection(): Collection
    {
        $lines = collect();

        foreach ($this->rows as $category) {
            $lines->push([
                $category['categoryName'],
                '',
                $this->quantityLabel($category['quantities'] ?? []),
                Money::of($category['value'])->toFloat(),
                Money::of($category['returns'] ?? '0.00')->toFloat(),
                Money::of($category['net'] ?? '0.00')->toFloat(),
            ]);

            foreach ($category['subcategories'] ?? [] as $subcategory) {
                $lines->push([
                    '',
                    '    '.$subcategory['subcategoryName'],
                    $this->quantityLabel($subcategory['quantities'] ?? []),
                    Money::of($subcategory['value'])->toFloat(),
                    Money::of($subcategory['returns'] ?? '0.00')->toFloat(),
                    Money::of($subcategory['net'] ?? '0.00')->toFloat(),
                ]);
            }
        }

        $lines->push(['', '', '', '', '', '']);
        $lines->push([
            'Grand Total',
            '',
            $this->quantityLabel($this->grandTotal['quantities'] ?? []),
            Money::of($this->grandTotal['value'] ?? '0.00')->toFloat(),
            Money::of($this->grandTotal['returns'] ?? '0.00')->toFloat(),
            Money::of($this->grandTotal['net'] ?? '0.00')->toFloat(),
        ]);

        return $lines;
    }

    /**
     * @return list<list<string>>
     */
    public function headings(): array
    {
        $rows = [[$this->title()], ["Period {$this->from} to {$this->to} (AD)"]];

        if ($this->branchLabel !== null) {
            $rows[] = [$this->branchLabel];
        }

        if ($this->storeName !== null) {
            $rows[] = ["Store: {$this->storeName}"];
        }

        $rows[] = [''];
        $rows[] = ['Category', 'Subcategory', 'Quantity', $this->valueHeading(), $this->returnsHeading(), 'Net'];

        return $rows;
    }

    /**
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        return [
            'D' => NumberFormat::FORMAT_NUMBER_00,
            'E' => NumberFormat::FORMAT_NUMBER_00,
            'F' => NumberFormat::FORMAT_NUMBER_00,
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
            $sheet->mergeCells("A{$row}:F{$row}");
        }

        $styles = [
            1 => ['font' => ['bold' => true, 'size' => 13]],
            $headingRowCount => ['font' => ['bold' => true]],
        ];

        $row = $headingRowCount;

        foreach ($this->rows as $category) {
            $row++;
            $styles[$row] = ['font' => ['bold' => true]];
            $row += count($category['subcategories'] ?? []);
        }

        $styles[$row + 2] = ['font' => ['bold' => true]];

        return $styles;
    }

    /**
     * The page's per-unit breakdown, trailing zeros trimmed as text (never
     * through a float): "12 Box, 3.5 Kg", or "-" when nothing was moved.
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
