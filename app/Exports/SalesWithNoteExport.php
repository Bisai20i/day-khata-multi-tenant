<?php

namespace App\Exports;

use App\Support\Money\Money;
use App\Support\NepaliCalendar;
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
 * Mirrors SalesWithNoteReportController::index()'s own `sales`/`totals`
 * payload: one line per posted sale that carries a note, then a Total row.
 * The Branch column exists only for a multi-branch company, exactly like the
 * page. The Total amount is a real number with a 2-decimal display format;
 * `Money::toFloat()` is used at exactly that boundary and nowhere else (C1).
 */
class SalesWithNoteExport implements FromCollection, ShouldAutoSize, WithColumnFormatting, WithHeadings, WithStrictNullComparison, WithStyles
{
    /**
     * @param  list<array<string, mixed>>  $sales  The report's `sales`
     * @param  array{count: int, total: string}  $totals
     * @param  string  $from  Start date (AD, Y-m-d)
     * @param  string  $to  End date (AD, Y-m-d)
     * @param  bool  $showBranch  Multi-branch company: add the Branch column
     * @param  string|null  $branchLabel  BranchFilter::label(), else null (no extra preamble row)
     * @param  string|null  $storeName  The chosen store, else null
     * @param  string|null  $customerName  The chosen customer, else null
     * @param  string|null  $search  The note search text, else null
     */
    public function __construct(
        private readonly array $sales,
        private readonly array $totals,
        private readonly string $from,
        private readonly string $to,
        private readonly bool $showBranch = false,
        private readonly ?string $branchLabel = null,
        private readonly ?string $storeName = null,
        private readonly ?string $customerName = null,
        private readonly ?string $search = null,
    ) {}

    /**
     * @return Collection<int, list<string|float>>
     */
    public function collection(): Collection
    {
        $lines = $this->sales === []
            ? collect()
            : collect($this->sales)->map(function (array $sale): array {
                $line = [
                    NepaliCalendar::formatBs($sale['date']),
                    $sale['date'],
                    (string) ($sale['invoice_number'] ?? ''),
                ];

                if ($this->showBranch) {
                    $line[] = (string) ($sale['branch'] ?? '');
                }

                return [
                    ...$line,
                    (string) ($sale['customer'] ?? ''),
                    $this->itemsLabel($sale['items'] ?? []),
                    (string) ($sale['note'] ?? ''),
                    (string) ($sale['chalani_number'] ?? ''),
                    Money::of($sale['total'])->toFloat(),
                ];
            });

        $blank = array_fill(0, $this->columnCount(), '');
        $total = $blank;
        $total[0] = 'Total';
        $total[2] = (string) $this->totals['count'].' sales';
        $total[$this->columnCount() - 1] = Money::of($this->totals['total'])->toFloat();

        return $lines->push($blank)->push($total);
    }

    /**
     * @return list<list<string>>
     */
    public function headings(): array
    {
        $rows = [['Sales With Note'], ["Period {$this->from} to {$this->to} (AD)"]];

        foreach ([
            $this->branchLabel,
            $this->storeName !== null ? "Store: {$this->storeName}" : null,
            $this->customerName !== null ? "Customer: {$this->customerName}" : null,
            $this->search !== null ? "Note contains: {$this->search}" : null,
        ] as $preamble) {
            if ($preamble !== null) {
                $rows[] = [$preamble];
            }
        }

        $rows[] = [''];

        $columns = ['Date (BS)', 'Date (AD)', 'Bill No'];

        if ($this->showBranch) {
            $columns[] = 'Branch';
        }

        $rows[] = [...$columns, "Buyer's Name", 'Items', 'Note', 'Chalani No', 'Total amount'];

        return $rows;
    }

    /**
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        return [$this->lastColumn() => NumberFormat::FORMAT_NUMBER_00];
    }

    /**
     * @return array<int|string, array<string, mixed>>
     */
    public function styles(Worksheet $sheet): array
    {
        $headingRowCount = count($this->headings());
        $preambleRowCount = $headingRowCount - 2;
        $last = $this->lastColumn();

        // Long text lives in merged cells so ShouldAutoSize does not widen column A.
        foreach (range(1, $preambleRowCount) as $row) {
            $sheet->mergeCells("A{$row}:{$last}{$row}");
        }

        return [
            1 => ['font' => ['bold' => true, 'size' => 13]],
            $headingRowCount => ['font' => ['bold' => true]],
            $headingRowCount + count($this->sales) + 2 => ['font' => ['bold' => true]],
        ];
    }

    private function columnCount(): int
    {
        return $this->showBranch ? 9 : 8;
    }

    private function lastColumn(): string
    {
        return $this->showBranch ? 'I' : 'H';
    }

    /**
     * "Name (qty unit), Name (qty unit)", quantity text trimmed of trailing
     * zeros without ever passing through a float.
     *
     * @param  list<array{name: string|null, quantity: string, unit: string|null}>  $items
     */
    private function itemsLabel(array $items): string
    {
        if ($items === []) {
            return '-';
        }

        return implode(', ', array_map(function (array $item): string {
            $quantity = str_contains($item['quantity'], '.') ? rtrim(rtrim($item['quantity'], '0'), '.') : $item['quantity'];

            return ($item['name'] ?? '-').' ('.trim($quantity.' '.($item['unit'] ?? '')).')';
        }, $items));
    }
}
