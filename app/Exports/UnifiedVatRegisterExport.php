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
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Mirrors UnifiedVatRegisterController::index()'s own `rows`/`totals` payload
 * (see App\Support\Reports\UnifiedVatRegister): one chronological line per
 * sale, sales return, purchase and purchase return, then an Output VAT block,
 * an Input VAT block and the net VAT payable (or refundable) line. There is no
 * single grand total - output and input VAT are never added together.
 *
 * Every monetary cell is a real number with a 2-decimal display format.
 * `Money::toFloat()` is used at exactly that boundary and nowhere else (C1):
 * the values it converts are the already-final signed strings, so no
 * arithmetic ever happens on a float.
 */
class UnifiedVatRegisterExport implements FromCollection, ShouldAutoSize, WithColumnFormatting, WithHeadings, WithStrictNullComparison, WithStyles
{
    private const AMOUNT_KEYS = ['subtotal', 'nontaxable_amount', 'taxable_amount', 'vat_amount', 'total'];

    /**
     * @param  list<array<string, mixed>>  $rows  UnifiedVatRegister::build()['rows']
     * @param  array{output: array<string, mixed>, input: array<string, mixed>, netVatPayable: string}  $totals
     * @param  string  $from  Period start (AD, Y-m-d)
     * @param  string  $to  Period end (AD, Y-m-d)
     * @param  bool  $hasBranchColumn  Multi-branch tenant (BR14): a Branch column after PAN from each row's `branch`.
     * @param  string|null  $branchLabel  BranchFilter::label() in a multi-branch tenant, else null (no extra preamble row)
     */
    public function __construct(
        private readonly array $rows,
        private readonly array $totals,
        private readonly string $from,
        private readonly string $to,
        private readonly bool $hasBranchColumn = false,
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
                $row['sn'],
                $row['date'] ?? '',
                $row['type'] ?? '',
                ($row['entry'] ?? 'issued') === 'cancelled' ? 'Cancelled' : 'Issued',
                $row['document_number'] ?? '',
                $row['reference'] ?? '',
                $row['party'] ?? '',
                $row['party_pan'] ?? '',
                ...($this->hasBranchColumn ? [$row['branch'] ?? ''] : []),
                ...$this->amountCells($row),
            ]);
        }

        $lines->push($this->blankLine());
        $lines->push($this->summaryLine('Output VAT (Sales side)', $this->totals['output']));
        $lines->push($this->summaryLine('Input VAT (Purchase side)', $this->totals['input']));

        $net = Money::of($this->totals['netVatPayable'] ?? '0.00');
        $amounts = array_fill(0, count(self::AMOUNT_KEYS), '');
        $amounts[3] = $net->abs()->toFloat();

        $lines->push([
            $net->isNegative() ? 'Net VAT Refundable (Input - Output)' : 'Net VAT Payable (Output - Input)',
            ...array_fill(0, $this->leadingColumnCount() - 1, ''),
            ...$amounts,
        ]);

        return $lines;
    }

    /**
     * @return list<list<string>>
     */
    public function headings(): array
    {
        $rows = [['Unified VAT Register'], ["Period: {$this->from} to {$this->to} (AD)"]];

        if ($this->branchLabel !== null) {
            $rows[] = [$this->branchLabel];
        }

        $rows[] = [''];

        $rows[] = [
            'SN', 'Date', 'Type', 'Entry', 'Document No', 'Against', 'Party', 'PAN',
            ...($this->hasBranchColumn ? ['Branch'] : []),
            'Total Before Tax', 'Exempt', 'Taxable', 'VAT', 'Total',
        ];

        return $rows;
    }

    /**
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        $first = $this->leadingColumnCount() + 1;
        $formats = [];

        foreach (range($first, $first + count(self::AMOUNT_KEYS) - 1) as $index) {
            $formats[$this->columnLetter($index)] = NumberFormat::FORMAT_NUMBER_00;
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
        $dataRowCount = count($this->rows);
        $lastLabelColumn = $this->columnLetter($this->leadingColumnCount());

        // Long text lives in merged cells so ShouldAutoSize does not widen column A.
        foreach (range(1, $preambleRowCount) as $row) {
            $sheet->mergeCells("A{$row}:{$lastLabelColumn}{$row}");
        }

        $outputRow = $headingRowCount + $dataRowCount + 2;

        foreach (range($outputRow, $outputRow + 2) as $row) {
            $sheet->mergeCells("A{$row}:{$lastLabelColumn}{$row}");
        }

        return [
            1 => ['font' => ['bold' => true, 'size' => 13]],
            $headingRowCount => ['font' => ['bold' => true]],
            $outputRow => ['font' => ['bold' => true]],
            $outputRow + 1 => ['font' => ['bold' => true]],
            $outputRow + 2 => ['font' => ['bold' => true]],
        ];
    }

    /**
     * Columns before the first amount column: SN..PAN, plus Branch when shown.
     */
    private function leadingColumnCount(): int
    {
        return $this->hasBranchColumn ? 9 : 8;
    }

    private function columnLetter(int $index): string
    {
        return Coordinate::stringFromColumnIndex($index);
    }

    /**
     * @param  array<string, mixed>  $source
     * @return list<float>
     */
    private function amountCells(array $source): array
    {
        return array_map(
            fn (string $key): float => Money::of($source[$key] ?? '0')->toFloat(),
            self::AMOUNT_KEYS,
        );
    }

    /**
     * @return list<string>
     */
    private function blankLine(): array
    {
        return array_fill(0, $this->leadingColumnCount() + count(self::AMOUNT_KEYS), '');
    }

    /**
     * @param  array<string, mixed>  $sideTotals
     * @return list<string|float>
     */
    private function summaryLine(string $label, array $sideTotals): array
    {
        return [
            $label,
            ...array_fill(0, $this->leadingColumnCount() - 1, ''),
            ...$this->amountCells($sideTotals),
        ];
    }
}
