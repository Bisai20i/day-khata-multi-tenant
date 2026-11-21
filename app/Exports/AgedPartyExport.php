<?php

namespace App\Exports;

use App\Support\Money\Money;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

/**
 * The aged receivables / payables report as a real .xlsx: one line per party
 * with its ledger balance as of the date aged into buckets (0-30, 31-60,
 * 61-90, 90+) plus the still-open Opening Balance remainder, flat or grouped
 * by area, closing with a Total line and an Advances line (parties in credit,
 * kept out of the rows and the Total).
 *
 * Amount cells are real numbers with a 2-decimal format; `Money::toFloat()`
 * is used at that boundary and nowhere else (C1). The sheet opens with the
 * report title, an "As of" line, the branch filter's label in a multi-branch
 * tenant, and the party / area filters when set, above the column headings.
 * Column order is unchanged (A:K).
 */
class AgedPartyExport implements FromCollection, WithColumnFormatting, WithHeadings, WithMapping, WithStrictNullComparison
{
    /** The amount columns of a line, in sheet order (F:K). */
    private const AMOUNT_KEYS = ['opening', 'current', 'days31_60', 'days61_90', 'days90Plus', 'total'];

    /**
     * @param  Collection<int, array<string, mixed>>|list<array<string, mixed>>  $rows
     * @param  array<string, string>  $totals  opening, current, days31_60, days61_90, days90Plus, total
     * @param  list<array{area: string, rows: list<array<string, mixed>>, totals: array<string, string>}>|null  $groups  the "Group by area" layout, when it was on
     * @param  string|null  $branchLabel  BranchFilter::label() in a multi-branch tenant, else null
     * @param  string  $advances  sum of the credit balances left out of the rows
     */
    public function __construct(
        private readonly Collection|array $rows,
        private readonly array $totals,
        private readonly ?array $groups,
        private readonly string $partyLabel,
        private readonly string $asOf,
        private readonly ?string $branchLabel = null,
        private readonly string $title = '',
        private readonly ?string $partyName = null,
        private readonly ?string $areaLabel = null,
        private readonly string $advances = '0.00',
    ) {}

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function collection(): Collection
    {
        $lines = collect();

        if ($this->groups === null) {
            foreach ($this->rows as $row) {
                $lines->push($row);
            }
        } else {
            foreach ($this->groups as $group) {
                $lines->push($this->line("Area: {$group['area']}"));

                foreach ($group['rows'] as $row) {
                    $lines->push($row);
                }

                $lines->push($this->line("Subtotal - {$group['area']}", $group['totals'] ?? []));
            }
        }

        $lines->push($this->line('Total', $this->totals));

        return $lines->push($this->line('Advances (credit balances, not in Total)', ['total' => $this->advances], blankOthers: true));
    }

    /**
     * @return list<list<string>>
     */
    public function headings(): array
    {
        $headings = [
            $this->partyLabel,
            'Contact',
            'Area',
            'Oldest unpaid',
            'Days',
            'Opening / unallocated',
            '0-30',
            '31-60',
            '61-90',
            '90+',
            'Total',
        ];

        $lead = [];

        if ($this->title !== '') {
            $lead[] = [$this->title];
        }

        $lead[] = ["As of {$this->asOf}"];

        if ($this->branchLabel !== null) {
            $lead[] = [$this->branchLabel];
        }

        if ($this->partyName !== null) {
            $lead[] = ["{$this->partyLabel}: {$this->partyName}"];
        }

        if ($this->areaLabel !== null) {
            $lead[] = ["Area: {$this->areaLabel}"];
        }

        return [...$lead, $headings];
    }

    /**
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        return [
            'F' => NumberFormat::FORMAT_NUMBER_00,
            'G' => NumberFormat::FORMAT_NUMBER_00,
            'H' => NumberFormat::FORMAT_NUMBER_00,
            'I' => NumberFormat::FORMAT_NUMBER_00,
            'J' => NumberFormat::FORMAT_NUMBER_00,
            'K' => NumberFormat::FORMAT_NUMBER_00,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<int, string|int|float|null>
     */
    public function map($row): array
    {
        $cells = [
            $row['party'] ?? '',
            $row['mobile_no'] ?? '',
            $row['area'] ?? '',
            $row['oldest'] ?? '',
            $row['days'] ?? null,
        ];

        foreach (self::AMOUNT_KEYS as $key) {
            // Heading lines of the grouped layout carry no amounts.
            $cells[] = ($row[$key] ?? null) === null ? null : Money::of($row[$key])->toFloat();
        }

        return $cells;
    }

    /**
     * A label line of the sheet: an "Area: X" heading (no amounts), a
     * Subtotal / Total line carrying the given totals, or (with $blankOthers)
     * a line that fills only the amounts it is given.
     *
     * @param  array<string, string>|null  $totals
     * @return array<string, mixed>
     */
    private function line(string $label, ?array $totals = null, bool $blankOthers = false): array
    {
        $line = [
            'party' => $label,
            'mobile_no' => null,
            'area' => null,
            'oldest' => null,
            'days' => null,
        ];

        foreach (self::AMOUNT_KEYS as $key) {
            $line[$key] = $totals === null ? null : ($totals[$key] ?? ($blankOthers ? null : '0.00'));
        }

        return $line;
    }
}
