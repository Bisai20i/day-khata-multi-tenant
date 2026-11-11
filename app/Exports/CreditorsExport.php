<?php

namespace App\Exports;

use App\Support\Money\Money;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

/**
 * The ledger-based Creditors list as a real .xlsx: every supplier carrying a
 * non-zero balance in the selected fiscal year, exactly as
 * SalesPurchaseReportController::creditors() renders it. This is the party
 * breakdown of the Sundry Creditors subgroup on the Balance Sheet, so it
 * includes migrated opening payables that no open invoice explains - the gap
 * the 2026-09-11 audit found ("no ledger-based Debtors/Creditors list").
 *
 * The balance cell is a real number with a 2-decimal format;
 * `Money::toFloat()` is used at that boundary and nowhere else (C1).
 *
 * In a multi-branch tenant the sheet opens with the branch filter's label
 * (plan 12, BR14) above the headings and the balances are that filter's;
 * with no label the layout is exactly what it always was.
 */
class CreditorsExport implements FromCollection, WithColumnFormatting, WithHeadings, WithMapping
{
    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  list<array{area: string, rows: list<array<string, mixed>>, totals: array<string, string>}>|null  $groups  the report's "Group by area" layout, when it was on
     * @param  string|null  $branchLabel  BranchFilter::label() in a multi-branch tenant, else null
     */
    public function __construct(
        private readonly Collection $rows,
        private readonly string $total,
        private readonly ?array $groups = null,
        private readonly ?string $branchLabel = null,
    ) {}

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function collection(): Collection
    {
        return PartyBalanceRows::build($this->rows, $this->total, $this->groups);
    }

    /**
     * @return array<int, string>|list<list<string>>
     */
    public function headings(): array
    {
        $headings = ['Supplier', 'Address', 'Mobile', 'Area', 'Balance'];

        return $this->branchLabel === null ? $headings : [[$this->branchLabel], $headings];
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
        return [
            $row['name'] ?? '',
            $row['address'] ?? '',
            $row['mobile_no'] ?? '',
            $row['area'] ?? '',
            // Heading rows of the grouped layout carry no balance.
            ($row['balance'] ?? null) === null ? null : Money::of($row['balance'])->toFloat(),
        ];
    }
}
