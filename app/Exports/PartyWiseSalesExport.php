<?php

namespace App\Exports;

use App\Support\Money\Money;
use App\Support\NepaliCalendar;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Mirrors PartyWiseSalesReportController::report() exactly: with no party
 * selected, one line per customer and a Total line; with `detail` set (the
 * drill-down), that customer's invoices, its credit notes and the receipt /
 * earlier-outstanding lines that tie the documents back to the customer row.
 *
 * Every amount is a real number cell with a 2-decimal format. Money::toFloat()
 * is used at exactly that boundary (C1): the values are the controller's
 * final strings, no arithmetic happens on a float.
 *
 * PartyWisePurchaseExport reuses this layout with the purchase-side labels.
 */
class PartyWiseSalesExport implements FromCollection, ShouldAutoSize, WithColumnFormatting, WithHeadings, WithStrictNullComparison, WithStyles
{
    /** Party summary amount columns, in order. */
    private const ROW_AMOUNTS = ['taxable_amount', 'nontaxable_amount', 'vat_amount', 'total'];

    private const ROW_TAIL_AMOUNTS = ['returns_total', 'net_total', 'tds_amount', 'received', 'outstanding'];

    /** Drill-down amount columns, in order. */
    private const DOCUMENT_AMOUNTS = ['taxable_amount', 'nontaxable_amount', 'vat_amount', 'total', 'tds_amount', 'received_at_billing', 'outstanding'];

    /** @var array{lines: list<list<string|int|float>>, bold: list<int>, merged: array<int, int>}|null */
    private ?array $layout = null;

    /**
     * @param  array<string, mixed>  $report  The controller's report() payload
     * @param  string|null  $branchLabel  BranchFilter::label() in a multi-branch tenant, else null
     */
    public function __construct(
        protected readonly array $report,
        protected readonly ?string $branchLabel = null,
    ) {}

    /**
     * @return Collection<int, list<string|int|float>>
     */
    public function collection(): Collection
    {
        return collect($this->layout()['lines']);
    }

    /**
     * @return list<list<string>>
     */
    public function headings(): array
    {
        $labels = $this->labels();
        $detail = $this->report['detail'] ?? null;
        $title = $detail ? "{$labels['title']}: {$detail['party']['name']}" : $labels['title'];

        $rows = [[$title], [$this->periodLabel()]];

        if ($detail && ! empty($detail['party']['pan'])) {
            $rows[] = ["PAN: {$detail['party']['pan']}"];
        }

        if ($this->branchLabel !== null) {
            $rows[] = [$this->branchLabel];
        }

        $rows[] = [''];
        $rows[] = $detail ? $this->documentHeadings() : $this->partyHeadings();

        return $rows;
    }

    /**
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        $formats = [];

        foreach ($this->amountColumnIndexes() as $index) {
            $formats[Coordinate::stringFromColumnIndex($index)] = NumberFormat::FORMAT_NUMBER_00;
        }

        return $formats;
    }

    /**
     * @return array<int|string, array<string, mixed>>
     */
    public function styles(Worksheet $sheet): array
    {
        $headingCount = count($this->headings());
        $lastColumn = Coordinate::stringFromColumnIndex(count($this->headings()[$headingCount - 1]));

        // Long preamble text lives in merged cells so ShouldAutoSize does not widen column A.
        foreach (range(1, $headingCount - 2) as $row) {
            $sheet->mergeCells("A{$row}:{$lastColumn}{$row}");
        }

        $styles = [
            1 => ['font' => ['bold' => true, 'size' => 13]],
            $headingCount => ['font' => ['bold' => true]],
        ];

        foreach ($this->layout()['bold'] as $line) {
            $styles[$headingCount + $line] = ['font' => ['bold' => true]];
        }

        // Long drill-down labels are merged across the columns beside them, so
        // they never widen the SN column either.
        foreach ($this->layout()['merged'] as $line => $toColumn) {
            $row = $headingCount + $line;
            $sheet->mergeCells("A{$row}:".Coordinate::stringFromColumnIndex($toColumn).$row);
        }

        return $styles;
    }

    /**
     * Words that differ between the sales and purchase sides.
     *
     * @return array{title: string, party: string, documents: string, number: string, reference: string, returns_count: string, returns: string, received: string, receipts: string, against: string}
     */
    protected function labels(): array
    {
        return [
            'title' => 'Customer-wise Sales',
            'party' => 'Customer',
            'documents' => 'Invoices',
            'number' => 'Invoice No',
            'reference' => 'Buyer',
            'returns_count' => 'Credit Notes',
            'returns' => 'Returns',
            'received' => 'Received',
            'receipts' => 'Receipts in the period',
            'against' => 'Against Invoice',
        ];
    }

    /**
     * The drill-down's "reference" cell: the buyer name printed on the sale.
     *
     * @param  array<string, mixed>  $document
     */
    protected function documentReference(array $document): string
    {
        return (string) ($document['buyer_name'] ?? '');
    }

    private function hasBranchColumn(): bool
    {
        return $this->branchLabel !== null;
    }

    private function periodLabel(): string
    {
        $from = $this->report['from'];
        $to = $this->report['to'];

        return 'Period: BS '.NepaliCalendar::formatBs($from).' to '.NepaliCalendar::formatBs($to)." (AD {$from} to {$to})";
    }

    /**
     * @return list<string>
     */
    private function partyHeadings(): array
    {
        $labels = $this->labels();

        return [
            'SN', $labels['party'], 'PAN', $labels['documents'], 'Taxable', 'Exempt', 'VAT', 'Total',
            $labels['returns_count'], $labels['returns'], 'Net', 'TDS', $labels['received'], 'Outstanding',
        ];
    }

    /**
     * @return list<string>
     */
    private function documentHeadings(): array
    {
        $labels = $this->labels();

        return [
            'SN', 'Date (BS)', 'Date (AD)', $labels['number'], $labels['reference'], 'Payment Mode', 'Status',
            ...($this->hasBranchColumn() ? ['Branch'] : []),
            'Taxable', 'Exempt', 'VAT', 'Total', 'TDS', $labels['received'].' at Billing', 'Outstanding',
        ];
    }

    /**
     * 1-based indexes of every money column for the active layout.
     *
     * @return list<int>
     */
    private function amountColumnIndexes(): array
    {
        if (($this->report['detail'] ?? null) === null) {
            return [5, 6, 7, 8, 10, 11, 12, 13, 14];
        }

        $first = $this->documentLeadingCount() + 1;

        return range($first, $first + count(self::DOCUMENT_AMOUNTS) - 1);
    }

    /** Drill-down columns before the first amount: SN..Status, plus Branch. */
    private function documentLeadingCount(): int
    {
        return $this->hasBranchColumn() ? 8 : 7;
    }

    /**
     * Builds the data lines once, with the 1-based data-line numbers that
     * must be bold (totals and section headings).
     *
     * @return array{lines: list<list<string|int|float>>, bold: list<int>, merged: array<int, int>}
     */
    private function layout(): array
    {
        if ($this->layout !== null) {
            return $this->layout;
        }

        $detail = $this->report['detail'] ?? null;

        return $this->layout = $detail ? $this->detailLayout($detail) : $this->partyLayout();
    }

    /**
     * @return array{lines: list<list<string|int|float>>, bold: list<int>, merged: array<int, int>}
     */
    private function partyLayout(): array
    {
        $lines = [];

        foreach ($this->report['rows'] as $row) {
            $lines[] = $this->partyLine((string) $row['sn'], $row['party'], (string) ($row['pan'] ?? ''), $row);
        }

        $lines[] = $this->partyLine('', 'Total', '', $this->report['totals']);

        return ['lines' => $lines, 'bold' => [count($lines)], 'merged' => []];
    }

    /**
     * @param  array<string, mixed>  $source
     * @return list<string|int|float>
     */
    private function partyLine(string $sn, string $party, string $pan, array $source): array
    {
        return [
            $sn, $party, $pan, (int) ($source['documents'] ?? 0),
            ...$this->money($source, self::ROW_AMOUNTS),
            (int) ($source['returns_count'] ?? 0),
            ...$this->money($source, self::ROW_TAIL_AMOUNTS),
        ];
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array{lines: list<list<string|int|float>>, bold: list<int>, merged: array<int, int>}
     */
    private function detailLayout(array $detail): array
    {
        $labels = $this->labels();
        $leading = $this->documentLeadingCount();
        $width = $leading + count(self::DOCUMENT_AMOUNTS);
        $lines = [];
        $bold = [];
        $merged = [];

        foreach ($detail['documents'] as $document) {
            $lines[] = [
                $document['sn'],
                NepaliCalendar::formatBs($document['date']),
                $document['date'],
                (string) ($document['number'] ?? ''),
                $this->documentReference($document),
                ucfirst((string) $document['payment_mode']),
                $this->statusLabel($document['payment_status']),
                ...($this->hasBranchColumn() ? [(string) ($document['branch'] ?? '')] : []),
                ...$this->money($document, self::DOCUMENT_AMOUNTS),
            ];
        }

        $lines[] = ['Total', ...array_fill(0, $leading - 1, ''), ...$this->money($detail['documentTotals'], self::DOCUMENT_AMOUNTS)];
        $bold[] = count($lines);

        $lines[] = array_fill(0, $width, '');
        $lines[] = [$labels['returns_count']];
        $bold[] = count($lines);
        $merged[count($lines)] = $leading;
        $lines[] = [
            'SN', 'Date (BS)', 'Date (AD)', 'Number', $labels['against'], '', '',
            ...($this->hasBranchColumn() ? ['Branch'] : []),
            '', '', '', 'Total', 'TDS', '', '',
        ];
        $bold[] = count($lines);

        foreach ($detail['returns'] as $return) {
            $lines[] = [
                $return['sn'],
                NepaliCalendar::formatBs($return['date']),
                $return['date'],
                (string) ($return['number'] ?? ''),
                (string) ($return['against'] ?? ''),
                '', '',
                ...($this->hasBranchColumn() ? [(string) ($return['branch'] ?? '')] : []),
                ...$this->returnAmounts($return),
            ];
        }

        $lines[] = ['Total', ...array_fill(0, $leading - 1, ''), ...$this->returnAmounts($detail['returnTotals'])];
        $bold[] = count($lines);

        $lines[] = array_fill(0, $width, '');

        $receipts = $detail['receipts'];
        $receiptLine = array_fill(0, $width, '');
        $receiptLine[0] = "{$labels['receipts']} ({$receipts['count']}), separate from {$labels['received']} at Billing; together they make the row's {$labels['received']}";
        $receiptLine[$leading + 5] = Money::of($receipts['amount'])->toFloat();
        $lines[] = $receiptLine;
        $merged[count($lines)] = $leading + 5;

        $earlierLine = array_fill(0, $width, '');
        $earlierLine[0] = 'Outstanding on documents dated before the period';
        $earlierLine[$leading + 6] = Money::of($detail['earlierOutstanding'])->toFloat();
        $lines[] = $earlierLine;
        $merged[count($lines)] = $leading + 6;

        return ['lines' => $lines, 'bold' => $bold, 'merged' => $merged];
    }

    /**
     * A credit / debit note's Total and TDS, placed under the document
     * table's Total and TDS columns.
     *
     * @param  array<string, mixed>  $source
     * @return list<string|float>
     */
    private function returnAmounts(array $source): array
    {
        return [
            '', '', '',
            Money::of($source['total'] ?? '0')->toFloat(),
            Money::of($source['tds_amount'] ?? '0')->toFloat(),
            '', '',
        ];
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'paid' => 'Paid',
            'partial' => 'Part paid',
            'unpaid' => 'Unpaid',
            'credit' => 'Overpaid',
            default => $status,
        };
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  list<string>  $keys
     * @return list<float>
     */
    private function money(array $source, array $keys): array
    {
        return array_map(fn (string $key): float => Money::of($source[$key] ?? '0')->toFloat(), $keys);
    }
}
