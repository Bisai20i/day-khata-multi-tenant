<?php

namespace App\Http\Controllers\Tenant\Reports;

use App\Http\Controllers\Controller;
use App\Models\PrintLog;
use App\Support\NepaliCalendar;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Read-only view over the `print_logs` rows written by `PrintLog::record()`
 * (contract C9). Admin-only, per routes/tenant-reports-print-log.php.
 *
 * The audit point of this report is reprints: a document printed once is
 * routine, the same invoice printed a fourth time by a fourth user is the
 * thing an owner or an auditor wants to see. So the default ordering is
 * newest first and the copy number is a first-class column.
 */
class PrintLogReportController extends Controller
{
    /**
     * Column names that hold a document's own printed number, most specific
     * first. Read through `getAttribute()` so a column a sibling module has
     * not migrated in yet simply reads as null instead of erroring - see
     * contract C7, where `invoice_number`, `credit_note_number` and
     * `debit_note_number` are added by T04, T05 and T06.
     *
     * @var list<string>
     */
    private const NUMBER_ATTRIBUTES = [
        'invoice_number',
        'credit_note_number',
        'debit_note_number',
        'bill_number',
        'reference_number',
    ];

    public function index(Request $request): Response
    {
        $from = $request->string('from')->toString() ?: null;
        $to = $request->string('to')->toString() ?: null;
        $printableType = $request->string('printable_type')->toString() ?: null;
        $copiesOnly = $request->boolean('copies_only');

        $logs = PrintLog::query()
            ->with(['printable', 'printedBy:id,name'])
            ->when($printableType, fn ($query) => $query->where('printable_type', $printableType))
            ->when($from, fn ($query) => $query->whereDate('printed_at', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('printed_at', '<=', $to))
            ->when($copiesOnly, fn ($query) => $query->where('copy_number', '>', 1))
            ->orderByDesc('printed_at')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (PrintLog $log) => [
                'id' => $log->id,
                'document_type' => $this->documentTypeLabel($log->printable_type),
                'document_number' => $this->documentNumber($log),
                'copy_number' => $log->copy_number,
                'is_copy' => $log->copy_number > 1,
                'printed_by' => $log->printedBy?->name,
                'printed_at_ad' => $log->printed_at?->format('Y-m-d H:i'),
                'printed_at_bs' => NepaliCalendar::formatBs($log->printed_at?->toDateString()),
            ]);

        return Inertia::render('Tenant/Reports/PrintLog', [
            'logs' => $logs,
            'documentTypes' => $this->documentTypeOptions(),
            'filters' => [
                'from' => $from,
                'to' => $to,
                'printable_type' => $printableType,
                'copies_only' => $copiesOnly,
            ],
        ]);
    }

    /**
     * The document types that actually appear in this tenant's log, read
     * from the table itself rather than from a hand-kept list of models.
     * Modules are being added in parallel, so a list maintained here would
     * be out of date the week it was written.
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function documentTypeOptions(): array
    {
        return PrintLog::query()
            ->select('printable_type')
            ->distinct()
            ->orderBy('printable_type')
            ->pluck('printable_type')
            ->map(fn (string $type) => ['value' => $type, 'label' => $this->documentTypeLabel($type)])
            ->values()
            ->all();
    }

    /**
     * "App\Models\SalesReturn" reads as "Sales Return".
     */
    private function documentTypeLabel(string $printableType): string
    {
        $basename = str_contains($printableType, '\\')
            ? substr($printableType, strrpos($printableType, '\\') + 1)
            : $printableType;

        return trim(preg_replace('/(?<!^)([A-Z])/', ' $1', $basename) ?? $basename);
    }

    /**
     * The number printed on the document itself, falling back to "#{id}" for
     * a document type that has no stored number (and for rows whose document
     * has since been deleted, where `printable` resolves to null).
     */
    private function documentNumber(PrintLog $log): string
    {
        $document = $log->printable;

        if (! $document instanceof Model) {
            return '#'.$log->printable_id;
        }

        foreach (self::NUMBER_ATTRIBUTES as $attribute) {
            $value = $document->getAttribute($attribute);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return '#'.$document->getKey();
    }
}
