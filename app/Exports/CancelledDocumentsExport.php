<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * Every cancelled document across every module in one sheet (T14 task 4),
 * mirroring AccountingReportController::cancelledDocuments()'s Inertia rows
 * exactly - no money column here (a cancellation report is an audit trail,
 * not a financial total, and mixing document types with different meanings
 * of "amount" would be misleading to sum).
 *
 * $showBranch (plan 12, BR22) adds a Branch column after Number, only for a
 * multi-branch company viewed as "All branches"
 * (BranchListFilter::columnVisible()); otherwise the sheet is unchanged.
 */
class CancelledDocumentsExport implements FromCollection, WithHeadings, WithMapping
{
    /**
     * @param  array<int, array{type: string, number: string, date: string, cancelledAt: ?string, cancelledBy: ?string, reason: ?string, branch?: ?array{id: int, name: string}}>  $rows
     */
    public function __construct(private readonly array $rows, private readonly bool $showBranch = false) {}

    public function collection(): Collection
    {
        return collect($this->rows);
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return [
            'Type',
            'Number',
            ...($this->showBranch ? ['Branch'] : []),
            'Date',
            'Cancelled At',
            'Cancelled By',
            'Reason',
        ];
    }

    /**
     * @param  array{type: string, number: string, date: string, cancelledAt: ?string, cancelledBy: ?string, reason: ?string, branch?: ?array{id: int, name: string}}  $row
     * @return array<int, string>
     */
    public function map($row): array
    {
        return [
            $row['type'],
            $row['number'],
            ...($this->showBranch ? [$row['branch']['name'] ?? ''] : []),
            $row['date'],
            $row['cancelledAt'] ?? '',
            $row['cancelledBy'] ?? '',
            $row['reason'] ?? '',
        ];
    }
}
