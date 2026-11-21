<?php

namespace App\Exports;

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
 * One account's Ledger, mirroring the on-screen table and the PDF 1:1:
 * an "Opening Balance" row first, one row per ledger line, a "Total" row
 * (period debit / credit) and a "Closing Balance" row. Each ledger row carries
 * its Particulars (the other side of the voucher). When the entries carry no
 * `particulars` key at all (the party ledger of a user without the general
 * ledger permission) the column is dropped, as on screen and in the PDF.
 *
 * Balances are written as the ABSOLUTE amount with the Dr/Cr side in its own
 * column (zero -> 0.00 with no side), so Debit / Credit / Balance stay
 * summable numbers. Money::toFloat() is used only here, at the Excel cell
 * boundary (CONTRACTS C1), never for arithmetic. Cash Book / Bank Book keep
 * using AccountBookExport.
 */
class LedgerExport implements FromCollection, ShouldAutoSize, WithColumnFormatting, WithHeadings, WithMapping, WithStrictNullComparison, WithStyles
{
    /**
     * @param  array<int, array<string, mixed>>  $entries  LedgerBook entries: date, dateBs, voucherType, voucherNumber, reference, particulars, narration, mainNarration, debit, credit, balance, side
     * @param  string  $openingBalance  Signed Money string (the absolute amount is written)
     * @param  string  $closingBalance  Signed Money string (the absolute amount is written)
     * @param  string|null  $branchLabel  BranchFilter::label() in a multi-branch tenant, else null (no heading row)
     * @param  string|null  $accountLabel  "CODE - Name" of the account, else null (no heading row)
     * @param  string|null  $periodLabel  Printable period, else null (no heading row)
     * @param  string|null  $projectLabel  "CODE - Name" of the project filter, else null (no heading row)
     * @param  array{rows: list<array<string, mixed>>, total: array<string, mixed>|null}  $subLedgerSummary  SubLedgerSummary::build() result; an empty `rows` writes no block
     */
    public function __construct(
        private readonly array $entries,
        private readonly string $openingBalance,
        private readonly string $openingSide,
        private readonly string $closingBalance,
        private readonly string $closingSide,
        private readonly string $periodDebit,
        private readonly string $periodCredit,
        private readonly ?string $branchLabel = null,
        private readonly ?string $accountLabel = null,
        private readonly ?string $periodLabel = null,
        private readonly ?string $projectLabel = null,
        private readonly array $subLedgerSummary = ['rows' => [], 'total' => null],
    ) {}

    public function collection(): Collection
    {
        $rows = collect();

        $rows->push($this->row(narration: 'Opening Balance', balance: $this->openingBalance, side: $this->openingSide, bold: true));

        foreach ($this->entries as $entry) {
            $rows->push($this->row(
                dateBs: (string) ($entry['dateBs'] ?? ''),
                dateAd: (string) ($entry['date'] ?? ''),
                voucher: strtoupper(str_replace('_', ' ', (string) $entry['voucherType'])).' #'.$entry['voucherNumber'],
                reference: (string) ($entry['reference'] ?? ($entry['document']['label'] ?? '')),
                particulars: (string) ($entry['particulars'] ?? ''),
                narration: (string) ($entry['narration'] ?? ''),
                mainNarration: (string) ($entry['mainNarration'] ?? ''),
                debit: (string) $entry['debit'],
                credit: (string) $entry['credit'],
                balance: (string) $entry['balance'],
                side: (string) ($entry['side'] ?? ''),
            ));
        }

        $rows->push($this->row(narration: 'Total', debit: $this->periodDebit, credit: $this->periodCredit, bold: true));
        $rows->push($this->row(narration: 'Closing Balance', balance: $this->closingBalance, side: $this->closingSide, bold: true));

        foreach ($this->subLedgerRows() as $row) {
            $rows->push($row);
        }

        return $rows;
    }

    /**
     * The sub-ledger summary block under the ledger, as the page and the PDF
     * show it: a blank row, the "Sub-ledger summary" title, a header row, one
     * row per sub-ledger and the Total row. The name sits in the Particulars
     * column, Opening / Opening Dr/Cr in F / G, Debit / Credit / Closing in
     * the ledger's own H / I / J and the Closing side in K. Empty when there
     * is nothing to summarise.
     *
     * @return list<array<string, mixed>>
     */
    private function subLedgerRows(): array
    {
        $summaryRows = $this->subLedgerSummary['rows'] ?? [];

        // The block's columns line up under the full eleven-column ledger.
        if ($summaryRows === [] || ! $this->showsParticulars()) {
            return [];
        }

        $rows = [
            $this->rawRow(array_fill(0, 11, '')),
            $this->rawRow(['', '', '', '', 'Sub-ledger summary', '', '', '', '', '', ''], bold: true),
            $this->rawRow(['', '', '', '', 'Sub-ledger', 'Opening', 'Opening Dr/Cr', 'Debit', 'Credit', 'Closing', 'Closing Dr/Cr'], bold: true),
        ];

        foreach ($summaryRows as $summaryRow) {
            $name = (string) $summaryRow['name'].(! empty($summaryRow['code']) ? ' ('.$summaryRow['code'].')' : '');
            $rows[] = $this->subLedgerCells($name, $summaryRow);
        }

        $total = $this->subLedgerSummary['total'] ?? null;

        if (! empty($total)) {
            $rows[] = $this->subLedgerCells('Total', $total, bold: true);
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $summaryRow  SubLedgerSummary row
     * @return array<string, mixed>
     */
    private function subLedgerCells(string $name, array $summaryRow, bool $bold = false): array
    {
        return $this->rawRow([
            '',
            '',
            '',
            '',
            $name,
            Money::of((string) $summaryRow['openingBalance'])->abs()->toFloat(),
            (string) ($summaryRow['openingSide'] ?? ''),
            $this->amountCell((string) $summaryRow['debit'], $bold),
            $this->amountCell((string) $summaryRow['credit'], $bold),
            Money::of((string) $summaryRow['closingBalance'])->abs()->toFloat(),
            (string) ($summaryRow['closingSide'] ?? ''),
        ], bold: $bold, openingFormat: true);
    }

    /**
     * A row whose eleven cells are already final (sub-ledger block), so map()
     * writes them as given.
     *
     * @param  list<string|float>  $cells
     * @return array<string, mixed>
     */
    private function rawRow(array $cells, bool $bold = false, bool $openingFormat = false): array
    {
        return ['cells' => $cells, 'bold' => $bold, 'openingFormat' => $openingFormat];
    }

    /**
     * @return list<list<string>>
     */
    public function headings(): array
    {
        $rows = [['Ledger']];

        if ($this->accountLabel !== null && $this->accountLabel !== '') {
            $rows[] = ["Account: {$this->accountLabel}"];
        }

        if ($this->periodLabel !== null && $this->periodLabel !== '') {
            $rows[] = ["Period: {$this->periodLabel}"];
        }

        if ($this->projectLabel !== null && $this->projectLabel !== '') {
            $rows[] = ["Project: {$this->projectLabel}"];
        }

        if ($this->branchLabel !== null && $this->branchLabel !== '') {
            $rows[] = [$this->branchLabel];
        }

        $rows[] = [''];
        $rows[] = $this->withoutParticularsCell(['Date (BS)', 'Date (AD)', 'Voucher', 'Reference', 'Particulars', 'Narration', 'Main Narration', 'Debit', 'Credit', 'Balance', 'Dr/Cr']);

        return $rows;
    }

    /**
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        return array_fill_keys($this->showsParticulars() ? ['H', 'I', 'J'] : ['G', 'H', 'I'], NumberFormat::FORMAT_NUMBER_00);
    }

    /**
     * False when the entries carry no `particulars` key (the column is then
     * left out of the headings, the cells and the number formats).
     */
    private function showsParticulars(): bool
    {
        return $this->entries === [] || array_key_exists('particulars', $this->entries[array_key_first($this->entries)]);
    }

    /**
     * Drops the Particulars cell (the fifth) when the column is hidden.
     *
     * @param  array<int, string|float>  $cells
     * @return array<int, string|float>
     */
    private function withoutParticularsCell(array $cells): array
    {
        if ($this->showsParticulars()) {
            return $cells;
        }

        unset($cells[4]);

        return array_values($cells);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<int, string|float>
     */
    public function map($row): array
    {
        if (isset($row['cells'])) {
            return $row['cells'];
        }

        return $this->withoutParticularsCell([
            $row['dateBs'],
            $row['dateAd'],
            $row['voucher'],
            $row['reference'],
            $row['particulars'],
            $row['narration'],
            $row['mainNarration'],
            $this->amountCell($row['debit'], $row['bold']),
            $this->amountCell($row['credit'], $row['bold']),
            $row['balance'] === null ? '' : Money::of($row['balance'])->abs()->toFloat(),
            $row['side'],
        ]);
    }

    /**
     * @return array<int|string, array<string, mixed>>
     */
    public function styles(Worksheet $sheet): array
    {
        $headingRowCount = count($this->headings());

        $styles = [
            1 => ['font' => ['bold' => true, 'size' => 13]],
            $headingRowCount => ['font' => ['bold' => true]],
        ];

        foreach ($this->collection()->values() as $index => $row) {
            $excelRow = $headingRowCount + $index + 1;

            if ($row['bold']) {
                $styles[$excelRow] = ['font' => ['bold' => true]];
            }

            // Opening of the sub-ledger block sits in F, which is not a number column of the ledger.
            if ($row['openingFormat'] ?? false) {
                $styles["F{$excelRow}"] = ['numberFormat' => ['formatCode' => NumberFormat::FORMAT_NUMBER_00]];
            }
        }

        return $styles;
    }

    /**
     * A zero debit/credit on a ledger line is an empty cell, never a printed
     * 0.00; the Total row ($keepZero) always shows its figures.
     */
    private function amountCell(?string $amount, bool $keepZero = false): string|float
    {
        if ($amount === null || $amount === '') {
            return '';
        }

        $money = Money::of($amount);

        return $money->isZero() && ! $keepZero ? '' : $money->toFloat();
    }

    /**
     * @return array<string, string|bool|null>
     */
    private function row(
        string $dateBs = '',
        string $dateAd = '',
        string $voucher = '',
        string $reference = '',
        string $particulars = '',
        string $narration = '',
        string $mainNarration = '',
        ?string $debit = null,
        ?string $credit = null,
        ?string $balance = null,
        string $side = '',
        bool $bold = false,
    ): array {
        return [
            'dateBs' => $dateBs,
            'dateAd' => $dateAd,
            'voucher' => $voucher,
            'reference' => $reference,
            'particulars' => $particulars,
            'narration' => $narration,
            'mainNarration' => $mainNarration,
            'debit' => $debit,
            'credit' => $credit,
            'balance' => $balance,
            'side' => $side,
            'bold' => $bold,
        ];
    }
}
