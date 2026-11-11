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
 * The credit-note book as a real .xlsx, built from exactly the rows
 * SalesPurchaseReportController::salesReturnRegister() renders, so the
 * sheet and the screen can never disagree. Posted notes only (C6), each
 * carrying its stored `credit_note_number` and the invoice number of the
 * sale it credits.
 *
 * Money cells are real numbers with a 2-decimal format - `Money::toFloat()`
 * at the cell boundary and nowhere else (C1).
 */
class SalesReturnRegisterExport implements FromCollection, WithColumnFormatting, WithHeadings, WithMapping
{
    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<string, string|int>  $totals
     * @param  bool  $withBranch  Multi-branch tenant (BR14): a trailing Branch column from each row's `branch`.
     * @param  string|null  $branchLabel  BranchFilter::label() in a multi-branch tenant, else null (no extra heading row)
     */
    public function __construct(
        private readonly Collection $rows,
        private readonly array $totals,
        private readonly bool $withBranch = false,
        private readonly ?string $branchLabel = null,
    ) {}

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function collection(): Collection
    {
        return $this->rows->values()->push([
            'sn' => null,
            'date' => null,
            'credit_note_number' => null,
            'invoice_number' => null,
            'buyer_name' => 'Total',
            'buyer_pan' => null,
            'entry' => null,
            'subtotal' => $this->totals['subtotal'] ?? '0.00',
            'nontaxable_amount' => $this->totals['nontaxable_amount'],
            'taxable_amount' => $this->totals['taxable_amount'],
            'vat_amount' => $this->totals['vat_amount'],
            'round_off' => $this->totals['round_off'] ?? '0.00',
            'total' => $this->totals['total'],
        ]);
    }

    /**
     * @return array<int, string>|list<list<string>>
     */
    public function headings(): array
    {
        $headings = ['SN', 'Date (BS)', 'Date (AD)', 'Credit Note #', 'Against Invoice #', 'Buyer', 'Buyer PAN', 'Entry', 'Sub Total', 'Non-Taxable', 'Taxable', 'VAT', 'Round Off', 'Total'];

        $headings = $this->withBranch ? [...$headings, 'Branch'] : $headings;

        return $this->branchLabel === null ? $headings : [[$this->branchLabel], $headings];
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
            'N' => NumberFormat::FORMAT_NUMBER_00,
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
            $row['credit_note_number'] ?? '',
            $row['invoice_number'] ?? '',
            $row['buyer_name'] ?? '',
            $row['buyer_pan'] ?? '',
            $row['entry'] ?? '',
            Money::of($row['subtotal'] ?? '0')->toFloat(),
            Money::of($row['nontaxable_amount'])->toFloat(),
            Money::of($row['taxable_amount'])->toFloat(),
            Money::of($row['vat_amount'])->toFloat(),
            Money::of($row['round_off'] ?? '0')->toFloat(),
            Money::of($row['total'])->toFloat(),
            ...($this->withBranch ? [$row['branch'] ?? ''] : []),
        ];
    }
}
