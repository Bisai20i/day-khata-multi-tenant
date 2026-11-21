<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * The ledger-based Creditors list as a real .xlsx: every Sundry Creditors account carrying a
 * non-zero balance in the selected fiscal year, exactly as
 * SalesPurchaseReportController::creditors() renders it. This is the account
 * breakdown of the Sundry Creditors subgroup on the Balance Sheet, so it
 * includes migrated opening payables that no open invoice explains - the gap
 * the 2026-09-11 audit found ("no ledger-based Debtors/Creditors list").
 *
 * The report signs each balance in the supplier's normal side, Cr (positive = Cr, negative = Dr). The sheet
 * shows the absolute amount in the Balance column (a real number with a
 * 2-decimal format) and the side it actually sits on in the Dr/Cr column
 * (column F), so an overpaid party reads e.g. `500.00 | Dr`. Zero shows no
 * side. The Subtotal and Total rows get a side too.
 *
 * Heading rows above the column headings (PartyBalanceRows::headingRows()):
 * the title, the fiscal year, in a multi-branch tenant the branch filter's
 * label (plan 12, BR14; the balances are that filter's), the area filter when
 * one is applied, and a blank spacer. The columns A to F are unchanged.
 */
class CreditorsExport implements FromCollection, WithColumnFormatting, WithHeadings, WithMapping, WithStrictNullComparison, WithStyles
{
    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  list<array{area: string, rows: list<array<string, mixed>>, totals: array<string, string>}>|null  $groups  the report's "Group by area" layout, when it was on
     * @param  string|null  $branchLabel  BranchFilter::label() in a multi-branch tenant, else null
     * @param  string|null  $fiscalYearName  the fiscal year the balances belong to, null when there is none yet
     * @param  string|null  $areaLabel  the applied area filter's label, null for all areas
     */
    public function __construct(
        private readonly Collection $rows,
        private readonly string $total,
        private readonly ?array $groups = null,
        private readonly ?string $branchLabel = null,
        private readonly ?string $fiscalYearName = null,
        private readonly ?string $areaLabel = null,
    ) {}

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function collection(): Collection
    {
        return PartyBalanceRows::build($this->rows, $this->total, $this->groups);
    }

    /**
     * @return list<list<string>>
     */
    public function headings(): array
    {
        return PartyBalanceRows::headingRows('Creditors', 'Supplier', $this->fiscalYearName, $this->branchLabel, $this->areaLabel);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function styles(Worksheet $sheet): array
    {
        return PartyBalanceRows::headingStyles($this->headings());
    }

    /**
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        return ['E' => NumberFormat::FORMAT_NUMBER_00];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<int, string|float|null>
     */
    public function map($row): array
    {
        return PartyBalanceRows::cells($row, creditNormal: true);
    }
}
