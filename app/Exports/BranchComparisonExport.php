<?php

namespace App\Exports;

use App\Http\Controllers\Tenant\Reports\BranchComparisonReportController;
use App\Support\Money\Money;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

/**
 * The branch comparison report (plan 12, BR16) as a real .xlsx, exactly as
 * BranchComparisonReportController renders it: one row per branch, then the
 * total row when the user may see every branch.
 *
 * The sheet opens with the branch filter's label ("Branches: ..."), the
 * period and the management-report note above the headings. Money cells are
 * real numbers with a 2-decimal format; `Money::toFloat()` is used at that
 * boundary and nowhere else (C1). The margin is the controller's exact
 * one-decimal string, written as text.
 */
class BranchComparisonExport implements FromCollection, WithColumnFormatting, WithHeadings, WithMapping
{
    /**
     * @param  list<array<string, mixed>>  $rows  BranchComparisonReportController rows
     * @param  array<string, string|null>|null  $totals  null when the user may not see every branch
     * @param  string  $branchLabel  BranchFilter::label()
     * @param  string  $period  "FY name: from to to"
     * @param  string  $branchWord  What the company calls a branch ("Branch", "Outlet", ...)
     */
    public function __construct(
        private readonly array $rows,
        private readonly ?array $totals,
        private readonly string $branchLabel,
        private readonly string $period,
        private readonly string $branchWord,
    ) {}

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function collection(): Collection
    {
        $rows = collect($this->rows)->map(fn (array $row): array => [
            ...$row,
            'label' => $row['isActive'] ? $row['name'] : "{$row['name']} (inactive)",
        ]);

        if ($this->totals !== null) {
            $rows->push([...$this->totals, 'label' => 'Total', 'code' => '']);
        }

        return $rows->values();
    }

    /**
     * @return list<list<string>>
     */
    public function headings(): array
    {
        return [
            [$this->branchLabel],
            ['Period: '.$this->period],
            ['Management report: shared costs sit where they were posted.'],
            [
                $this->branchWord,
                'Code',
                'Sales',
                'Purchases',
                'Net stock transfers',
                'Stock change',
                'Gross profit',
                'Other income',
                'Expenses',
                'Net profit',
                'Net margin %',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        return [
            'C' => NumberFormat::FORMAT_NUMBER_00,
            'D' => NumberFormat::FORMAT_NUMBER_00,
            'E' => NumberFormat::FORMAT_NUMBER_00,
            'F' => NumberFormat::FORMAT_NUMBER_00,
            'G' => NumberFormat::FORMAT_NUMBER_00,
            'H' => NumberFormat::FORMAT_NUMBER_00,
            'I' => NumberFormat::FORMAT_NUMBER_00,
            'J' => NumberFormat::FORMAT_NUMBER_00,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string|float|null>
     */
    public function map($row): array
    {
        return [
            $row['label'],
            $row['code'] ?? '',
            ...array_map(
                fn (string $key): float => Money::of($row[$key])->toFloat(),
                BranchComparisonReportController::FIGURES,
            ),
            $row['margin'] ?? '',
        ];
    }
}
