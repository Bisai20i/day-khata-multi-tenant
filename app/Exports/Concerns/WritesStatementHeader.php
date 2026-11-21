<?php

namespace App\Exports\Concerns;

use App\Models\CompanySetting;
use App\Support\NepaliCalendar;

/**
 * Leading heading rows shared by the financial statement exports (trial
 * balance, income statement, balance sheet).
 *
 * The trait changes nothing by itself: an export opts in by calling
 * statementHeaderRows() from its own headings() (or from the start of its
 * collection()) and by offsetting every row index it computes by
 * STATEMENT_HEADER_ROW_COUNT.
 *
 * How to call it
 * --------------
 *   use WritesStatementHeader;
 *
 *   public function headings(): array
 *   {
 *       return [
 *           ...$this->statementHeaderRows('Trial Balance', $this->from, $this->to),
 *           // the export's own rows follow (fiscal year, filters, column headings)
 *       ];
 *   }
 *
 * Rows added: always exactly STATEMENT_HEADER_ROW_COUNT (4), each a one-cell
 * row, so a caller can offset merged-cell and style row indexes without
 * counting branches:
 *   1  company name
 *   2  "PAN/VAT: <number>" (or "PAN/VAT: -" when the company has none)
 *   3  report title
 *   4  "Period: <BS from> to <BS to> (BS) | <AD from> to <AD to> (AD)"
 *      (an empty string when from or to is missing)
 *
 * Because the export's old title row (row 1) is replaced by these rows, an
 * export that previously styled row 1 as its title should instead use
 * statementHeaderStyles() and drop its own title row.
 *
 * Company name and PAN come from CompanySetting::current(), the same record
 * pdf/layout.blade.php prints (company_name and pan_vat_number). Pass a
 * resolved CompanySetting as $company to skip the lookup.
 */
trait WritesStatementHeader
{
    /** Number of rows statementHeaderRows() always returns. */
    public const STATEMENT_HEADER_ROW_COUNT = 4;

    /**
     * @param  string  $title  Report title, e.g. "Trial Balance"
     * @param  string|null  $from  Period start (AD, Y-m-d)
     * @param  string|null  $to  Period end (AD, Y-m-d)
     * @return list<list<string>> exactly STATEMENT_HEADER_ROW_COUNT one-cell rows
     */
    protected function statementHeaderRows(string $title, ?string $from, ?string $to, ?CompanySetting $company = null): array
    {
        $company ??= CompanySetting::current();

        $pan = trim((string) $company->pan_vat_number);

        return [
            [(string) $company->company_name],
            ['PAN/VAT: '.($pan !== '' ? $pan : '-')],
            [$title],
            [$this->statementPeriodLine($from, $to)],
        ];
    }

    /**
     * Period line showing both calendars. Empty when either date is missing.
     */
    protected function statementPeriodLine(?string $from, ?string $to): string
    {
        if ($from === null || $from === '' || $to === null || $to === '') {
            return '';
        }

        $bsFrom = NepaliCalendar::formatBs($from);
        $bsTo = NepaliCalendar::formatBs($to);

        $bs = $bsFrom !== '' && $bsTo !== '' ? "{$bsFrom} to {$bsTo} (BS) | " : '';

        return "Period: {$bs}{$from} to {$to} (AD)";
    }

    /**
     * Row styles for the header rows, to merge into the export's styles()
     * result. Company name large and bold, title bold.
     *
     * @param  int  $offset  Rows that precede the header (normally 0)
     * @return array<int, array<string, mixed>>
     */
    protected function statementHeaderStyles(int $offset = 0): array
    {
        return [
            $offset + 1 => ['font' => ['bold' => true, 'size' => 14]],
            $offset + 3 => ['font' => ['bold' => true, 'size' => 12]],
        ];
    }
}
