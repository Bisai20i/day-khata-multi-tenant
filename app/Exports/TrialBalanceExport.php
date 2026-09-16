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
 * Flattens AccountingReportController::buildHierarchy()'s head -> group ->
 * (subgroup, optional) -> accounts tree into one row per account (T14 task
 * 2), mirroring the on-screen Trial Balance exactly.
 *
 * Every Money value in $heads is already an exact 2-decimal string
 * (buildHierarchy() renders it with Money::toString() before it leaves the
 * controller); Money::toFloat() is used only here, at the Excel cell
 * boundary (CONTRACTS C1).
 */
class TrialBalanceExport implements FromCollection, WithColumnFormatting, WithHeadings, WithMapping
{
    /**
     * @param  array<int, array{name: string, groups: array<int, array{name: string, accounts: array, subgroups: array}>}>  $heads
     */
    public function __construct(private readonly array $heads) {}

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
            'openingDebit' => $account['openingDebit'] ?? '0.00',
            'openingCredit' => $account['openingCredit'] ?? '0.00',
            'periodDebit' => $account['periodDebit'] ?? '0.00',
            'periodCredit' => $account['periodCredit'] ?? '0.00',
            'closingDebit' => $account['closingDebit'] ?? $account['debit'] ?? '0.00',
            'closingCredit' => $account['closingCredit'] ?? $account['credit'] ?? '0.00',
        ];
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return ['Head', 'Group', 'Subgroup', 'Code', 'Account', 'Opening Dr', 'Opening Cr', 'Period Dr', 'Period Cr', 'Closing Dr', 'Closing Cr'];
    }

    /**
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        return array_fill_keys(['F', 'G', 'H', 'I', 'J', 'K'], NumberFormat::FORMAT_NUMBER_00);
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
            Money::of($row['openingDebit'])->toFloat(),
            Money::of($row['openingCredit'])->toFloat(),
            Money::of($row['periodDebit'])->toFloat(),
            Money::of($row['periodCredit'])->toFloat(),
            Money::of($row['closingDebit'])->toFloat(),
            Money::of($row['closingCredit'])->toFloat(),
        ];
    }
}
