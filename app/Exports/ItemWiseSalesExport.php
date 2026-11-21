<?php

namespace App\Exports;

use App\Support\Money\Money;
use App\Support\Money\Quantity;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Mirrors ItemWiseSalesReportController's own `items` / `totals` / `lines` /
 * `lineTotals` payload (the export and the page share one report() method):
 * the item summary with a Total row and one quantity total row per base unit
 * (different units are never added together), then, when an item is
 * selected, that item's sale and return lines with their own Total row.
 *
 * ItemWisePurchaseExport reuses this layout with purchase wording.
 *
 * Every amount and quantity cell is a real number with a display format.
 * `toFloat()` is used at exactly that boundary and nowhere else (C1): the
 * values it converts are the already-final strings, so no arithmetic ever
 * happens on a float. WithStrictNullComparison keeps a real 0 a 0 (without
 * it PhpSpreadsheet writes zero as a blank cell); blank cells are ''.
 */
class ItemWiseSalesExport implements FromCollection, ShouldAutoSize, WithHeadings, WithStrictNullComparison, WithStyles
{
    /** Summary columns after SN, Item, Unit: [key, kind] in order. */
    private const SUMMARY_KEYS = [
        ['total_quantity', 'quantity'],
        ['total_value', 'money'],
        ['vat_amount', 'money'],
        ['gross_amount', 'money'],
        ['transaction_count', 'count'],
        ['returned_quantity', 'quantity'],
        ['returned_value', 'money'],
        ['returned_vat', 'money'],
        ['returned_gross', 'money'],
        ['net_quantity', 'quantity'],
        ['net_value', 'money'],
        ['net_gross', 'money'],
    ];

    /** Detail amount columns after the label columns: [key, kind] in order. */
    private const DETAIL_KEYS = [
        ['rate', 'rate'],
        ['quantity', 'quantity'],
        ['bonus_quantity', 'quantity'],
        ['base_quantity', 'quantity'],
        ['line_total', 'money'],
        ['net_amount', 'money'],
        ['vat_amount', 'money'],
        ['gross_amount', 'money'],
    ];

    private const QUANTITY_FORMAT = '#,##0.0000';

    /**
     * @param  list<array<string, mixed>>  $items  report()['items']
     * @param  array<string, mixed>  $totals  report()['totals']
     * @param  list<array<string, mixed>>  $lines  report()['lines'] (empty when no item is selected)
     * @param  array<string, string>  $lineTotals  report()['lineTotals']
     * @param  string  $from  Period start (AD, Y-m-d)
     * @param  string  $to  Period end (AD, Y-m-d)
     * @param  string|null  $selectedItemName  The drilled-down item, or null for no detail section
     * @param  bool  $hasBranchColumn  Multi-branch tenant: a Branch column in the detail lines.
     * @param  string|null  $branchLabel  BranchFilter::label() in a multi-branch tenant, else null
     * @param  list<string>  $filterLabels  Applied store / item filters ("Store: Main"), empty for none
     */
    public function __construct(
        private readonly array $items,
        private readonly array $totals,
        private readonly array $lines,
        private readonly array $lineTotals,
        private readonly string $from,
        private readonly string $to,
        private readonly ?string $selectedItemName = null,
        private readonly bool $hasBranchColumn = false,
        private readonly ?string $branchLabel = null,
        private readonly array $filterLabels = [],
    ) {}

    protected function title(): string
    {
        return 'Item-wise Sales';
    }

    /**
     * @return array{quantity: string, value: string, vat: string, gross: string, documents: string, document: string, party: string, main_kind: string}
     */
    protected function labels(): array
    {
        return [
            'quantity' => 'Qty Sold (base)',
            'value' => 'Sales Value (excl. VAT)',
            'vat' => 'VAT',
            'gross' => 'Sales incl. VAT',
            'documents' => 'Invoices',
            'document' => 'Invoice / Credit Note No',
            'party' => 'Customer',
            'main_kind' => 'Sale',
        ];
    }

    /**
     * @return Collection<int, list<string|int|float>>
     */
    public function collection(): Collection
    {
        $rows = collect();

        foreach ($this->items as $index => $item) {
            $rows->push([$index + 1, $item['name'] ?? '', $item['unit'] ?? '', ...$this->cells($item, self::SUMMARY_KEYS)]);
        }

        $rows->push([
            'Total', '', '',
            ...array_map(
                fn (array $column): string|float => $column[1] === 'money' ? Money::of($this->totals[$column[0]] ?? '0')->toFloat() : '',
                self::SUMMARY_KEYS,
            ),
        ]);

        foreach ($this->unitTotals() as $unit => $quantities) {
            $cells = array_fill(0, count(self::SUMMARY_KEYS), '');
            $cells[0] = Quantity::of($quantities['total'])->toFloat();
            $cells[5] = Quantity::of($quantities['returned'])->toFloat();
            $cells[9] = Quantity::of($quantities['net'])->toFloat();
            $rows->push(['Total quantity', $unit === '' ? '-' : $unit, '', ...$cells]);
        }

        if ($this->hasDetail()) {
            $rows->push(['']);
            $rows->push(["Transaction detail: {$this->selectedItemName} (returns are negative; the total equals the item's Net row above)"]);
            $rows->push($this->detailHeadings());

            foreach ($this->lines as $index => $line) {
                $rows->push([
                    $index + 1,
                    $line['date'] ?? '',
                    ($line['kind'] ?? '') === 'return' ? 'Return' : $this->labels()['main_kind'],
                    $line['document_number'] ?? '',
                    $line['party_name'] ?? '',
                    ...($this->hasBranchColumn ? [$line['branch'] ?? ''] : []),
                    ...$this->cells($line, self::DETAIL_KEYS),
                ]);
            }

            $total = array_fill(0, count(self::DETAIL_KEYS), '');
            $total[3] = Quantity::of($this->lineTotals['base_quantity'] ?? '0')->toFloat();
            $total[5] = Money::of($this->lineTotals['net_amount'] ?? '0')->toFloat();
            $total[6] = Money::of($this->lineTotals['vat_amount'] ?? '0')->toFloat();
            $total[7] = Money::of($this->lineTotals['gross_amount'] ?? '0')->toFloat();

            $rows->push(['Total', ...array_fill(0, $this->detailLeadingCount() - 1, ''), ...$total]);
        }

        return $rows;
    }

    /**
     * @return list<list<string>>
     */
    public function headings(): array
    {
        $labels = $this->labels();
        $rows = [[$this->title()], ["Period: {$this->from} to {$this->to} (AD)"]];

        if ($this->branchLabel !== null) {
            $rows[] = [$this->branchLabel];
        }

        if ($this->filterLabels !== []) {
            $rows[] = ['Filters: '.implode('; ', $this->filterLabels)];
        }

        $rows[] = ['Quantities are in base units and include bonus (free) units. Values are after line and bill discounts; "incl. VAT" adds VAT and the round off share.'];
        $rows[] = [''];
        $rows[] = [
            'SN', 'Item', 'Unit',
            $labels['quantity'], $labels['value'], $labels['vat'], $labels['gross'], $labels['documents'],
            'Returned Qty (base)', 'Returned Value (excl. VAT)', 'Returned VAT', 'Returned incl. VAT',
            'Net Qty (base)', 'Net Value (excl. VAT)', 'Net incl. VAT',
        ];

        return $rows;
    }

    /**
     * @return array<int|string, array<string, mixed>>
     */
    public function styles(Worksheet $sheet): array
    {
        $headingRowCount = count($this->headings());
        $firstData = $headingRowCount + 1;
        $totalRow = $headingRowCount + count($this->items) + 1;
        $lastSummaryRow = $totalRow + count($this->unitTotals());
        $lastSummaryColumn = $this->letter(3 + count(self::SUMMARY_KEYS));

        // Long text lives in merged cells so ShouldAutoSize does not widen column A.
        foreach (range(1, $headingRowCount - 2) as $row) {
            $sheet->mergeCells("A{$row}:{$lastSummaryColumn}{$row}");
        }

        $this->formatColumns($sheet, self::SUMMARY_KEYS, 4, $firstData, $lastSummaryRow);

        $styles = [
            1 => ['font' => ['bold' => true, 'size' => 13]],
            $headingRowCount => ['font' => ['bold' => true]],
        ];

        foreach (range($totalRow, $lastSummaryRow) as $row) {
            $styles[$row] = ['font' => ['bold' => true]];
        }

        if ($this->hasDetail()) {
            $titleRow = $lastSummaryRow + 2;
            $headingRow = $titleRow + 1;
            $detailTotalRow = $headingRow + count($this->lines) + 1;
            $lastDetailColumn = $this->letter($this->detailLeadingCount() + count(self::DETAIL_KEYS));

            $sheet->mergeCells("A{$titleRow}:{$lastDetailColumn}{$titleRow}");
            $this->formatColumns($sheet, self::DETAIL_KEYS, $this->detailLeadingCount() + 1, $headingRow + 1, $detailTotalRow);

            $styles[$titleRow] = ['font' => ['bold' => true]];
            $styles[$headingRow] = ['font' => ['bold' => true]];
            $styles[$detailTotalRow] = ['font' => ['bold' => true]];
        }

        return $styles;
    }

    private function hasDetail(): bool
    {
        return $this->selectedItemName !== null;
    }

    /**
     * @return list<string>
     */
    private function detailHeadings(): array
    {
        $labels = $this->labels();

        return [
            'SN', 'Date (AD)', 'Type', $labels['document'], $labels['party'],
            ...($this->hasBranchColumn ? ['Branch'] : []),
            'Rate', 'Qty (as entered)', 'Bonus Qty', 'Qty (base)', 'Line Value', 'Value (excl. VAT)', 'VAT', 'Value incl. VAT',
        ];
    }

    /**
     * Columns before the first detail amount column: SN..Party, plus Branch when shown.
     */
    private function detailLeadingCount(): int
    {
        return $this->hasBranchColumn ? 6 : 5;
    }

    /**
     * Sold / returned / net quantity per base unit, keyed by unit.
     *
     * @return array<string, array{total: string, returned: string, net: string}>
     */
    private function unitTotals(): array
    {
        $byUnit = [];

        foreach (['total' => 'quantities', 'returned' => 'returned_quantities', 'net' => 'net_quantities'] as $slot => $key) {
            foreach ($this->totals[$key] ?? [] as $entry) {
                $unit = (string) ($entry['unit'] ?? '');
                $byUnit[$unit] ??= ['total' => '0', 'returned' => '0', 'net' => '0'];
                $byUnit[$unit][$slot] = $entry['quantity'];
            }
        }

        ksort($byUnit, SORT_NATURAL | SORT_FLAG_CASE);

        return $byUnit;
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  list<array{0: string, 1: string}>  $columns
     * @return list<int|float>
     */
    private function cells(array $source, array $columns): array
    {
        return array_map(
            fn (array $column): int|float => match ($column[1]) {
                'money' => Money::of($source[$column[0]] ?? '0')->toFloat(),
                'count' => (int) ($source[$column[0]] ?? 0),
                default => Quantity::of($source[$column[0]] ?? '0')->toFloat(),
            },
            $columns,
        );
    }

    /**
     * @param  list<array{0: string, 1: string}>  $columns
     */
    private function formatColumns(Worksheet $sheet, array $columns, int $firstColumn, int $firstRow, int $lastRow): void
    {
        if ($lastRow < $firstRow) {
            return;
        }

        foreach ($columns as $offset => $column) {
            $format = match ($column[1]) {
                'money' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1,
                'count' => NumberFormat::FORMAT_NUMBER,
                default => self::QUANTITY_FORMAT,
            };
            $letter = $this->letter($firstColumn + $offset);

            $sheet->getStyle("{$letter}{$firstRow}:{$letter}{$lastRow}")->getNumberFormat()->setFormatCode($format);
        }
    }

    private function letter(int $index): string
    {
        return Coordinate::stringFromColumnIndex($index);
    }
}
