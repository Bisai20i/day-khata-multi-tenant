<?php

namespace App\Exports;

use App\Support\Money\Money;
use App\Support\Money\Quantity;
use App\Support\NepaliCalendar;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

/**
 * One item's stock ledger, laid out like AccountBookExport: a synthetic
 * "Opening Balance" row, one row per stock movement, a "Total" row of the
 * window's in/out/net, then a "Closing Balance" row, so the export reads
 * exactly like the on-screen table.
 *
 * Above the column headings sit context rows (item, period, store, branch)
 * when the caller supplies them, so a saved file still says what it covers.
 *
 * Quantities and rates are written as real numbers (toFloat() only at this
 * Excel cell boundary, CONTRACTS C1) with a 4-decimal display format, so the
 * In, Out, Quantity and Balance columns stay summable in Excel. The Value
 * column (the running balance valued at that date's cost) is a 2-decimal
 * amount; it is blank where no value can be given.
 */
class ItemLedgerExport implements FromCollection, WithColumnFormatting, WithHeadings, WithMapping, WithStrictNullComparison
{
    private const QUANTITY_FORMAT = '#,##0.####';

    /**
     * @param  array<int, array{date: string, type: string, storeName: ?string, quantity: string, qtyIn?: string, qtyOut?: string, unitCostRate: ?string, balance: string, value?: ?string, reference: string}>  $entries
     * @param  string|null  $branchLabel  BranchFilter::label() in a multi-branch tenant, else null (no extra heading row)
     * @param  array{in: string, out: string, net: string}|null  $totals  null skips the Total row
     * @param  string|null  $openingValue  Money string: the opening balance valued (null leaves the Value cell blank)
     * @param  string|null  $closingValue  Money string: the closing balance valued (null leaves the Value cell blank)
     */
    public function __construct(
        private readonly array $entries,
        private readonly string $openingBalance,
        private readonly string $closingBalance,
        private readonly ?string $branchLabel = null,
        private readonly ?array $totals = null,
        private readonly ?string $itemName = null,
        private readonly ?string $from = null,
        private readonly ?string $to = null,
        private readonly ?string $storeName = null,
        private readonly ?string $openingValue = null,
        private readonly ?string $closingValue = null,
    ) {}

    public function collection(): Collection
    {
        $rows = collect();

        $rows->push($this->summaryRow('Opening Balance', balance: $this->openingBalance, value: $this->openingValue ?? ''));

        foreach ($this->entries as $entry) {
            $quantity = Quantity::of($entry['quantity']);

            $rows->push([
                'date' => $entry['date'],
                'dateBs' => NepaliCalendar::formatBs($entry['date']),
                'type' => $entry['type'],
                'store' => $entry['storeName'] ?? '',
                'reference' => $entry['reference'],
                'in' => $entry['qtyIn'] ?? ($quantity->isNegative() ? Quantity::zero() : $quantity)->toString(),
                'out' => $entry['qtyOut'] ?? ($quantity->isNegative() ? $quantity->negated() : Quantity::zero())->toString(),
                'quantity' => $entry['quantity'],
                'rate' => $entry['unitCostRate'] ?? '',
                'balance' => $entry['balance'],
                'value' => $entry['value'] ?? '',
            ]);
        }

        if ($this->totals !== null) {
            $rows->push($this->summaryRow('Total', $this->totals['in'], $this->totals['out'], $this->totals['net']));
        }

        $rows->push($this->summaryRow('Closing Balance', balance: $this->closingBalance, value: $this->closingValue ?? ''));

        return $rows;
    }

    /**
     * @return array<int, string>|list<list<string>>
     */
    public function headings(): array
    {
        $headings = ['Date', 'Date (BS)', 'Type', 'Store', 'Reference', 'In', 'Out', 'Quantity', 'Unit Cost', 'Balance', 'Value'];

        $context = array_values(array_filter([
            $this->itemName !== null ? ['Item: '.$this->itemName] : null,
            $this->from !== null && $this->to !== null
                ? ["Period: {$this->from} to {$this->to} (".NepaliCalendar::formatBs($this->from).' to '.NepaliCalendar::formatBs($this->to).' BS)']
                : null,
            $this->storeName !== null ? ['Store: '.$this->storeName] : null,
            $this->branchLabel !== null ? [$this->branchLabel] : null,
        ]));

        return $context === [] ? $headings : [...$context, $headings];
    }

    /**
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        return [
            'F' => self::QUANTITY_FORMAT,
            'G' => self::QUANTITY_FORMAT,
            'H' => self::QUANTITY_FORMAT,
            'I' => self::QUANTITY_FORMAT,
            'J' => self::QUANTITY_FORMAT,
            'K' => NumberFormat::FORMAT_NUMBER_00,
        ];
    }

    /**
     * @param  array{date: string, dateBs: string, type: string, store: string, reference: string, in: string, out: string, quantity: string, rate: string, balance: string, value: string}  $row
     * @return array<int, string|float>
     */
    public function map($row): array
    {
        return [
            $row['date'],
            $row['dateBs'],
            $row['type'],
            $row['store'],
            $row['reference'],
            $this->quantityCell($row['in']),
            $this->quantityCell($row['out']),
            $this->quantityCell($row['quantity']),
            $this->quantityCell($row['rate']),
            $this->quantityCell($row['balance']),
            $row['value'] === '' ? '' : Money::of($row['value'])->toFloat(),
        ];
    }

    /**
     * @return array{date: string, dateBs: string, type: string, store: string, reference: string, in: string, out: string, quantity: string, rate: string, balance: string, value: string}
     */
    private function summaryRow(string $label, string $in = '', string $out = '', string $quantity = '', string $balance = '', string $value = ''): array
    {
        return ['date' => '', 'dateBs' => '', 'type' => $label, 'store' => '', 'reference' => '', 'in' => $in, 'out' => $out, 'quantity' => $quantity, 'rate' => '', 'balance' => $balance, 'value' => $value];
    }

    private function quantityCell(string $value): string|float
    {
        return $value === '' ? '' : Quantity::of($value)->toFloat();
    }
}
