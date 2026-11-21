<?php

namespace App\Exports;

use App\Exports\Concerns\WritesStatementHeader;
use App\Models\CompanySetting;
use App\Support\Money\Money;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Trial Balance export following the legacy hierarchy: head -> group ->
 * sub-group -> ledger, with a bold "<name> Total" row closing every sub-group
 * and group, a bold "Total <Head>" row closing every head, and a grand TOTAL.
 * Empty nodes (no accounts beneath them) are skipped at ledger level. At
 * `subgroup` / `group` level the heads arrive already pruned by
 * TrialBalanceStatement::build(), and each sub-group / group whose rows were
 * dropped prints as one bold line carrying its totals.
 *
 * The sheet opens with the shared statement header (company name, PAN/VAT,
 * title, BS and AD period), see WritesStatementHeader.
 *
 * Every Money value in $heads / $totals is already an exact 2-decimal string;
 * Money::toFloat() is used only here, at the Excel cell boundary (CONTRACTS C1).
 */
class TrialBalanceExport implements FromCollection, ShouldAutoSize, WithColumnFormatting, WithHeadings, WithMapping, WithStrictNullComparison, WithStyles
{
    use WritesStatementHeader;

    private const AMOUNT_KEYS = ['openingDebit', 'openingCredit', 'periodDebit', 'periodCredit', 'closingDebit', 'closingCredit'];

    /**
     * @param  array<int, array{name: string, totals: array<string, string>, groups: array<int, array{name: string, totals: array<string, string>, accounts: array, subgroups: array}>}>  $heads
     * @param  string|null  $branchLabel  BranchFilter::label() in a multi-branch tenant, else null (no extra heading row)
     * @param  array<string, mixed>  $totals  totalOpeningDebit, totalOpeningCredit, totalPeriodDebit, totalPeriodCredit, totalDebit, totalCredit (strings); inBalance, openingInBalance, periodInBalance (bools). Empty skips the grand total and balance lines.
     * @param  string|null  $from  Period start (AD, Y-m-d)
     * @param  string|null  $to  Period end (AD, Y-m-d)
     * @param  string|null  $accountLabel  "CODE - Name" when a single account is filtered, else null
     * @param  string  $level  Lowest row level in $heads: group, subgroup or ledger
     * @param  CompanySetting|null  $company  Company for the header rows; null looks up CompanySetting::current()
     */
    public function __construct(
        private readonly array $heads,
        private readonly ?string $branchLabel = null,
        private readonly array $totals = [],
        private readonly ?string $from = null,
        private readonly ?string $to = null,
        private readonly ?string $accountLabel = null,
        private readonly bool $hideZero = false,
        private readonly ?string $fiscalYearName = null,
        private readonly string $level = 'ledger',
        private readonly ?CompanySetting $company = null,
    ) {}

    public function collection(): Collection
    {
        $rows = collect();

        $heading = function (string $name, int $indent = 0) use ($rows): void {
            $rows->push(['name' => str_repeat(' ', $indent).$name, 'code' => '', 'values' => null, 'bold' => true]);
        };
        $line = function (string $name, ?array $values, bool $bold = false, int $indent = 0, string $code = '') use ($rows): void {
            $rows->push(['name' => str_repeat(' ', $indent).$name, 'code' => $code, 'values' => $values, 'bold' => $bold]);
        };
        $note = function (string $text, bool $bold = false) use ($rows): void {
            $rows->push(['name' => $text, 'code' => '', 'values' => null, 'bold' => $bold]);
        };

        $accountCount = fn (array $node): int => count($node['accounts'] ?? [])
            + array_sum(array_map(fn (array $sub): int => count($sub['accounts'] ?? []), $node['subgroups'] ?? []));

        $ledgerLevel = $this->level === 'ledger';

        foreach ($this->heads as $head) {
            $headAccounts = array_sum(array_map($accountCount, $head['groups'] ?? []));

            if ($ledgerLevel ? $headAccounts === 0 : empty($head['groups'])) {
                continue;
            }

            $heading($head['name']);

            foreach ($head['groups'] ?? [] as $group) {
                if ($ledgerLevel && $accountCount($group) === 0) {
                    continue;
                }

                if (empty($group['accounts']) && empty($group['subgroups'])) {
                    $line($group['name'], $group['totals'] ?? [], true, 2);

                    continue;
                }

                $heading($group['name'], 2);

                foreach ($group['accounts'] ?? [] as $account) {
                    $line($account['name'], $account, false, 6, (string) ($account['code'] ?? ''));
                }

                foreach ($group['subgroups'] ?? [] as $subgroup) {
                    if (empty($subgroup['accounts'])) {
                        if (! $ledgerLevel) {
                            $line($subgroup['name'], $subgroup['totals'] ?? [], true, 4);
                        }

                        continue;
                    }

                    $heading($subgroup['name'], 4);

                    foreach ($subgroup['accounts'] as $account) {
                        $line($account['name'], $account, false, 8, (string) ($account['code'] ?? ''));
                    }

                    $line($subgroup['name'].' Total', $subgroup['totals'] ?? [], true, 4);
                }

                $line($group['name'].' Total', $group['totals'] ?? [], true, 2);
            }

            $line('Total '.$head['name'], $head['totals'] ?? [], true);
        }

        if ($this->totals !== []) {
            $line('TOTAL', [
                'openingDebit' => $this->totals['totalOpeningDebit'] ?? '0.00',
                'openingCredit' => $this->totals['totalOpeningCredit'] ?? '0.00',
                'periodDebit' => $this->totals['totalPeriodDebit'] ?? '0.00',
                'periodCredit' => $this->totals['totalPeriodCredit'] ?? '0.00',
                'closingDebit' => $this->totals['totalDebit'] ?? '0.00',
                'closingCredit' => $this->totals['totalCredit'] ?? '0.00',
            ], true);

            if ($this->accountLabel === null || $this->accountLabel === '') {
                $note('');

                $out = array_values(array_filter([
                    empty($this->totals['openingInBalance']) ? 'opening' : null,
                    empty($this->totals['periodInBalance']) ? 'period' : null,
                    empty($this->totals['inBalance']) ? 'closing' : null,
                ]));

                $note(
                    $out === []
                        ? 'Balanced: opening, period and closing debits all equal their credits.'
                        : 'NOT balanced: '.implode('/', $out).' out',
                    true,
                );
            }
        }

        return $rows;
    }

    /**
     * @return list<list<string>>
     */
    public function headings(): array
    {
        // Company, PAN/VAT, title and the BS + AD period line.
        $rows = $this->statementHeaderRows('Trial Balance', $this->from, $this->to, $this->company);

        if ($this->fiscalYearName !== null && $this->fiscalYearName !== '') {
            $rows[] = ["Fiscal Year: {$this->fiscalYearName}"];
        }

        $filters = [
            'Account: '.(($this->accountLabel !== null && $this->accountLabel !== '') ? $this->accountLabel : 'All accounts'),
            'Level: '.match ($this->level) {
                'group' => 'Group',
                'subgroup' => 'Sub group',
                default => 'Ledger',
            },
        ];

        if ($this->hideZero) {
            $filters[] = 'Zero balances hidden';
        }

        $rows[] = [implode(' | ', $filters)];

        if ($this->branchLabel !== null) {
            $rows[] = [$this->branchLabel];
        }

        $rows[] = [''];
        $rows[] = ['Particulars', 'Code', 'Opening', '', 'Period', '', 'Closing', ''];
        $rows[] = ['', '', 'Dr', 'Cr', 'Dr', 'Cr', 'Dr', 'Cr'];

        return $rows;
    }

    /**
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        return array_fill_keys(['C', 'D', 'E', 'F', 'G', 'H'], NumberFormat::FORMAT_NUMBER_00);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<int, string|float>
     */
    public function map($row): array
    {
        $cells = [$row['name'], $row['code']];

        foreach (self::AMOUNT_KEYS as $key) {
            $cells[] = $row['values'] === null
                ? ''
                : Money::of($row['values'][$key] ?? '0.00')->toFloat();
        }

        return $cells;
    }

    /**
     * @return array<int|string, array<string, mixed>>
     */
    public function styles(Worksheet $sheet): array
    {
        $headingRowCount = count($this->headings());
        $groupHeaderRow = $headingRowCount - 1;

        foreach (['C' => 'D', 'E' => 'F', 'G' => 'H'] as $start => $end) {
            $sheet->mergeCells("{$start}{$groupHeaderRow}:{$end}{$groupHeaderRow}");
        }

        $sheet->getStyle("C{$groupHeaderRow}:H{$groupHeaderRow}")
            ->getAlignment()
            ->setHorizontal('center');

        // Union, not spread: spread would renumber the integer row keys.
        $styles = $this->statementHeaderStyles() + [
            $groupHeaderRow => ['font' => ['bold' => true]],
            $headingRowCount => ['font' => ['bold' => true]],
        ];

        foreach ($this->collection()->values() as $index => $row) {
            if ($row['bold']) {
                $styles[$headingRowCount + $index + 1] = ['font' => ['bold' => true]];
            }
        }

        return $styles;
    }
}
