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
 * Flattens AccountingReportController::buildHierarchy()'s Balance Sheet tree
 * into one row per account, plus a totals row (T14 task 2). Money::toFloat()
 * only at this Excel cell boundary (CONTRACTS C1).
 */
class BalanceSheetExport implements FromCollection, WithColumnFormatting, WithHeadings, WithMapping
{
    /**
     * @param  array<int, array{name: string, groups: array<int, array{name: string, accounts: array, subgroups: array}>}>  $heads
     */
    public function __construct(
        private readonly array $heads,
        private readonly string $totalAssets,
        private readonly string $totalLiabilitiesAndCapital,
    ) {}

    public function collection(): Collection
    {
        $rows = collect();

        foreach ($this->heads as $head) {
            foreach ($head['groups'] as $group) {
                foreach ($group['accounts'] as $account) {
                    $rows->push($this->row($head['name'], $group['name'], null, $account));
                }

                foreach ($group['subgroups'] as $subgroup) {
                    foreach ($subgroup['accounts'] as $account) {
                        $rows->push($this->row($head['name'], $group['name'], $subgroup['name'], $account));
                    }
                }
            }
        }

        $rows->push(['head' => '', 'group' => '', 'subgroup' => '', 'code' => '', 'name' => 'Total Assets', 'debit' => $this->totalAssets, 'credit' => '0.00']);
        $rows->push(['head' => '', 'group' => '', 'subgroup' => '', 'code' => '', 'name' => 'Total Liabilities and Capital', 'debit' => '0.00', 'credit' => $this->totalLiabilitiesAndCapital]);

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $account
     * @return array<string, mixed>
     */
    private function row(string $head, string $group, ?string $subgroup, array $account): array
    {
        return [
            'head' => $head,
            'group' => $group,
            'subgroup' => $subgroup ?? '',
            'code' => $account['code'] ?? '',
            'name' => $account['name'],
            'debit' => $account['debit'] ?? '0.00',
            'credit' => $account['credit'] ?? '0.00',
        ];
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return ['Head', 'Group', 'Subgroup', 'Code', 'Account', 'Debit', 'Credit'];
    }

    /**
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        return ['F' => NumberFormat::FORMAT_NUMBER_00, 'G' => NumberFormat::FORMAT_NUMBER_00];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<int, string|float>
     */
    public function map($row): array
    {
        return [
            $row['head'],
            $row['group'],
            $row['subgroup'],
            $row['code'],
            $row['name'],
            Money::of($row['debit'])->toFloat(),
            Money::of($row['credit'])->toFloat(),
        ];
    }
}
