<?php

namespace App\Exports;

use App\Support\Money\Quantity;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * One item's stock ledger, laid out like AccountBookExport: a synthetic
 * "Opening Balance" row, one row per stock movement, then a "Closing
 * Balance" row, so the export reads exactly like the on-screen table.
 *
 * Quantities and rates are written as real numbers (toFloat() only at this
 * Excel cell boundary, CONTRACTS C1) with a 4-decimal display format, so the
 * Quantity and Balance columns stay summable in Excel.
 */
class ItemLedgerExport implements FromCollection, WithColumnFormatting, WithHeadings, WithMapping
{
    private const QUANTITY_FORMAT = '#,##0.####';

    /**
     * @param  array<int, array{date: string, type: string, storeName: ?string, quantity: string, unitCostRate: ?string, balance: string, reference: string}>  $entries
     */
    public function __construct(
        private readonly array $entries,
        private readonly string $openingBalance,
        private readonly string $closingBalance,
    ) {}

    public function collection(): Collection
    {
        $rows = collect();

        $rows->push(['date' => '', 'type' => 'Opening Balance', 'store' => '', 'reference' => '', 'quantity' => '', 'rate' => '', 'balance' => $this->openingBalance]);

        foreach ($this->entries as $entry) {
            $rows->push([
                'date' => $entry['date'],
                'type' => $entry['type'],
                'store' => $entry['storeName'] ?? '',
                'reference' => $entry['reference'],
                'quantity' => $entry['quantity'],
                'rate' => $entry['unitCostRate'] ?? '',
                'balance' => $entry['balance'],
            ]);
        }

        $rows->push(['date' => '', 'type' => 'Closing Balance', 'store' => '', 'reference' => '', 'quantity' => '', 'rate' => '', 'balance' => $this->closingBalance]);

        return $rows;
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return ['Date', 'Type', 'Store', 'Reference', 'Quantity', 'Unit Cost', 'Balance'];
    }

    /**
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        return ['E' => self::QUANTITY_FORMAT, 'F' => self::QUANTITY_FORMAT, 'G' => self::QUANTITY_FORMAT];
    }

    /**
     * @param  array{date: string, type: string, store: string, reference: string, quantity: string, rate: string, balance: string}  $row
     * @return array<int, string|float>
     */
    public function map($row): array
    {
        return [
            $row['date'],
            $row['type'],
            $row['store'],
            $row['reference'],
            $row['quantity'] === '' ? '' : Quantity::of($row['quantity'])->toFloat(),
            $row['rate'] === '' ? '' : Quantity::of($row['rate'])->toFloat(),
            Quantity::of($row['balance'])->toFloat(),
        ];
    }
}
