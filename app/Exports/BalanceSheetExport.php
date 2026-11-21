<?php

namespace App\Exports;

use App\Exports\Concerns\WritesStatementHeader;
use App\Models\CompanySetting;
use App\Support\Money\Money;
use App\Support\NepaliCalendar;
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
 * Balance Sheet export following the legacy hierarchy: the shared statement
 * header (company, PAN/VAT, title, "as at" line), then Liabilities & Equity
 * (Capital, Liabilities, any other head, Unclassified) closing with "Total
 * Liabilities & Equity", then Assets closing with "Total Assets". Current year
 * profit / (loss) is the synthetic ledger BalanceSheetStatement puts inside
 * Capital and prints like any ledger. Every sub-group, group and head closes
 * with a bold "<name> Total" row. Amounts are the signed statement-side
 * `amount` / `total` strings BalanceSheetStatement::build() provides.
 * Money::toFloat() only at this Excel cell boundary (CONTRACTS C1).
 */
class BalanceSheetExport implements FromCollection, ShouldAutoSize, WithColumnFormatting, WithHeadings, WithMapping, WithStrictNullComparison, WithStyles
{
    use WritesStatementHeader;

    /**
     * @param  array<int, array{name: string, total: string, groups: array<int, array{name: string, total: string, accounts: array, subgroups: array}>}>  $heads
     * @param  string|null  $branchLabel  BranchFilter::label() in a multi-branch tenant, else null (no extra heading row)
     * @param  string|null  $currentYearEarnings  Kept for the constructor's argument order; no longer printed as its own line (the profit row is inside Capital in $heads)
     * @param  string|null  $balanceWarning  Set when the sheet does not balance; null means balanced
     * @param  string|null  $to  "As at" date (AD, Y-m-d)
     * @param  CompanySetting|null  $company  Company for the header rows; null looks up CompanySetting::current()
     */
    public function __construct(
        private readonly array $heads,
        private readonly string $totalAssets,
        private readonly string $totalLiabilitiesAndCapital,
        private readonly ?string $branchLabel = null,
        private readonly ?string $currentYearEarnings = null,
        private readonly ?string $balanceWarning = null,
        private readonly ?string $fiscalYearName = null,
        private readonly ?string $to = null,
        private readonly ?CompanySetting $company = null,
    ) {}

    public function collection(): Collection
    {
        $rows = collect();

        $heading = function (string $name, int $indent = 0) use ($rows): void {
            $rows->push(['name' => str_repeat(' ', $indent).$name, 'code' => '', 'amount' => null, 'bold' => true]);
        };
        $line = function (string $name, string $amount, bool $bold = false, int $indent = 0, string $code = '') use ($rows): void {
            $rows->push(['name' => str_repeat(' ', $indent).$name, 'code' => $code, 'amount' => $amount, 'bold' => $bold]);
        };
        $note = function (string $text, bool $bold = false) use ($rows): void {
            $rows->push(['name' => $text, 'code' => '', 'amount' => null, 'bold' => $bold]);
        };

        $headBlock = function (array $head, bool $showHeadRow) use ($heading, $line): void {
            if ($showHeadRow) {
                $heading($head['name']);
            }

            foreach ($head['groups'] as $group) {
                $heading($group['name'], 2);

                foreach ($group['accounts'] as $account) {
                    $line($account['name'], $account['amount'], false, 6, (string) ($account['code'] ?? ''));
                }

                foreach ($group['subgroups'] as $subgroup) {
                    $heading($subgroup['name'], 4);

                    foreach ($subgroup['accounts'] as $account) {
                        $line($account['name'], $account['amount'], false, 8, (string) ($account['code'] ?? ''));
                    }

                    $line($subgroup['name'].' Total', $subgroup['total'], true, 4);
                }

                $line($group['name'].' Total', $group['total'], true, 2);
            }
        };

        $assetHeads = array_values(array_filter($this->heads, fn (array $head): bool => $head['name'] === 'Assets'));
        $otherHeads = array_values(array_filter($this->heads, fn (array $head): bool => $head['name'] !== 'Assets'));

        $heading('Liabilities & Equity');

        foreach ($otherHeads as $head) {
            $headBlock($head, true);
            $line($head['name'].' Total', $head['total'], true);
        }

        $line('Total Liabilities & Equity', $this->totalLiabilitiesAndCapital, true);
        $note('');

        $heading('Assets');

        foreach ($assetHeads as $head) {
            $headBlock($head, false);
        }

        $line('Total Assets', $this->totalAssets, true);
        $note('');

        if ($this->balanceWarning === null || $this->balanceWarning === '') {
            $note('Balance Sheet is balanced: Total Assets = Total Liabilities & Equity', true);
        } else {
            $difference = Money::of($this->totalAssets)->minus(Money::of($this->totalLiabilitiesAndCapital))->abs()->toString();
            $note('Balance Sheet is NOT balanced. '.$this->balanceWarning, true);
            $line('Difference', $difference, true);
        }

        return $rows;
    }

    /**
     * @return list<list<string>>
     */
    public function headings(): array
    {
        // Company, PAN/VAT, title, then an "as at" line in place of the
        // shared period line: a balance sheet is a position, not a period.
        $rows = $this->statementHeaderRows('Balance Sheet', null, $this->to, $this->company);
        $rows[self::STATEMENT_HEADER_ROW_COUNT - 1] = [$this->asAtLine()];

        if ($this->fiscalYearName !== null && $this->fiscalYearName !== '') {
            $rows[] = ["Fiscal Year: {$this->fiscalYearName}"];
        }

        if ($this->branchLabel !== null) {
            $rows[] = [$this->branchLabel];
        }

        $rows[] = [''];
        $rows[] = ['Particulars', 'Code', 'Amount'];

        return $rows;
    }

    /**
     * "As at: <BS> (BS) | <AD> (AD)", or empty without a date.
     */
    private function asAtLine(): string
    {
        if ($this->to === null || $this->to === '') {
            return '';
        }

        $bs = NepaliCalendar::formatBs($this->to);

        return 'As at: '.($bs !== '' ? "{$bs} (BS) | " : '')."{$this->to} (AD)";
    }

    /**
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        return ['C' => NumberFormat::FORMAT_NUMBER_00];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<int, string|float>
     */
    public function map($row): array
    {
        return [
            $row['name'],
            $row['code'],
            $row['amount'] === null ? '' : Money::of($row['amount'])->toFloat(),
        ];
    }

    /**
     * @return array<int|string, array<string, mixed>>
     */
    public function styles(Worksheet $sheet): array
    {
        $headingRowCount = count($this->headings());

        // Data rows start after every heading row, the shared header included.
        $styles = $this->statementHeaderStyles();
        $styles[$headingRowCount] = ['font' => ['bold' => true]];

        foreach ($this->collection()->values() as $index => $row) {
            if ($row['bold']) {
                $styles[$headingRowCount + $index + 1] = ['font' => ['bold' => true]];
            }
        }

        return $styles;
    }
}
