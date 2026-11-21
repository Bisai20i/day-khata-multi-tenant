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
 * Mirrors DamageLostStockReportController::index() for the same query string.
 *
 * `view` 'list': one line per written-off stock adjustment line (the
 * existing columns A-I are unchanged, the Value column is appended as J),
 * then the total written off per base unit. `view` 'item': the item-wise rows
 * (legacy itemwisedamagestock), one per item, then the same per-unit totals.
 * There is no quantity total across units; the value total is money, so it is
 * summed once across everything.
 *
 * Quantities are base-unit quantities (the controller already applied the
 * unit conversion factor). They and the values are real numbers with a
 * display format. `Quantity::toFloat()` / `Money::toFloat()` are used at
 * exactly that boundary and nowhere else (C1): the values they convert are
 * already-final exact strings.
 */
class DamageLostStockExport implements FromCollection, ShouldAutoSize, WithColumnFormatting, WithHeadings, WithStrictNullComparison, WithStyles
{
    private const QUANTITY_FORMAT = '#,##0.####';

    private const MONEY_FORMAT = '#,##0.00';

    private const LIST_COLUMN_COUNT = 10;

    private const ITEM_COLUMN_COUNT = 5;

    private const REASON_LABELS = ['damage' => 'Damage', 'lost' => 'Lost'];

    /**
     * @param  list<array<string, mixed>>  $lines  The controller's presented lines
     * @param  list<array{unit: string, quantity: string, value: string, lines: int}>  $totals  Per-unit totals
     * @param  string  $from  Period start (AD, Y-m-d)
     * @param  string  $to  Period end (AD, Y-m-d)
     * @param  string|null  $reason  'damage', 'lost' or null for both
     * @param  string|null  $branchLabel  BranchFilter::label() in a multi-branch tenant, else null (no extra preamble row)
     * @param  list<array<string, mixed>>  $itemWise  The controller's item-wise rows
     * @param  string  $view  'list' or 'item'
     * @param  string  $totalValue  The value written off across every line
     */
    public function __construct(
        private readonly array $lines,
        private readonly array $totals,
        private readonly string $from,
        private readonly string $to,
        private readonly ?string $reason = null,
        private readonly ?string $branchLabel = null,
        private readonly array $itemWise = [],
        private readonly string $view = 'list',
        private readonly string $totalValue = '0.00',
    ) {}

    /**
     * @return Collection<int, list<string|int|float>>
     */
    public function collection(): Collection
    {
        return $this->isItemView() ? $this->itemRows() : $this->listRows();
    }

    /**
     * @return list<list<string>>
     */
    public function headings(): array
    {
        $reasonLabel = match ($this->reason) {
            'damage' => 'Damage only',
            'lost' => 'Lost only',
            default => 'Damage & Lost',
        };

        $title = $this->isItemView() ? 'Damage & Lost Stock (Item-wise)' : 'Damage & Lost Stock';
        $rows = [[$title], ["Period: {$this->from} to {$this->to} (AD)"], ["Reason: {$reasonLabel}"]];

        if ($this->branchLabel !== null) {
            $rows[] = [$this->branchLabel];
        }

        $rows[] = [''];

        $rows[] = $this->isItemView()
            ? ['Item', 'Unit', 'Total Quantity', 'Total Value', 'Entries']
            : ['Date (AD)', 'Item', 'Unit', 'Store', 'Reason', 'Quantity', 'Remarks', 'Note', 'Adjustment #', 'Value'];

        return $rows;
    }

    /**
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        return $this->isItemView()
            ? ['C' => self::QUANTITY_FORMAT, 'D' => self::MONEY_FORMAT]
            : ['F' => self::QUANTITY_FORMAT, 'J' => self::MONEY_FORMAT];
    }

    /**
     * @return array<int|string, array<string, mixed>>
     */
    public function styles(Worksheet $sheet): array
    {
        $headingRowCount = count($this->headings());
        $preambleRowCount = $headingRowCount - 2;
        $lastColumn = $this->isItemView() ? 'E' : 'J';

        // Long text lives in merged cells so ShouldAutoSize does not widen column A.
        foreach (range(1, $preambleRowCount) as $row) {
            $sheet->mergeCells("A{$row}:{$lastColumn}{$row}");
        }

        $styles = [
            1 => ['font' => ['bold' => true, 'size' => 13]],
            $headingRowCount => ['font' => ['bold' => true]],
        ];

        $bodyCount = $this->isItemView() ? count($this->itemWise) : count($this->lines);
        $firstTotalRow = $headingRowCount + $bodyCount + 2;
        $labelEnd = $this->isItemView() ? 'B' : 'E';

        // One row per unit, then the grand value row.
        for ($offset = 0; $offset <= count($this->totals); $offset++) {
            $row = $firstTotalRow + $offset;
            $sheet->mergeCells("A{$row}:{$labelEnd}{$row}");
            $styles[$row] = ['font' => ['bold' => true]];
        }

        return $styles;
    }

    private function isItemView(): bool
    {
        return $this->view === 'item';
    }

    /**
     * @return Collection<int, list<string|int|float>>
     */
    private function listRows(): Collection
    {
        $rows = collect();

        foreach ($this->lines as $line) {
            $rows->push([
                $line['date'] ?? '',
                $line['itemName'] ?? '',
                $line['unit'] ?? '',
                $line['storeName'] ?? '',
                self::REASON_LABELS[$line['reason'] ?? ''] ?? ($line['reason'] ?? ''),
                Quantity::of($line['quantity'] ?? '0')->toFloat(),
                $line['remarks'] ?? '',
                $line['note'] ?? '',
                $line['stockAdjustmentId'] ?? '',
                Money::of($line['value'] ?? '0')->toFloat(),
            ]);
        }

        $rows->push(array_fill(0, self::LIST_COLUMN_COUNT, ''));

        foreach ($this->totals as $total) {
            $unit = $total['unit'] !== '' ? " ({$total['unit']})" : '';
            $count = $total['lines'] === 1 ? '1 line' : "{$total['lines']} lines";

            $rows->push([
                "Total written off{$unit} - {$count}", '', '', '', '',
                Quantity::of($total['quantity'])->toFloat(), '', '', '',
                Money::of($total['value'] ?? '0')->toFloat(),
            ]);
        }

        $rows->push(['Total value written off', '', '', '', '', '', '', '', '', Money::of($this->totalValue)->toFloat()]);

        return $rows;
    }

    /**
     * @return Collection<int, list<string|int|float>>
     */
    private function itemRows(): Collection
    {
        $rows = collect();

        foreach ($this->itemWise as $row) {
            $rows->push([
                $row['name'] ?? '',
                $row['unit'] ?? '',
                Quantity::of($row['total_quantity'] ?? '0')->toFloat(),
                Money::of($row['total_value'] ?? '0')->toFloat(),
                $row['transaction_count'] ?? 0,
            ]);
        }

        $rows->push(array_fill(0, self::ITEM_COLUMN_COUNT, ''));

        foreach ($this->totals as $total) {
            $unit = $total['unit'] !== '' ? " ({$total['unit']})" : '';

            $rows->push([
                "Total written off{$unit}", '',
                Quantity::of($total['quantity'])->toFloat(),
                Money::of($total['value'] ?? '0')->toFloat(),
                '',
            ]);
        }

        $rows->push(['Total value written off', '', '', Money::of($this->totalValue)->toFloat(), '']);

        return $rows;
    }
}
