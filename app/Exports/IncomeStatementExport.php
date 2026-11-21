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
 * Income Statement export. With the structured `$statement` (the same one the
 * Inertia page and PDF render) the sheet follows the legacy single vertical
 * layout under the shared statement header (company, PAN/VAT, title, period):
 * Revenue from operations, Cost of goods sold / direct cost (opening stock,
 * purchases, returns, direct expenses, closing stock), Gross Profit / (Loss),
 * Other Business Income, Expenses, Net Profit / (Loss). Every section lists its
 * accounts nested as Group -> Sub-group -> Ledger, fully expanded, from the
 * statement's `*Groups` nodes; a loss is shown in parentheses as text. Without
 * the statement it falls back to the flat income / expenses / totals layout.
 * Money::toFloat() only at this Excel cell boundary (CONTRACTS C1).
 */
class IncomeStatementExport implements FromCollection, ShouldAutoSize, WithColumnFormatting, WithHeadings, WithMapping, WithStrictNullComparison, WithStyles
{
    use WritesStatementHeader;

    /**
     * @param  array<int, array{code: ?string, name: string, amount: string, computed: bool}>  $income
     * @param  array<int, array{code: ?string, name: string, amount: string, computed: bool}>  $expenses
     * @param  string|null  $branchLabel  BranchFilter::label() in a multi-branch tenant, else null (no extra heading row)
     * @param  array<string, mixed>|null  $statement  Structured statement (revenue, costOfSales, grossProfit, otherIncome, expenses, netProfit); null keeps the flat layout
     * @param  string|null  $projectLabel  "CODE - Name" of the project filter, else null (no extra heading row)
     * @param  CompanySetting|null  $company  Company for the header rows; null looks up CompanySetting::current()
     */
    public function __construct(
        private readonly array $income,
        private readonly array $expenses,
        private readonly string $grossProfit,
        private readonly string $netProfit,
        private readonly ?string $branchLabel = null,
        private readonly ?array $statement = null,
        private readonly ?string $fiscalYearName = null,
        private readonly ?string $from = null,
        private readonly ?string $to = null,
        private readonly ?string $projectLabel = null,
        private readonly ?CompanySetting $company = null,
    ) {}

    public function collection(): Collection
    {
        if ($this->statement !== null) {
            return $this->statementRows();
        }

        $rows = collect();

        foreach ($this->income as $row) {
            $rows->push(['section' => 'Income', 'code' => $row['code'] ?? '', 'name' => $row['name'], 'amount' => $row['amount']]);
        }

        foreach ($this->expenses as $row) {
            $rows->push(['section' => 'Expenses', 'code' => $row['code'] ?? '', 'name' => $row['name'], 'amount' => $row['amount']]);
        }

        $rows->push(['section' => '', 'code' => '', 'name' => 'Gross Profit', 'amount' => $this->grossProfit]);
        $rows->push(['section' => '', 'code' => '', 'name' => 'Net Profit', 'amount' => $this->netProfit]);

        return $rows;
    }

    /**
     * @return array<int, string>|list<list<string>>
     */
    public function headings(): array
    {
        if ($this->statement === null) {
            $headings = ['Section', 'Code', 'Account', 'Amount'];

            return $this->branchLabel === null ? $headings : [[$this->branchLabel], $headings];
        }

        $rows = $this->statementHeaderRows('Income Statement', $this->from, $this->to, $this->company);

        if ($this->fiscalYearName !== null && $this->fiscalYearName !== '') {
            $rows[] = ["Fiscal Year: {$this->fiscalYearName}"];
        }

        if ($this->projectLabel !== null && $this->projectLabel !== '') {
            $rows[] = ["Project: {$this->projectLabel}"];
        }

        if ($this->branchLabel !== null) {
            $rows[] = [$this->branchLabel];
        }

        $rows[] = [''];
        $rows[] = ['Particulars', 'Code', 'Amount'];

        return $rows;
    }

    /**
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        return [$this->statement === null ? 'D' : 'C' => NumberFormat::FORMAT_NUMBER_00];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<int, string|float>
     */
    public function map($row): array
    {
        if ($this->statement === null) {
            return [$row['section'], $row['code'], $row['name'], Money::of($row['amount'])->toFloat()];
        }

        return [
            $row['name'],
            $row['code'],
            $row['amount'] === null ? '' : (($row['text'] ?? false) ? $row['amount'] : Money::of($row['amount'])->toFloat()),
        ];
    }

    /**
     * @return array<int|string, array<string, mixed>>
     */
    public function styles(Worksheet $sheet): array
    {
        if ($this->statement === null) {
            return [];
        }

        $headingRowCount = count($this->headings());

        // Data rows start after every heading row, the shared header included.
        $styles = $this->statementHeaderStyles();
        $styles[$headingRowCount] = ['font' => ['bold' => true]];

        foreach ($this->statementRows()->values() as $index => $row) {
            if ($row['bold']) {
                $styles[$headingRowCount + $index + 1] = ['font' => ['bold' => true]];
            }
        }

        return $styles;
    }

    /**
     * @return Collection<int, array{name: string, code: string, amount: ?string, bold: bool, text?: bool}>
     */
    private function statementRows(): Collection
    {
        $statement = $this->statement ?? [];
        $rows = collect();

        $heading = function (string $name, int $indent = 0) use ($rows): void {
            $rows->push(['name' => str_repeat(' ', $indent).$name, 'code' => '', 'amount' => null, 'bold' => true]);
        };
        $line = function (string $name, string $amount, bool $bold = false, int $indent = 0, string $code = '') use ($rows): void {
            $rows->push(['name' => str_repeat(' ', $indent).$name, 'code' => $code, 'amount' => $amount, 'bold' => $bold]);
        };
        // A group's ledgers and sub-groups, fully expanded. A group or
        // sub-group with a single ledger and nothing below skips its own
        // "<name> Total" row, which would only repeat the ledger's figure
        // (the same rule as the PDF).
        $groups = function (array $nodes) use ($heading, $line): void {
            foreach ($nodes as $group) {
                $heading($group['name'], 2);

                foreach ($group['rows'] as $row) {
                    $line($row['name'], $row['amount'], false, 6, (string) ($row['code'] ?? ''));
                }

                foreach ($group['subgroups'] as $subgroup) {
                    $heading($subgroup['name'], 4);

                    foreach ($subgroup['rows'] as $row) {
                        $line($row['name'], $row['amount'], false, 8, (string) ($row['code'] ?? ''));
                    }

                    if (count($subgroup['rows']) > 1) {
                        $line($subgroup['name'].' Total', $subgroup['total'], true, 4);
                    }
                }

                if (count($group['rows']) + count($group['subgroups']) > 1) {
                    $line($group['name'].' Total', $group['total'], true, 2);
                }
            }
        };
        $deduct = fn (string $amount): string => Money::of($amount)->negated()->toString();
        // A loss is text in parentheses, a profit a plain number.
        $profitOrLoss = function (string $amount, string $label) use ($rows): void {
            $value = Money::of($amount);

            $rows->push($value->isNegative()
                ? ['name' => $label, 'code' => '', 'amount' => '('.$value->abs()->format().')', 'bold' => true, 'text' => true]
                : ['name' => $label, 'code' => '', 'amount' => $amount, 'bold' => true]);
        };

        $revenue = $statement['revenue'];
        $cost = $statement['costOfSales'];

        $heading('Revenue from operations');
        $groups($revenue['salesGroups']);
        $line('Sales', $revenue['sales'], true);
        $groups($revenue['salesReturnGroups']);
        $line('Less: Sales Return', $deduct($revenue['salesReturn']), true);
        $line('Net Revenue from operations', $revenue['net'], true);

        $heading('Cost of goods sold / direct cost');
        $line('Opening Stock', $cost['openingStock']['amount'], false, 0, (string) ($cost['openingStock']['code'] ?? ''));
        $groups($cost['purchaseGroups']);
        $line('Add: Purchases', $cost['purchases'], true);
        $groups($cost['purchaseReturnGroups']);
        $line('Less: Purchase Return', $deduct($cost['purchaseReturn']), true);

        if (count($cost['directExpenseGroups']) > 0) {
            $groups($cost['directExpenseGroups']);
            $line('Add: Direct Expenses', $cost['directExpenses'], true);
        }

        $line('Less: Closing Stock', $deduct($cost['closingStock']['amount']), false, 0, (string) ($cost['closingStock']['code'] ?? ''));
        $line('Total Cost of Sales', $cost['total'], true);

        $profitOrLoss($statement['grossProfit'], 'Gross Profit / (Loss)');

        $heading('Add: Other Business Income');
        $groups($statement['otherIncome']['groups']);
        $line('Total Other Business Income', $statement['otherIncome']['total'], true);

        $heading('Less: Expenses');
        $groups($statement['expenses']['groups']);
        $line('Total Expenses', $statement['expenses']['total'], true);

        $profitOrLoss($statement['netProfit'], 'Net Profit / (Loss)');

        return $rows;
    }
}
