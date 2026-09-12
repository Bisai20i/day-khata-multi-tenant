<?php

namespace App\Exports;

use App\Support\Money\Money;
use App\Support\NepaliCalendar;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

/**
 * Mirrors SalesPurchaseReportController::salesVatBook()'s own Inertia
 * `rows`/`totals` payload exactly - the same filtered rows (the caller has
 * already applied from/to/store_id), including the capital sales and the
 * negative cancellation rows, plus a trailing Total row.
 *
 * Every monetary cell is written as a real number with a 2-decimal display
 * format, never a comma-grouped string, so Excel can sum/sort/filter the
 * column. `Money::toFloat()` is used at exactly that boundary and nowhere
 * else (C1): the values it converts are the already-final stored amounts,
 * so no arithmetic ever happens on the float.
 */
class SalesVatBookExport implements FromCollection, WithColumnFormatting, WithHeadings, WithMapping
{
    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<string, string|int>  $totals
     */
    public function __construct(
        private readonly Collection $rows,
        private readonly array $totals,
    ) {}

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function collection(): Collection
    {
        return $this->rows->values()->push([
            'sn' => null,
            'date' => null,
            'invoice_number' => null,
            'buyer_name' => 'Total',
            'buyer_pan' => null,
            'invoice_type' => null,
            'entry' => null,
            'taxable_amount' => $this->totals['taxable_amount'],
            'nontaxable_amount' => $this->totals['nontaxable_amount'],
            'vat_amount' => $this->totals['vat_amount'],
            'capital_amount' => $this->totals['capital_amount'],
            'total' => $this->totals['total'],
        ]);
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return ['SN', 'Date (BS)', 'Date (AD)', 'Invoice #', 'Buyer', 'Buyer PAN', 'Type', 'Entry', 'Taxable', 'Exempt', 'VAT', 'Capital', 'Total'];
    }

    /**
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        return [
            'I' => NumberFormat::FORMAT_NUMBER_00,
            'J' => NumberFormat::FORMAT_NUMBER_00,
            'K' => NumberFormat::FORMAT_NUMBER_00,
            'L' => NumberFormat::FORMAT_NUMBER_00,
            'M' => NumberFormat::FORMAT_NUMBER_00,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<int, string|int|float|null>
     */
    public function map($row): array
    {
        return [
            $row['sn'],
            $row['date'] === null ? '' : NepaliCalendar::formatBs($row['date']),
            $row['date'] ?? '',
            $row['invoice_number'] ?? '',
            $row['buyer_name'] ?? '',
            $row['buyer_pan'] ?? '',
            $row['invoice_type'] ?? '',
            $row['entry'] ?? '',
            Money::of($row['taxable_amount'])->toFloat(),
            Money::of($row['nontaxable_amount'])->toFloat(),
            Money::of($row['vat_amount'])->toFloat(),
            Money::of($row['capital_amount'])->toFloat(),
            Money::of($row['total'])->toFloat(),
        ];
    }
}
